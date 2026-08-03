<?php

declare(strict_types=1);

namespace Snapshot;

/**
 * Ring buffer of snapshots: once full, adding one drops the oldest.
 */
class InstantCollectionLimited extends InstantCollection
{
    public function __construct(protected int $limit = 10)
    {
    }

    public function add(Instant $instant): void
    {
        parent::add($instant);

        if (count($this->instantCollection) > $this->limit) {
            $this->instantCollection = array_values(
                array_slice($this->instantCollection, -$this->limit)
            );
        }
    }
}
