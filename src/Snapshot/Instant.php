<?php

declare(strict_types=1);

namespace Snapshot;

/**
 * A frozen copy of an object graph. Serializing on the way in is what makes
 * the snapshot immune to later mutations of the live world.
 */
class Instant
{
    private string $data;

    public function __construct(object $instance)
    {
        $this->data = serialize($instance);
    }

    public function getData(): mixed
    {
        return unserialize($this->data);
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
