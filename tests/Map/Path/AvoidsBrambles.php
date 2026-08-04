<?php

declare(strict_types=1);

namespace Tests\Map\Path;

use Map\Builder\MapBuilder;
use Map\Path\CostBiasInterface;

/**
 * A fixed opinion, so the pathfinder can be tested without dragging a cat and
 * its memory into it.
 */
final class AvoidsBrambles implements CostBiasInterface
{
    public function __construct(private int $extra = 100)
    {
    }

    public function bias(string $tile, int $x, int $y): int
    {
        return MapBuilder::RONCE === $tile ? $this->extra : 0;
    }
}
