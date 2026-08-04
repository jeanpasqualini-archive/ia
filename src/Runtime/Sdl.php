<?php

declare(strict_types=1);

namespace Runtime;

use FFI;

/**
 * The SDL2 video and event binding, bound at runtime through FFI.
 *
 * **The limit that shaped the sound does not apply here.** Everything in
 * `Audio\` follows from one thing — a PHP callback cannot be invoked from a
 * foreign thread — so every audio API that *pulls* samples from its own thread
 * would crash the process, and `SDL_QueueAudio` was chosen because it pushes.
 * SDL's video side is already a pushed model: the renderer calls
 * `SDL_PollEvent`, the renderer draws, the renderer calls `SDL_RenderPresent`.
 * No C code ever calls back into PHP. What was nearly impossible for the
 * speaker is ordinary for the screen.
 *
 * Bound separately from `SdlAudioOutput` on purpose: the two are optional
 * independently — a machine can have a screen and no sound card, which is
 * exactly what the container is — and `dlopen` hands back the same library
 * either way.
 *
 * Like `SDL_AudioSpec`, the structures below are transcribed by hand and must
 * match the ABI. `SDL_Event` is a union padded to 56 bytes, and only the type
 * and the parts actually read are named: reading a field that is not there is
 * how a union corrupts memory rather than raising anything.
 */
final class Sdl
{
    public const INIT_VIDEO = 0x00000020;
    public const WINDOWPOS_CENTERED = 0x2FFF0000;
    public const WINDOW_SHOWN = 0x00000004;
    public const WINDOW_RESIZABLE = 0x00000020;

    /** Hardware, and synchronised to the display: nothing is gained above it. */
    public const RENDERER = 0x00000002 | 0x00000004;

    /**
     * ARGB8888, streaming because the texture is rewritten every frame. SDL
     * builds this out of a macro, which FFI cannot read, so it is spelled out.
     */
    public const PIXELFORMAT_ARGB8888 = 0x16362004;
    public const TEXTUREACCESS_STREAMING = 1;

    public const QUIT = 0x100;
    public const KEYDOWN = 0x300;
    public const MOUSEBUTTONDOWN = 0x401;
    public const MOUSEBUTTONUP = 0x402;
    public const MOUSEMOTION = 0x400;
    public const MOUSEWHEEL = 0x403;

    /** Scancodes carry this bit once turned into key codes. */
    public const SCANCODE_MASK = 1 << 30;

    /** @var list<string> Where a Homebrew, a Linux or a Windows SDL2 lands. */
    private const LIBRARIES = [
        '/opt/homebrew/lib/libSDL2.dylib',
        '/usr/local/lib/libSDL2.dylib',
        'libSDL2-2.0.so.0',
        'libSDL2.so',
        'SDL2.dll',
    ];

    private const HEADER = <<<'C'
        typedef struct { int x; int y; int w; int h; } SdlRect;

        typedef struct {
            uint32_t type; uint32_t timestamp; uint32_t windowID;
            uint8_t state; uint8_t repeat; uint8_t p2; uint8_t p3;
            int32_t scancode; int32_t sym; uint16_t mod; uint16_t pad; uint32_t unused;
        } SdlKeyEvent;

        typedef struct {
            uint32_t type; uint32_t timestamp; uint32_t windowID; uint32_t which;
            uint32_t state; int32_t x; int32_t y; int32_t xrel; int32_t yrel;
        } SdlMotionEvent;

        typedef struct {
            uint32_t type; uint32_t timestamp; uint32_t windowID; uint32_t which;
            uint8_t button; uint8_t state; uint8_t clicks; uint8_t bpad;
            int32_t x; int32_t y;
        } SdlButtonEvent;

        typedef struct {
            uint32_t type; uint32_t timestamp; uint32_t windowID; uint32_t which;
            int32_t x; int32_t y; uint32_t direction;
        } SdlWheelEvent;

        typedef union {
            uint32_t type;
            SdlKeyEvent key;
            SdlMotionEvent motion;
            SdlButtonEvent button;
            SdlWheelEvent wheel;
            uint8_t padding[56];
        } SdlEvent;

        int SDL_Init(uint32_t flags);
        void SDL_Quit(void);
        const char *SDL_GetError(void);
        void *SDL_CreateWindow(const char *title, int x, int y, int w, int h, uint32_t flags);
        void SDL_DestroyWindow(void *window);
        void SDL_RaiseWindow(void *window);
        void *SDL_CreateRenderer(void *window, int index, uint32_t flags);
        void SDL_DestroyRenderer(void *renderer);
        void *SDL_CreateTexture(void *renderer, uint32_t format, int access, int w, int h);
        void SDL_DestroyTexture(void *texture);
        int SDL_UpdateTexture(void *texture, const SdlRect *rect, const char *pixels, int pitch);
        int SDL_RenderClear(void *renderer);
        int SDL_RenderCopy(void *renderer, void *texture, const SdlRect *src, const SdlRect *dst);
        void SDL_RenderPresent(void *renderer);
        int SDL_PollEvent(SdlEvent *event);
        C;

    private ?FFI $ffi = null;

    /**
     * Bind the library, or answer null where there is none. Absence is a
     * normal outcome — the container has no screen — and it must not be an
     * error any more than a missing sound card is.
     */
    public function open(): ?FFI
    {
        if (null !== $this->ffi) {
            return $this->ffi;
        }

        if (!class_exists(FFI::class)) {
            return null;
        }

        foreach (self::LIBRARIES as $library) {
            try {
                return $this->ffi = FFI::cdef(self::HEADER, $library);
            } catch (FFI\Exception) {
                continue;
            }
        }

        return null;
    }
}
