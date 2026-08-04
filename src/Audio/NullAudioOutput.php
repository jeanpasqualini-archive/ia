<?php

declare(strict_types=1);

namespace Audio;

/**
 * The silent sink, used wherever there is no sound card to talk to: inside
 * the container, and in the tests, which must never open a real device.
 *
 * open() answers false rather than pretending to work, so the sound board
 * skips generating the music at all instead of synthesizing a few hundred
 * kilobytes nobody will hear.
 */
final class NullAudioOutput implements AudioOutputInterface
{
    public function open(int $rate): bool
    {
        return false;
    }

    public function queue(string $pcm): void
    {
    }

    public function queuedBytes(): int
    {
        return 0;
    }

    public function close(): void
    {
    }
}
