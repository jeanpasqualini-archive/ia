<?php

declare(strict_types=1);

namespace Map\Provider;

use Map\Builder\MapBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * What lies under the meadow: rock, with tunnels cut through it.
 *
 * Built on the same noise as the surface, because the terrain generator is
 * already the thing that knows how to make regions contiguous and a cave
 * system is that problem upside down. A fresh field is generated with a
 * shifted seed — the same one cut twice would trace the lakes overhead — and
 * the two *extremes* of it become tunnel while the middle stays solid. Cutting
 * the extremes rather than one tail is what makes the galleries wander and
 * meet instead of sitting as separate pockets.
 *
 * The place is deliberately poorer than the surface: one thing to eat, and no
 * way to be hurt. It exists to be somewhere else to go, not to be a second
 * world with its own everything.
 */
class UndergroundMapProvider implements MapProviderInterface
{
    /**
     * Share of the tunnel floor where mushrooms grow. Deliberately meagre.
     *
     * At nine percent the underground carried as much food as the meadow —
     * measured, a cat went down once and stayed for the rest of the run,
     * which turns a refuge into a home. Thin enough and the food around a
     * cat runs out, the "nothing in sight" rule sends it back up a cavern,
     * and the place goes back to being what it is for.
     */
    public const MUSHROOM_SHARE = 0.012;

    private Randomizer $randomizer;

    public function __construct(
        private int $lines,
        private int $columns,
        private ?int $seed = null,
    ) {
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * @return list<string>
     */
    public function getMap(): array
    {
        $surface = new TerrainMapProvider(
            $this->lines,
            $this->columns,
            null === $this->seed ? null : $this->seed + 7_919
        );

        $rows = [];

        foreach ($surface->getMap() as $row) {
            $out = '';

            foreach (mb_str_split($row) as $tile) {
                $out .= $this->carve($tile);
            }

            $rows[] = $out;
        }

        return $rows;
    }

    /**
     * The low ground and the high ground become open tunnel — roughly forty
     * percent of the map between them — and the meadow in between is the rock
     * they run through.
     */
    private function carve(string $tile): string
    {
        $open = in_array($tile, [
            MapBuilder::EAU,
            MapBuilder::ARBRE,
            MapBuilder::FOURRE,
        ], true);

        if (!$open) {
            return MapBuilder::ROCHE;
        }

        return $this->randomizer->getInt(1, 1000) <= (int) (self::MUSHROOM_SHARE * 1000)
            ? MapBuilder::CHAMPIGNON
            : MapBuilder::GALERIE;
    }
}
