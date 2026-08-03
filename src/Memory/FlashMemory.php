<?php

declare(strict_types=1);

namespace Memory;

use Snapshot\Instant;
use Snapshot\InstantCollectionLimited;

/**
 * Short term memory: a bounded ring of world snapshots plus a read cursor,
 * which is what the time machine walks back and forth.
 */
class FlashMemory
{
    private InstantCollectionLimited $instantCollection;

    private int $positionRead = -1;

    public function __construct(private int $limit = 10)
    {
        $this->instantCollection = new InstantCollectionLimited($this->limit);
    }

    public function addInstant(Instant $instant): void
    {
        $this->instantCollection->add($instant);

        $this->positionRead = $this->instantCollection->count() - 1;
    }

    public function getPositionRead(): int
    {
        return $this->positionRead;
    }

    public function getPlaces(): int
    {
        return $this->limit;
    }

    /**
     * @return list<Instant>
     */
    public function all(): array
    {
        return $this->instantCollection->all();
    }

    public function previous(): ?Instant
    {
        if ($this->positionRead <= 0) {
            return null;
        }

        $this->positionRead--;

        return $this->instantCollection->get($this->positionRead);
    }

    public function after(): ?Instant
    {
        if ($this->positionRead >= $this->instantCollection->count() - 1) {
            return null;
        }

        $this->positionRead++;

        return $this->instantCollection->get($this->positionRead);
    }

    public function count(): int
    {
        return $this->instantCollection->count();
    }

    /**
     * Total bytes held by the retained snapshots.
     */
    public function bytes(): int
    {
        return array_sum(array_map(
            static fn (Instant $instant): int => $instant->size(),
            $this->all()
        ));
    }
}
