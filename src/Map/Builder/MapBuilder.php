<?php

declare(strict_types=1);

namespace Map\Builder;

use Logger\MultipleLogger;
use Map\Location\Point;

/**
 * The map itself: a stack of named layers of single-character tiles, flattened
 * into a final layer the renderers consume.
 */
class MapBuilder
{
    public const HERBE = 'X';
    public const ARBRE = 'Y';
    public const EAU = 'E';
    public const FLEUR = 'F';
    public const RONCE = 'R';

    /**
     * Foxglove: a flower like any other to look at, and poisonous to eat.
     *
     * It is what gives a cat something to learn that is not pain from the
     * ground. It cannot be told from food before being tasted, which is the
     * point — the tile *is* distinguishable, so the cat can learn which is
     * which, it simply does not know yet.
     */
    public const DIGITALE = 'D';

    /**
     * A pit. Walkable, slow to climb out of, and it hurts several times what
     * a bramble does.
     *
     * Its point is not the damage but the *gradient*: the memory learns in
     * proportion to the pain, so a cat comes to fear a hole far more than a
     * thorn without anything having been written down about either.
     */
    public const TROU = 'T';

    /**
     * Dense thicket: the heart of a wood, where the undergrowth closes in.
     *
     * It costs six to push through against three for open forest, and that
     * one number does two things at once. It makes a cat walk round rather
     * than through — and, because sight is spent in cost rather than in
     * distance, it also means a cat sees barely four tiles into one. The
     * thicket blocks the view without anything ever mentioning the view.
     */
    public const FOURRE = 'U';

    /**
     * What a cat will walk to when hungry. Both look like a meal; only one
     * is.
     *
     * @var list<string>
     */
    public const NOURRITURE = [self::FLEUR, self::DIGITALE];

    public const LAYER_MAP = 'map';
    public const LAYER_PLAYER = 'player';

    /** @var list<string> */
    private const ALLOWED_ITEMS = [
        self::HERBE, self::ARBRE, self::EAU, self::FLEUR,
        self::RONCE, self::DIGITALE, self::TROU, self::FOURRE,
    ];

    /**
     * What eating a tile gives: food on the left, damage on the right.
     *
     * @var array<string, array{int, int}>
     */
    private const EATING = [
        self::FLEUR => [10, 0],
        self::DIGITALE => [0, 3],
    ];

    /**
     * Damage taken for standing on a tile.
     *
     * Brambles are the only thing that hurts, and note what they cost to walk
     * on: one, the same as grass. That is deliberate and it is the whole
     * mechanism. If they were expensive here, every cat would route round
     * them from birth and there would be nothing to learn — the avoidance has
     * to come from the cat's own memory, not from the map telling it.
     *
     * @var array<string, int>
     */
    private const HURTS = [
        self::RONCE => 1,
        self::TROU => 4,
    ];

    /**
     * Cost of stepping onto a tile, null meaning impassable. Undergrowth is
     * crossable but slow enough that a cat prefers to walk around a wood.
     *
     * @var array<string, int|null>
     */
    private const COSTS = [
        self::HERBE => 1,
        self::FLEUR => 1,
        self::RONCE => 1,
        self::DIGITALE => 1,
        self::ARBRE => 3,
        self::FOURRE => 6,
        // One, like grass, and for the same reason brambles are one: this was
        // three at first — climbing out ought to cost something — and the
        // pathfinder then routed round every pit from birth. Six thousand
        // ticks on three maps and no cat ever fell in, so nothing was ever
        // learnt. A danger written into the map is not a danger, it is a wall.
        self::TROU => 1,
        self::EAU => null,
    ];

    /** @var array<string, array<int, array<int, string>>> */
    private array $layers = [];

    /** @var array<int, array<int, string>> */
    private array $finalLayer = [];

    /**
     * Built once and kept until the terrain changes.
     *
     * Manger builds a PathFinder on every re-route and each one asked for the
     * whole grid. On a map the size of the screen that was invisible; on a
     * large one, copying forty thousand tiles cost more than the search it
     * was preparing. Only the terrain layer feeds it, so a cat moving — which
     * writes to the player layer every frame — does not throw it away.
     *
     * @var list<list<int|null>>|null
     */
    private ?array $costGrid = null;

    /**
     * @param list<string> $map
     */
    public function __construct(array $map, private ?MultipleLogger $logger = null)
    {
        $this->layers[self::LAYER_MAP] = array_map(mb_str_split(...), $map);
        $this->layers[self::LAYER_PLAYER] = [];
        $this->updateFinalLayer();
    }

    /**
     * @return list<string>
     */
    public static function getAllowedItems(): array
    {
        return self::ALLOWED_ITEMS;
    }

    /**
     * The map is essentially the whole snapshot, and `serialize()` is a poor
     * way to write it: a tile costs `i:127;s:1:"X";`, some twenty five bytes
     * for one character. Rows are therefore packed back into strings on the
     * way out, which took a 256x160 world from 1.07 MB to 42 KB — before the
     * compression in `Instant` even runs.
     *
     * Only the terrain layer is packed. The player layer is sparse — tiles
     * are written at arbitrary coordinates — and imploding it would silently
     * move every cat to the start of its row.
     *
     * The final layer is not stored at all: it is derived, and rebuilt here.
     * Neither is the logger, which a frozen map has no business carrying.
     *
     * @return array{terrain: array<int, string>, layers: array<string, array<int, array<int, string>>>}
     */
    public function __serialize(): array
    {
        $layers = $this->layers;
        unset($layers[self::LAYER_MAP]);

        return [
            'terrain' => array_map(
                static fn (array $row): string => implode('', $row),
                $this->layers[self::LAYER_MAP]
            ),
            'layers' => $layers,
        ];
    }

    /**
     * @param array{terrain: array<int, string>, layers: array<string, array<int, array<int, string>>>} $data
     */
    public function __unserialize(array $data): void
    {
        // The terrain has to come back *first*. Layers are flattened in
        // insertion order, so restoring it last would repaint the ground over
        // the players standing on it and every cat would vanish.
        $this->layers = [self::LAYER_MAP => array_map(mb_str_split(...), $data['terrain'])]
            + $data['layers'];
        $this->logger = null;
        $this->costGrid = null;

        $this->updateFinalLayer();
    }

    public function getWidth(): int
    {
        return count($this->layers[self::LAYER_MAP][0] ?? []);
    }

    public function getHeight(): int
    {
        return count($this->layers[self::LAYER_MAP]);
    }

    /**
     * Keep a position inside the map. Nothing did this before, so a player
     * walking to the edge kept going and later reads went out of bounds.
     */
    public function clamp(Point $point): void
    {
        $point->setX(max(0, min($this->getWidth() - 1, $point->getX())));
        $point->setY(max(0, min($this->getHeight() - 1, $point->getY())));
    }

    public function contains(Point $point): bool
    {
        return isset($this->layers[self::LAYER_MAP][$point->getY()][$point->getX()]);
    }

    /**
     * Cost of stepping onto this tile, null when it cannot be walked on.
     */
    public function cost(Point $point): ?int
    {
        $tile = $this->getItem($point);

        if (null === $tile) {
            return null;
        }

        // array_key_exists, not ??: an impassable tile has a null cost, which
        // ?? would happily replace with the default.
        return array_key_exists($tile, self::COSTS) ? self::COSTS[$tile] : 1;
    }

    /** Nourishment from eating what stands on this tile. */
    public function nourishment(Point $point): int
    {
        return (self::EATING[$this->getItem($point) ?? ''] ?? [0, 0])[0];
    }

    /** Damage from eating what stands on this tile. */
    public function poison(Point $point): int
    {
        return (self::EATING[$this->getItem($point) ?? ''] ?? [0, 0])[1];
    }

    /**
     * The terrain tile at raw coordinates, without going through a Point.
     * Read on every tile a search expands, so it stays allocation free.
     */
    public function tileAt(int $x, int $y): ?string
    {
        return $this->layers[self::LAYER_MAP][$y][$x] ?? null;
    }

    /**
     * The terrain layer itself, for a caller that walks it tile by tile.
     *
     * @return array<int, array<int, string>>
     */
    public function terrain(): array
    {
        return $this->layers[self::LAYER_MAP];
    }

    /**
     * Damage for standing here, zero for anywhere that does not bite.
     */
    public function hurts(Point $point): int
    {
        return self::HURTS[$this->getItem($point) ?? ''] ?? 0;
    }

    public function isWalkable(Point $point): bool
    {
        return null !== $this->cost($point);
    }

    /**
     * Every tile cost in one pass, indexed [y][x], null meaning impassable.
     *
     * The pathfinder needs the whole grid, and asking for it tile by tile
     * through Point objects turned out to dominate its runtime.
     *
     * @return list<list<int|null>>
     */
    public function costGrid(): array
    {
        return $this->costGrid ??= $this->buildCostGrid();
    }

    /**
     * @return list<list<int|null>>
     */
    private function buildCostGrid(): array
    {
        $grid = [];

        foreach ($this->layers[self::LAYER_MAP] as $line) {
            $row = [];

            foreach ($line as $tile) {
                $row[] = array_key_exists($tile, self::COSTS) ? self::COSTS[$tile] : 1;
            }

            $grid[] = $row;
        }

        return $grid;
    }

    /**
     * Tiles holding $item — one kind or several — as flat [y, x] pairs.
     *
     * A centre and a range restrict the sweep to what a searcher could
     * possibly reach. Scanning the whole map to keep the handful of tiles
     * within sight is the kind of cost that stays hidden while the map is the
     * size of the screen and dominates once it is not.
     *
     * @return list<array{int, int}>
     */
    public function positionsOf(string|array $item, ?Point $around = null, ?int $range = null): array
    {
        $wanted = array_flip((array) $item);
        $found = [];

        $top = 0;
        $bottom = $this->getHeight() - 1;
        $left = 0;
        $right = $this->getWidth() - 1;

        if (null !== $around && null !== $range) {
            $top = max($top, $around->getY() - $range);
            $bottom = min($bottom, $around->getY() + $range);
            $left = max($left, $around->getX() - $range);
            $right = min($right, $around->getX() + $range);
        }

        for ($y = $top; $y <= $bottom; $y++) {
            for ($x = $left; $x <= $right; $x++) {
                if (isset($wanted[$this->layers[self::LAYER_MAP][$y][$x] ?? ''])) {
                    $found[] = [$y, $x];
                }
            }
        }

        return $found;
    }

    /**
     * Closest walkable tile around $point, searched outwards. Used to place
     * players, so nobody spawns in the middle of a lake.
     */
    public function nearestWalkable(Point $point): Point
    {
        if ($this->isWalkable($point)) {
            return $point;
        }

        $radius = max($this->getWidth(), $this->getHeight());

        for ($ring = 1; $ring <= $radius; $ring++) {
            for ($dy = -$ring; $dy <= $ring; $dy++) {
                for ($dx = -$ring; $dx <= $ring; $dx++) {
                    // Only the outline of the ring is new ground.
                    if (abs($dx) !== $ring && abs($dy) !== $ring) {
                        continue;
                    }

                    $candidate = new Point($point->getX() + $dx, $point->getY() + $dy);

                    if ($this->isWalkable($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return $point;
    }

    /**
     * Every occurrence of $item, closest first.
     *
     * @return list<array{distance: int, point: Point}>
     */
    public function findItems(Point $point, string $item, string $layer = self::LAYER_MAP): array
    {
        $found = [];

        foreach ($this->layers[$layer] ?? [] as $y => $line) {
            foreach ($line as $x => $tile) {
                if ($tile !== $item) {
                    continue;
                }

                $candidate = new Point($x, $y);
                $found[] = ['distance' => $point->distanceTo($candidate), 'point' => $candidate];
            }
        }

        usort($found, static fn (array $a, array $b): int => $a['distance'] <=> $b['distance']);

        return $found;
    }

    public function getItem(Point $position, string $layer = self::LAYER_MAP): ?string
    {
        return $this->layers[$layer][$position->getY()][$position->getX()] ?? null;
    }

    public function setItem(Point $position, string $item, string $layer = self::LAYER_MAP): void
    {
        $this->layers[$layer][$position->getY()][$position->getX()] = $item;

        if (self::LAYER_MAP === $layer) {
            $this->costGrid = null;
        }
    }

    public function clearLayer(string $layer): void
    {
        $this->layers[$layer] = [];
    }

    /**
     * Flatten the layers, lowest first, into the map handed to the renderer.
     */
    public function updateFinalLayer(): void
    {
        $this->finalLayer = [];

        foreach ($this->layers as $layer) {
            foreach ($layer as $y => $line) {
                foreach ($line as $x => $tile) {
                    $this->finalLayer[$y][$x] = $tile;
                }
            }
        }
    }

    /**
     * @return array<int, array<int, string>>
     */
    public function getFinalMap(): array
    {
        return $this->finalLayer;
    }
}
