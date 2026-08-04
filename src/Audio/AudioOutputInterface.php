<?php

declare(strict_types=1);

namespace Audio;

/**
 * A sink for raw PCM. Implementations are swappable the way renderers are:
 * the game holds this interface and never learns whether a sound card was
 * found, which is what keeps it running silently inside a container.
 */
interface AudioOutputInterface
{
    /**
     * Take the device. Returns false when there is nothing to play through,
     * which is a normal outcome and not an error: no library, no device, no
     * permission. The caller is expected to carry on without sound.
     */
    public function open(int $rate): bool;

    /**
     * Hand unsigned 8 bit mono samples to the device. The data is copied, so
     * the caller may reuse its buffer immediately.
     */
    public function queue(string $pcm): void;

    /**
     * Bytes still waiting to be played. This is what lets the caller keep a
     * short lead ahead of the speaker instead of guessing from the clock.
     */
    public function queuedBytes(): int;

    public function close(): void;
}
