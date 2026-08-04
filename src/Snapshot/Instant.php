<?php

declare(strict_types=1);

namespace Snapshot;

/**
 * A frozen copy of an object graph. Serializing on the way in is what makes
 * the snapshot immune to later mutations of the live world.
 */
class Instant
{
    /**
     * Terrain is extremely repetitive, so the cheapest level already divides
     * the payload by twelve; the expensive ones spend milliseconds per
     * snapshot to gain a few percent on a ring that is already small.
     */
    private const COMPRESSION = 1;

    private string $data;

    private bool $compressed;

    public function __construct(object $instance)
    {
        $plain = serialize($instance);
        $packed = gzcompress($plain, self::COMPRESSION);

        // zlib is compiled into every PHP this runs on, host and container
        // alike, but a snapshot that cannot be taken is worse than a large
        // one.
        $this->compressed = false !== $packed;
        $this->data = false === $packed ? $plain : $packed;
    }

    public function getData(): mixed
    {
        $plain = $this->compressed ? gzuncompress($this->data) : $this->data;

        return false === $plain ? null : unserialize($plain);
    }

    /**
     * Size of the frozen payload, in bytes. The snapshot ring is by far the
     * biggest thing this program holds, so it is worth watching.
     */
    public function size(): int
    {
        return strlen($this->data);
    }
}
