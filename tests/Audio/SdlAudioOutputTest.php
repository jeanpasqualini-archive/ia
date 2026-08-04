<?php

declare(strict_types=1);

namespace Tests\Audio;

use Audio\SdlAudioOutput;
use FFI;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SdlAudioOutputTest extends TestCase
{
    /**
     * The struct is transcribed by hand from SDL's headers, and a wrong
     * layout does not raise anything here — it hands the library a pointer
     * into the wrong bytes, which is a crash somewhere else entirely, or
     * worse, silence. Thirty two bytes is what the fields come to under the
     * usual alignment rules; if that ever stops matching, this is the place
     * that should say so.
     */
    public function testTheAudioSpecMatchesTheLibraryLayout(): void
    {
        $sdl = $this->load();

        self::assertSame(32, FFI::sizeof($sdl->new('SDL_AudioSpec')));
    }

    /**
     * Not a real assertion about sound — a device may be busy or absent on
     * whatever machine this runs on — but opening and closing must never
     * leave an exception escaping into the game loop.
     */
    public function testOpeningAndClosingIsAlwaysSafe(): void
    {
        $output = new SdlAudioOutput();

        try {
            $opened = $output->open(22050);

            self::assertIsBool($opened);

            if ($opened) {
                $output->queue(str_repeat(chr(128), 512));

                self::assertGreaterThanOrEqual(0, $output->queuedBytes());
            }
        } finally {
            $output->close();
        }
    }

    private function load(): FFI
    {
        if (!class_exists(FFI::class)) {
            self::markTestSkipped('FFI absent de cette installation de PHP');
        }

        foreach (['/opt/homebrew/lib/libSDL2.dylib', '/usr/local/lib/libSDL2.dylib', 'libSDL2-2.0.so.0'] as $library) {
            try {
                return FFI::cdef(SdlAudioOutput::HEADER, $library);
            } catch (Throwable) {
                continue;
            }
        }

        // The normal outcome inside the container, where there is no sound
        // card to bind to in the first place.
        self::markTestSkipped('libSDL2 introuvable');
    }
}
