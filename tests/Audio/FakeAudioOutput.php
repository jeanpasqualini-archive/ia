<?php

declare(strict_types=1);

namespace Tests\Audio;

use Audio\AudioOutputInterface;

/**
 * A sound card that remembers instead of playing, so the feeding logic can
 * be asserted on without a device — and without a test ever making a noise.
 */
final class FakeAudioOutput implements AudioOutputInterface
{
    public bool $available = true;

    public string $written = '';

    public int $queued = 0;

    public bool $closed = false;

    public int $rate = 0;

    public function open(int $rate): bool
    {
        $this->rate = $rate;

        return $this->available;
    }

    public function queue(string $pcm): void
    {
        $this->written .= $pcm;
        $this->queued += strlen($pcm);
    }

    public function queuedBytes(): int
    {
        return $this->queued;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    /** Pretend the speaker got through some of the queue. */
    public function drain(int $bytes): void
    {
        $this->queued = max(0, $this->queued - $bytes);
    }
}
