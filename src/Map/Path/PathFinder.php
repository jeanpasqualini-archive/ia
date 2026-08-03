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
 *
 * The whole search runs on integers over a flat cost grid. The first version
 * created Point objects for every neighbour it looked at — about two dozen per
 * expanded tile — and a search that found nothing, having to visit the entire
 * map, took 50ms. That capped the simulation at roughly forty ticks per second
 * as soon as the food ran out.
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

    /** @var list<list<int|null>> */
    private array $costs;

    private int $width;

    private int $height;

    public function __construct(private MapBuilder $map)
    {
        $this->costs = $map->costGrid();
        $this->height = count($this->costs);
        $this->width = count($this->costs[0] ?? []);
    }

    /**
     * Route to the closest reachable tile holding $item, excluding the
     * starting tile. Null when no such tile can be reached.
     *
     * @return list<Point>|null
     */
    public function toNearest(Point $from, string $item): ?array
    {
        // Goals are looked up by index rather than re-read from the map on
        // every expanded tile.
        $goals = [];

        foreach ($this->map->positionsOf($item) as [$y, $x]) {
            $goals[$this->index($x, $y)] = true;
        }

        unset($goals[$this->index($from->getX(), $from->getY())]);

        return $this->search($from, $goals);
    }

    /**
     * @return list<Point>|null
     */
    public function to(Point $from, Point $destination): ?array
    {
        if (null === $this->costAt($destination->getX(), $destination->getY())) {
            return null;
        }

        return $this->search($from, [$this->index($destination->getX(), $destination->getY()) => true]);
    }

    /**
     * @param array<int, true> $goals
     *
     * @return list<Point>|null
     */
    private function search(Point $from, array $goals): ?array
    {
        if ([] === $goals || 0 === $this->width) {
            return null;
        }

        $start = $this->index($from->getX(), $from->getY());
        $best = [$start => 0];
        $cameFrom = [];

        $queue = new SplPriorityQueue();
        $queue->insert($start, 0);

        while (!$queue->isEmpty()) {
            $current = $queue->extract();

            if (isset($goals[$current])) {
                return $this->rebuild($cameFrom, $current, $start);
            }

            $x = $current % $this->width;
            $y = intdiv($current, $this->width);
            $costSoFar = $best[$current];

            foreach (self::MOVES as [$dx, $dy]) {
                $nx = $x + $dx;
                $ny = $y + $dy;
                $cost = $this->costs[$ny][$nx] ?? null;

                if (null === $cost) {
                    continue;
                }

                // A diagonal may not slip through the corner where two lakes
                // touch: at least one of the tiles it cuts across must be open.
                if (0 !== $dx && 0 !== $dy
                    && null === ($this->costs[$y][$nx] ?? null)
                    && null === ($this->costs[$ny][$x] ?? null)
                ) {
                    continue;
                }

                $total = $costSoFar + $cost * ((0 !== $dx && 0 !== $dy) ? self::DIAGONAL : self::STRAIGHT);
                $key = $this->index($nx, $ny);

                if (isset($best[$key]) && $best[$key] <= $total) {
                    continue;
                }

                $best[$key] = $total;
                $cameFrom[$key] = $current;
                // SplPriorityQueue pops the highest priority first.
                $queue->insert($key, -$total);
            }
        }

        return null;
    }

    /**
     * @param array<int, int> $cameFrom
     *
     * @return list<Point>
     */
    private function rebuild(array $cameFrom, int $goal, int $start): array
    {
        $route = [];
        $current = $goal;

        while ($current !== $start) {
            $route[] = new Point($current % $this->width, intdiv($current, $this->width));
            $current = $cameFrom[$current];
        }

        return array_reverse($route);
    }

    private function costAt(int $x, int $y): ?int
    {
        return $this->costs[$y][$x] ?? null;
    }

    private function index(int $x, int $y): int
    {
        return $y * $this->width + $x;
    }
}
