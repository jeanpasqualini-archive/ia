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

    public const LAYER_MAP = 'map';
    public const LAYER_PLAYER = 'player';

    /** @var list<string> */
    private const ALLOWED_ITEMS = [self::HERBE, self::ARBRE, self::EAU, self::FLEUR];

    /**
     * Cost of stepping onto a tile, null meaning impassable. Undergrowth is
     * crossable but slow enough that a cat prefers to walk around a wood.
     *
     * @var array<string, int|null>
     */
    private const COSTS = [
        self::HERBE => 1,
        self::FLEUR => 1,
        self::ARBRE => 3,
        self::EAU => null,
    ];

    /** @var array<string, array<int, array<int, string>>> */
    private array $layers = [];

    /** @var array<int, array<int, string>> */
    private array $finalLayer = [];

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
     * Tiles holding $item, as flat [y, x] pairs.
     *
     * @return list<array{int, int}>
     */
    public function positionsOf(string $item): array
    {
        $found = [];

        foreach ($this->layers[self::LAYER_MAP] as $y => $line) {
            foreach ($line as $x => $tile) {
                if ($tile === $item) {
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
