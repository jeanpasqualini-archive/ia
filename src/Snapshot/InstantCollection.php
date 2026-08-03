<?php

declare(strict_types=1);

namespace Snapshot;

use Countable;
use Iterator;

/**
 * @implements Iterator<int, Instant>
 */
class InstantCollection implements Iterator, Countable
{
    /** @var list<Instant> */
    protected array $instantCollection = [];

    protected int $index = 0;

    /**
     * @return list<Instant>
     */
    public function all(): array
    {
        return $this->instantCollection;
    }

    public function add(Instant $instant): void
    {
        $this->instantCollection[] = $instant;
    }

    public function current(): Instant
    {
        return $this->instantCollection[$this->index];
    }

    public function get(int $index): ?Instant
    {
        return $this->instantCollection[$index] ?? null;
    }

    public function next(): void
    {
        $this->index++;
    }

    public function key(): int
    {
        return $this->index;
    }

    public function valid(): bool
    {
        return isset($this->instantCollection[$this->index]);
    }

    public function rewind(): void
    {
        $this->index = 0;
    }

    public function count(): int
    {
        return count($this->instantCollection);
    }
}
