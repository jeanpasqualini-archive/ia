<?php

declare(strict_types=1);

namespace Audio;

use FFI;
use Throwable;

/**
 * Plays PCM through SDL2, bound at runtime with FFI — no compiled extension.
 *
 * The choice of SDL is not about SDL. PHP callbacks **cannot be invoked from
 * a foreign thread**, and every audio API that pulls its samples from its own
 * thread — CoreAudio's AudioQueue, PortAudio in callback mode, SDL's own
 * callback mode — would therefore crash the process rather than raise an
 * error. SDL_QueueAudio is the way out: the samples are pushed in and the
 * queue is inspected from the game loop, so no C code ever calls back into
 * PHP.
 *
 * This is also why writing a C extension was rejected. This repository spent
 * its whole recent history escaping ncurses, an abandoned native extension
 * that had pinned it to PHP 7.2 and a dead image; adding a compiled
 * dependency for background music would be that same mistake again. FFI
 * binds at runtime, is skipped entirely when the library is absent, and
 * leaves the game running silently instead of failing to start.
 */
final class SdlAudioOutput implements AudioOutputInterface
{
    private const SDL_INIT_AUDIO = 0x00000010;

    /** Unsigned 8 bit samples, the format the synth produces. */
    private const AUDIO_U8 = 0x0008;

    /**
     * Homebrew does not put its libraries on the default dyld search path, so
     * the absolute path is needed on macOS. The bare soname at the end is for
     * Linux, where the loader will find it on its own.
     *
     * @var list<string>
     */
    private const LIBRARIES = [
        '/opt/homebrew/lib/libSDL2.dylib',
        '/usr/local/lib/libSDL2.dylib',
        'libSDL2-2.0.so.0',
    ];

    /**
     * Only what is actually called. The struct layout is transcribed by hand
     * and has to match the ABI exactly — it comes to 32 bytes on arm64, which
     * the test asserts, because a wrong layout does not raise an error here,
     * it corrupts memory.
     */
    public const HEADER = <<<'C'
        typedef struct {
            int freq;
            uint16_t format;
            uint8_t channels;
            uint8_t silence;
            uint16_t samples;
            uint16_t padding;
            uint32_t size;
            void *callback;
            void *userdata;
        } SDL_AudioSpec;

        int SDL_Init(uint32_t flags);
        void SDL_Quit(void);
        const char *SDL_GetError(void);
        uint32_t SDL_OpenAudioDevice(const char *device, int iscapture, const SDL_AudioSpec *desired, SDL_AudioSpec *obtained, int allowed_changes);
        void SDL_PauseAudioDevice(uint32_t dev, int pause_on);
        void SDL_CloseAudioDevice(uint32_t dev);
        int SDL_QueueAudio(uint32_t dev, const void *data, uint32_t len);
        uint32_t SDL_GetQueuedAudioSize(uint32_t dev);
        void SDL_ClearQueuedAudio(uint32_t dev);
        C;

    private ?FFI $sdl = null;

    private int $device = 0;

    public function open(int $rate): bool
    {
        if (!class_exists(FFI::class)) {
            return false;
        }

        $this->sdl = $this->load();

        if (null === $this->sdl) {
            return false;
        }

        try {
            if (0 !== $this->sdl->SDL_Init(self::SDL_INIT_AUDIO)) {
                $this->sdl = null;

                return false;
            }

            $wanted = $this->sdl->new('SDL_AudioSpec');
            $wanted->freq = $rate;
            $wanted->format = self::AUDIO_U8;
            $wanted->channels = 1;
            // The device buffer, in samples. Small enough that a queued
            // effect is not held back behind a long block already in flight.
            $wanted->samples = 512;

            $obtained = $this->sdl->new('SDL_AudioSpec');

            $this->device = $this->sdl->SDL_OpenAudioDevice(
                null,
                0,
                FFI::addr($wanted),
                FFI::addr($obtained),
                0
            );

            if (0 === $this->device) {
                $this->sdl->SDL_Quit();
                $this->sdl = null;

                return false;
            }

            // Devices open paused.
            $this->sdl->SDL_PauseAudioDevice($this->device, 0);

            return true;
        } catch (Throwable) {
            $this->sdl = null;

            return false;
        }
    }

    public function queue(string $pcm): void
    {
        if (null === $this->sdl || 0 === $this->device || '' === $pcm) {
            return;
        }

        $length = strlen($pcm);

        // SDL copies the samples into its own queue, so this buffer can be
        // released as soon as the call returns — PHP owns it and will.
        $buffer = $this->sdl->new('uint8_t[' . $length . ']');
        FFI::memcpy($buffer, $pcm, $length);

        $this->sdl->SDL_QueueAudio($this->device, $buffer, $length);
    }

    public function queuedBytes(): int
    {
        if (null === $this->sdl || 0 === $this->device) {
            return 0;
        }

        return (int) $this->sdl->SDL_GetQueuedAudioSize($this->device);
    }

    public function close(): void
    {
        if (null === $this->sdl) {
            return;
        }

        if (0 !== $this->device) {
            $this->sdl->SDL_ClearQueuedAudio($this->device);
            $this->sdl->SDL_CloseAudioDevice($this->device);
            $this->device = 0;
        }

        $this->sdl->SDL_Quit();
        $this->sdl = null;
    }

    /**
     * Try each candidate until one binds. A missing library throws, which is
     * the expected outcome inside the container and must stay as quiet as a
     * missing terminal colour.
     */
    private function load(): ?FFI
    {
        $candidates = self::LIBRARIES;

        if (false !== ($override = getenv('CAT_IA_SDL')) && '' !== $override) {
            array_unshift($candidates, $override);
        }

        foreach ($candidates as $library) {
            try {
                return FFI::cdef(self::HEADER, $library);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }
}
