<?php

declare(strict_types=1);

namespace Map\Path;

use Map\Builder\MapBuilder;
use Map\Location\Point;
use SplPriorityQueue;

/**
 * Uniform cost search over the tile grid.
 *
 * Looking for the closest flower and computing the route to it is the same
 * problem, so it is the same flood: expand outwards by cost until a tile
 * satisfies the goal. Running one search beats running A* once per candidate
 * flower, and it answers "the nearest one I can actually reach" rather than
 * "the nearest one as the crow flies" — which, now that lakes block movement,
 * are frequently not the same tile.
 */
class PathFinder
{
    private const MOVES = [
        [0, -1], [0, 1], [-1, 0], [1, 0],
        [-1, -1], [1, -1], [-1, 1], [1, 1],
    ];

    /**
     * Tile costs are scaled so a diagonal step can cost more than a straight
     * one. Charged the same, a detour through a diagonal ties with the
     * straight line and the cat wanders sideways for no reason.
     */
    private const STRAIGHT = 10;
    private const DIAGONAL = 14;

    public function __construct(private MapBuilder $map)
    {
    }

    /**
     * Route to the closest reachable tile holding $item, excluding the
     * starting tile. Null when no such tile can be reached.
     *
     * @return list<Point>|null
     */
    public function toNearest(Point $from, string $item): ?array
    {
        return $this->search(
            $from,
            fn (Point $point): bool => $this->map->getItem($point) === $item
                && !$point->equals($from)
        );
    }

    /**
     * @return list<Point>|null
     */
    public function to(Point $from, Point $destination): ?array
    {
        if (!$this->map->isWalkable($destination)) {
            return null;
        }

        return $this->search($from, static fn (Point $point): bool => $point->equals($destination));
    }

    /**
     * @param callable(Point): bool $isGoal
     *
     * @return list<Point>|null
     */
    private function search(Point $from, callable $isGoal): ?array
    {
        $start = $this->key($from);
        $best = [$start => 0];
        $cameFrom = [];

        $queue = new SplPriorityQueue();
        $queue->insert($from, 0);

        while (!$queue->isEmpty()) {
            /** @var Point $current */
            $current = $queue->extract();
            $currentKey = $this->key($current);

            if ($isGoal($current)) {
                return $this->rebuild($cameFrom, $current, $start);
            }

            foreach (self::MOVES as [$dx, $dy]) {
                $neighbour = new Point($current->getX() + $dx, $current->getY() + $dy);
                $cost = $this->map->cost($neighbour);

                if (null === $cost || !$this->canCross($current, $dx, $dy)) {
                    continue;
                }

                $step = (0 !== $dx && 0 !== $dy) ? self::DIAGONAL : self::STRAIGHT;
                $total = $best[$currentKey] + $cost * $step;
                $key = $this->key($neighbour);

                if (isset($best[$key]) && $best[$key] <= $total) {
                    continue;
                }

                $best[$key] = $total;
                $cameFrom[$key] = $current;
                // SplPriorityQueue pops the highest priority first.
                $queue->insert($neighbour, -$total);
            }
        }

        return null;
    }

    /**
     * A diagonal step is only allowed when at least one of the two tiles it
     * cuts across is walkable, so nobody slips through the corner where two
     * lakes touch.
     */
    private function canCross(Point $from, int $dx, int $dy): bool
    {
        if (0 === $dx || 0 === $dy) {
            return true;
        }

        return $this->map->isWalkable(new Point($from->getX() + $dx, $from->getY()))
            || $this->map->isWalkable(new Point($from->getX(), $from->getY() + $dy));
    }

    /**
     * @param array<string, Point> $cameFrom
     *
     * @return list<Point>
     */
    private function rebuild(array $cameFrom, Point $goal, string $start): array
    {
        $route = [];
        $current = $goal;

        while ($this->key($current) !== $start) {
            $route[] = $current;
            $current = $cameFrom[$this->key($current)];
        }

        return array_reverse($route);
    }

    private function key(Point $point): string
    {
        return $point->getY() . ';' . $point->getX();
    }
}
