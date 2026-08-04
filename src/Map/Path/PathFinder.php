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

    /** @var array<int, array<int, string>> */
    private array $tiles;

    private int $width;

    private int $height;

    /**
     * $bias is what the searcher subjectively adds to a tile — a cat's fear.
     * Left out, the search is the world's own opinion, which is what the
     * field of view wants: being afraid of a place does not stop one seeing
     * it.
     */
    public function __construct(private MapBuilder $map, private ?CostBiasInterface $bias = null)
    {
        $this->costs = $map->costGrid();
        $this->tiles = $map->terrain();
        $this->height = count($this->costs);
        $this->width = count($this->costs[0] ?? []);
    }

    /**
     * Route to the closest reachable tile holding $item, excluding the
     * starting tile. Null when no such tile can be reached.
     *
     * $range bounds the search to what the searcher can see, in tiles. That
     * is what lets the map grow without the simulation slowing down: an
     * unbounded search that finds nothing has to flood everything reachable,
     * so its cost follows the size of the world. Bounded, it does not.
     *
     * @return list<Point>|null
     */
    public function toNearest(Point $from, string $item, ?int $range = null): ?array
    {
        // Goals are looked up by index rather than re-read from the map on
        // every expanded tile. The sweep that collects them is bounded too:
        // bounding only the flood would still scan the whole map here.
        $goals = [];

        foreach ($this->map->positionsOf($item, $from, $range) as [$y, $x]) {
            $goals[$this->index($x, $y)] = true;
        }

        unset($goals[$this->index($from->getX(), $from->getY())]);

        return $this->search($from, $goals, $this->budget($range));
    }

    /**
     * @return list<Point>|null
     */
    public function to(Point $from, Point $destination, ?int $range = null): ?array
    {
        if (null === $this->costAt($destination->getX(), $destination->getY())) {
            return null;
        }

        return $this->search(
            $from,
            [$this->index($destination->getX(), $destination->getY()) => true],
            $this->budget($range)
        );
    }

    /**
     * A range in tiles becomes a ceiling on accumulated cost.
     *
     * Spending the budget in cost rather than in distance has a side effect
     * worth keeping: undergrowth costs three times what grass does, so a cat
     * sees three times less far through a wood than across a meadow. That is
     * the behaviour one would have had to write by hand otherwise.
     */
    private function budget(?int $range): ?int
    {
        return null === $range ? null : self::budgetFor($range);
    }

    /**
     * The cost a range of tiles is worth, for anyone who needs to reason about
     * the same ceiling — drawing the edge of what a cat can see, for one.
     */
    public static function budgetFor(int $range): int
    {
        return $range * self::STRAIGHT;
    }

    /**
     * What a searcher standing at $from can actually reach, as tile index to
     * accumulated cost.
     *
     * This is the flood with nothing to find: it stops at the budget and
     * returns everything it touched. The shape is never a circle — undergrowth
     * costs three times what grass does and water is not crossed at all — so
     * it is also the only honest way to draw a field of view.
     *
     * @return array<int, int>
     */
    public function costsWithin(Point $from, int $range): array
    {
        if (0 === $this->width) {
            return [];
        }

        return $this->flood($from, [], $this->budget($range))['best'];
    }

    /**
     * @param array<int, true> $goals
     *
     * @return list<Point>|null
     */
    private function search(Point $from, array $goals, ?int $budget = null): ?array
    {
        if ([] === $goals || 0 === $this->width) {
            return null;
        }

        $flood = $this->flood($from, $goals, $budget);

        if (null === $flood['reached']) {
            return null;
        }

        return $this->rebuild(
            $flood['cameFrom'],
            $flood['reached'],
            $this->index($from->getX(), $from->getY())
        );
    }

    /**
     * Expand outwards by cost until a goal is met or the budget runs out.
     *
     * Shared by the routing and by the field of view, which are the same
     * flood asked two different questions.
     *
     * @param array<int, true> $goals
     *
     * @return array{best: array<int, int>, cameFrom: array<int, int>, reached: int|null}
     */
    private function flood(Point $from, array $goals, ?int $budget): array
    {
        $start = $this->index($from->getX(), $from->getY());

        // Two costs are carried, and keeping them apart is the whole point.
        // $reach is what the world charges and is the only thing the budget
        // is measured against — sight is a fact. $best adds what the searcher
        // subjectively fears and is what the expansion is ordered by — that
        // is a preference. Conflated, a cat that fears a path stops *seeing*
        // the food at the end of it, which is not what fear does.
        $best = [$start => 0];
        $reach = [$start => 0];
        $cameFrom = [];

        $queue = new SplPriorityQueue();
        $queue->insert($start, 0);

        while (!$queue->isEmpty()) {
            $current = $queue->extract();

            if (isset($goals[$current])) {
                return ['best' => $reach, 'cameFrom' => $cameFrom, 'reached' => $current];
            }

            $x = $current % $this->width;
            $y = intdiv($current, $this->width);
            $costSoFar = $best[$current];
            $reachSoFar = $reach[$current];

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

                $step = $cost * ((0 !== $dx && 0 !== $dy) ? self::DIAGONAL : self::STRAIGHT);
                $walked = $reachSoFar + $step;

                // Out of sight. Measured on what the world charges, never on
                // what the searcher fears. Dropping the tile here rather than
                // after expanding it is what keeps the frontier bounded, so
                // the search costs the same on any size of map.
                if (null !== $budget && $walked > $budget) {
                    continue;
                }

                $total = $costSoFar + $step;

                if (null !== $this->bias) {
                    $total += $this->bias->bias($this->tiles[$ny][$nx] ?? '', $nx, $ny);
                }

                $key = $this->index($nx, $ny);

                if (isset($best[$key]) && $best[$key] <= $total) {
                    continue;
                }

                $best[$key] = $total;
                $reach[$key] = $walked;
                $cameFrom[$key] = $current;
                // SplPriorityQueue pops the highest priority first.
                $queue->insert($key, -$total);
            }
        }

        return ['best' => $reach, 'cameFrom' => $cameFrom, 'reached' => null];
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
