<?php

declare(strict_types=1);

namespace Map\Provider;

use Map\Builder\MapBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Coherent terrain generator.
 *
 * The previous provider drew every cell independently, which produced white
 * noise: no lake, no forest, just an even sprinkle of tiles. Here two fractal
 * value-noise fields are generated instead — one for elevation, one for
 * flowers — and the terrain is cut out of them. Low ground becomes water, high
 * ground becomes forest, the rest is grass, and flowers only grow where their
 * own field peaks, which groups them into meadows instead of scattering them.
 *
 * The cuts are quantiles, not fixed values: asking for "the lowest 18% of the
 * map" gives the same coverage on every seed, whereas a fixed threshold on a
 * normalized field swung between 19% and 37% of water depending on how the
 * noise happened to fall. Terrain shares are a contract here, not an accident.
 */
class TerrainMapProvider implements MapProviderInterface
{
    /** Share of the map covered by water, as lakes in the low ground. */
    public const WATER_SHARE = 0.18;

    /** Share of the map covered by forest, on the high ground. */
    public const FOREST_SHARE = 0.22;

    /** Share of the *grass* that blooms. The cat has to find food easily. */
    public const FLOWER_SHARE = 0.07;

    /** Distance between two control points of the coarsest octave, in tiles. */
    private const BASE_CELL = 12;

    /** Amplitude of each successive octave, coarsest first. */
    private const OCTAVES = [1.0, 0.5, 0.25];

    private Randomizer $randomizer;

    public function __construct(
        private int $lines,
        private int $columns,
        ?int $seed = null,
    ) {
        // An explicit seed makes a map reproducible, which is what lets the
        // tests assert on the shape of the terrain instead of its statistics.
        $this->randomizer = new Randomizer(new Mt19937($seed));
    }

    /**
     * @return list<string>
     */
    public function getMap(): array
    {
        $elevation = $this->field();
        $bloom = $this->field();

        $levels = $this->quantiles($elevation, [self::WATER_SHARE, 1 - self::FOREST_SHARE]);
        [$waterLevel, $forestLevel] = $levels;

        $tiles = [];
        $grassBloom = [];

        for ($y = 0; $y < $this->lines; $y++) {
            for ($x = 0; $x < $this->columns; $x++) {
                $height = $elevation[$y][$x];

                $tiles[$y][$x] = match (true) {
                    $height <= $waterLevel => MapBuilder::EAU,
                    $height >= $forestLevel => MapBuilder::ARBRE,
                    default => MapBuilder::HERBE,
                };

                if (MapBuilder::HERBE === $tiles[$y][$x]) {
                    $grassBloom[] = $bloom[$y][$x];
                }
            }
        }

        // Flowers are ranked among grass cells only, so their share does not
        // silently shrink on a map that happens to be mostly lake.
        $bloomLevel = [] === $grassBloom
            ? PHP_FLOAT_MAX
            : $this->quantile($grassBloom, 1 - self::FLOWER_SHARE);

        $map = [];

        for ($y = 0; $y < $this->lines; $y++) {
            $row = '';

            for ($x = 0; $x < $this->columns; $x++) {
                $row .= MapBuilder::HERBE === $tiles[$y][$x] && $bloom[$y][$x] >= $bloomLevel
                    ? MapBuilder::FLEUR
                    : $tiles[$y][$x];
            }

            $map[] = $row;
        }

        return $map;
    }

    /**
     * @param list<list<float>> $field
     * @param list<float> $shares
     *
     * @return list<float>
     */
    private function quantiles(array $field, array $shares): array
    {
        $values = array_merge(...$field);

        return array_map(fn (float $share): float => $this->quantile($values, $share), $shares);
    }

    /**
     * @param list<float> $values
     */
    private function quantile(array $values, float $share): float
    {
        sort($values);

        $index = (int) floor($share * (count($values) - 1));

        return $values[max(0, min($index, count($values) - 1))];
    }

    /**
     * Fractal value noise: a few octaves of interpolated random lattices,
     * summed so large shapes carry small details.
     *
     * @return list<list<float>>
     */
    private function field(): array
    {
        $octaves = [];

        foreach (self::OCTAVES as $index => $amplitude) {
            $cell = max(2, (int) (self::BASE_CELL / 2 ** $index));
            $octaves[] = [
                'amplitude' => $amplitude,
                'cell' => $cell,
                'points' => $this->controlPoints($cell),
            ];
        }

        $field = [];

        for ($y = 0; $y < $this->lines; $y++) {
            $row = [];

            for ($x = 0; $x < $this->columns; $x++) {
                $value = 0.0;

                foreach ($octaves as $octave) {
                    $value += $octave['amplitude'] * $this->sample($octave['points'], $octave['cell'], $x, $y);
                }

                $row[] = $value;
            }

            $field[] = $row;
        }

        return $field;
    }

    /**
     * Random lattice the octave interpolates between.
     *
     * @return list<list<float>>
     */
    private function controlPoints(int $cell): array
    {
        $rows = (int) ceil($this->lines / $cell) + 2;
        $columns = (int) ceil($this->columns / $cell) + 2;

        $points = [];

        for ($j = 0; $j < $rows; $j++) {
            $row = [];

            for ($i = 0; $i < $columns; $i++) {
                $row[] = $this->randomizer->getInt(0, 1_000_000) / 1_000_000;
            }

            $points[] = $row;
        }

        return $points;
    }

    /**
     * Bilinear interpolation between the four lattice points surrounding
     * (x, y), eased so the lattice does not show up as a square grid.
     *
     * @param list<list<float>> $points
     */
    private function sample(array $points, int $cell, int $x, int $y): float
    {
        $i = intdiv($x, $cell);
        $j = intdiv($y, $cell);

        $tx = $this->ease(($x % $cell) / $cell);
        $ty = $this->ease(($y % $cell) / $cell);

        $top = $this->lerp($points[$j][$i], $points[$j][$i + 1], $tx);
        $bottom = $this->lerp($points[$j + 1][$i], $points[$j + 1][$i + 1], $tx);

        return $this->lerp($top, $bottom, $ty);
    }

    private function ease(float $t): float
    {
        return $t * $t * (3 - 2 * $t);
    }

    private function lerp(float $a, float $b, float $t): float
    {
        return $a + ($b - $a) * $t;
    }
}
