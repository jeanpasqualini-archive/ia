<?php

declare(strict_types=1);

/**
 * A throwaway look at the map in 2.5D.
 *
 * **This is beside the game, not in it.** Nothing here is loaded by `console`,
 * nothing in `src/` knows it exists, and it is meant to be read once, argued
 * with, and either taken up or deleted. It prints a still image to the normal
 * screen — no alternate buffer, no raw mode, no game loop.
 *
 * It draws the real terrain: the same `TerrainMapProvider` the game generates
 * from, and the same `TilePalette` colours, so what is on screen is what the
 * map would look like with a third dimension bolted on and nothing else
 * changed. A seed shows the same landscape as `./console --seed N`.
 *
 * The height is *invented here*: the world is flat and has no elevation of its
 * own, so a height is read off the terrain — water lies low, grass sits above
 * it, a wood stands higher, a thicket higher still. It is smoothed twice,
 * because taken straight the map turns into stairs, one step per tile.
 *
 * Two projections, because they answer different questions:
 *
 *   --mode=relief   The rows are pushed up by the height and a cliff face is
 *                   drawn under each one. Columns stay columns, so the map is
 *                   still read left to right and a place can be found on it.
 *
 *   --mode=iso      A proper isometric lattice. Prettier, and it costs the
 *                   thing the game is for: half the world runs off the side
 *                   of the screen, and no one can say where a cat is.
 *
 * Usage:
 *   php demo/relief.php [--seed=7] [--mode=relief|iso] [--width=90] [--depth=44]
 */

require __DIR__ . '/../vendor/autoload.php';

use Map\Builder\MapBuilder;
use Map\Provider\TerrainMapProvider;
use Map\Render\TilePalette;
use PhpTui\Tui\Color\RgbColor;

$options = getopt('', ['seed::', 'mode::', 'width::', 'depth::']);
$seed = (int) ($options['seed'] ?? 7);
$mode = (string) ($options['mode'] ?? 'relief');
$width = max(20, (int) ($options['width'] ?? 90));
$depth = max(10, (int) ($options['depth'] ?? 44));

/**
 * How high each tile stands. Not a property of the world — the map is flat —
 * but the one thing that has to be made up for any of this to mean anything.
 *
 * A hole goes below the ground it is dug in, which is the only place where
 * this reading of the map says something the flat one cannot.
 */
const HEIGHTS = [
    MapBuilder::EAU => 0.0,
    MapBuilder::TROU => 0.4,
    MapBuilder::HERBE => 2.4,
    MapBuilder::FLEUR => 2.4,
    MapBuilder::DIGITALE => 2.4,
    MapBuilder::RONCE => 2.6,
    MapBuilder::ARBRE => 4.6,
    MapBuilder::FOURRE => 6.0,
    MapBuilder::CAVERNE => 1.6,
];

$rows = (new TerrainMapProvider($depth, $width, $seed))->getMap();
$tiles = array_map(static fn (string $row): array => str_split($row), $rows);

$height = [];

foreach ($tiles as $y => $line) {
    foreach ($line as $x => $tile) {
        $height[$y][$x] = HEIGHTS[$tile] ?? 2.4;
    }
}

// Twice, because once still leaves the shoreline as a wall. The averaging is
// deliberately crude: this is a sketch, and a proper heightfield would be a
// second noise generator to argue with.
for ($pass = 0; $pass < 3; $pass++) {
    $smoothed = $height;

    foreach ($height as $y => $line) {
        foreach ($line as $x => $value) {
            $sum = 0.0;
            $count = 0;

            for ($dy = -1; $dy <= 1; $dy++) {
                for ($dx = -1; $dx <= 1; $dx++) {
                    if (isset($height[$y + $dy][$x + $dx])) {
                        $sum += $height[$y + $dy][$x + $dx];
                        ++$count;
                    }
                }
            }

            $smoothed[$y][$x] = $sum / $count;
        }
    }

    $height = $smoothed;
}

$palette = new TilePalette(trueColor: true);

/** @var array<int, array<int, array{int, int, int}>> the screen, in tiles */
$screen = [];

/**
 * Darken or lift a colour. Faces are the whole illusion: a top and a side of
 * the same colour is a flat map with the rows moved about.
 *
 * @return array{int, int, int}
 */
$shade = static function (RgbColor $colour, float $factor): array {
    return [
        max(0, min(255, (int) round($colour->r * $factor))),
        max(0, min(255, (int) round($colour->g * $factor))),
        max(0, min(255, (int) round($colour->b * $factor))),
    ];
};

$put = static function (int $row, int $column, array $rgb) use (&$screen): void {
    if ($row < 0 || $column < 0) {
        return;
    }

    $screen[$row][$column] = $rgb;
};

$colourOf = static function (int $x, int $y) use ($tiles, $palette): RgbColor {
    $colour = $palette->pixel($tiles[$y][$x], $x, $y);
    assert($colour instanceof RgbColor);

    return $colour;
};

$lift = 12; // room above the map for the tallest thing on it

if ('iso' === $mode) {
    // Back to front, by depth along the lattice. No z buffer and none needed:
    // whatever is drawn later is nearer, which is the whole of the painter's
    // algorithm.
    for ($sum = 0; $sum <= ($width - 1) + ($depth - 1); $sum++) {
        for ($x = 0; $x < $width; $x++) {
            $y = $sum - $x;

            if ($y < 0 || $y >= $depth) {
                continue;
            }

            $h = (int) round($height[$y][$x] * 2);
            $column = ($x - $y) * 2 + $depth * 2;
            $row = $x + $y - $h + $lift;
            $colour = $colourOf($x, $y);

            // The wall first, so the face lands on top of its own foundation.
            for ($down = 2; $down <= $h + 2; $down++) {
                for ($across = 0; $across < 4; $across++) {
                    $put($row + $down, $column + $across, $shade($colour, 0.42));
                }
            }

            for ($across = 0; $across < 4; $across++) {
                $put($row, $column + $across, $shade($colour, 1.05));
                $put($row + 1, $column + $across, $shade($colour, 0.82));
            }
        }
    }
} else {
    // Relief: a column per map column, far row drawn first.
    for ($y = 0; $y < $depth; $y++) {
        for ($x = 0; $x < $width; $x++) {
            // Under a tile and a half of travel between a lake and a thicket:
            // taken at face value the wood stands ten rows above the meadow
            // and the picture shreds — one reads a torn map rather than a
            // hill. The illusion wants the smallest displacement that still
            // says which way is up.
            $h = (int) round($height[$y][$x] * 0.9);
            $top = $y - $h + $lift;
            $colour = $colourOf($x, $y);

            // The face under the tile, down to the ground it stands on. Rows
            // nearer the viewer are drawn later and cover it, so only what is
            // actually exposed survives.
            for ($row = $top + 1; $row <= $y + $lift; $row++) {
                $put($row, $x, $shade($colour, 0.45));
            }

            // Higher ground catches more light, which is what turns a set of
            // steps into a landscape.
            $put($top, $x, $shade($colour, 0.8 + 0.06 * $h));
        }
    }
}

// Out as half blocks: two tile rows share a cell, the upper one as the
// foreground and the lower as the background. Exactly what the game does, and
// the reason a tile is square rather than half as wide as it is tall.
$bottom = max(array_keys($screen));
$sky = [16, 18, 24];

for ($row = 0; $row <= $bottom; $row += 2) {
    $line = '';
    $columns = max(
        empty($screen[$row]) ? 0 : max(array_keys($screen[$row])),
        empty($screen[$row + 1]) ? 0 : max(array_keys($screen[$row + 1]))
    );

    for ($column = 0; $column <= $columns; $column++) {
        [$ur, $ug, $ub] = $screen[$row][$column] ?? $sky;
        [$lr, $lg, $lb] = $screen[$row + 1][$column] ?? $sky;

        $line .= sprintf("\e[38;2;%d;%d;%dm\e[48;2;%d;%d;%dm▀", $ur, $ug, $ub, $lr, $lg, $lb);
    }

    echo $line . "\e[0m\n";
}

echo sprintf(
    "\e[0m\n  seed %d, %s, %dx%d tiles — php demo/relief.php --mode=%s --seed=%d\n",
    $seed,
    $mode,
    $width,
    $depth,
    'iso' === $mode ? 'relief' : 'iso',
    $seed + 1
);
