<?php

declare(strict_types=1);

namespace Map\Path;

/**
 * A searcher's own opinion about what a tile costs, added on top of what it
 * really costs.
 *
 * The cost grid is objective and shared — it is cached on the map, and every
 * cat reads the same one. This is where a cat's subjectivity goes instead,
 * which is why it arrives as a separate object rather than as a second grid:
 * a per-cat copy of forty thousand tiles would undo the caching it sits
 * beside.
 */
interface CostBiasInterface
{
    /**
     * Extra cost, in the same units as the grid: a straight step over grass
     * is ten.
     */
    public function bias(string $tile, int $x, int $y): int;
}
