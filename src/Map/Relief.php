<?php

declare(strict_types=1);

namespace Map;

/**
 * The shape of the ground.
 *
 * **This is not new information.** `TerrainMapProvider` already builds a
 * continuous elevation field and cuts the terrain out of it by quantiles — the
 * water is the lowest fifth, the thicket the highest ground of the highest —
 * and then discards it, having only ever needed to know which side of a
 * threshold each tile fell on. Everything here is that field, kept instead of
 * thrown away. Terrain and relief therefore agree by construction: no lake
 * sits on a hilltop, because being low is what made it a lake.
 *
 * Inventing a height from the terrain type instead is what `demo/relief.php`
 * does, and it gives a wood the flat top of a mesa and a lake a uniform depth,
 * and has to be smoothed three times to stop the map reading as a staircase.
 * All of that is a worse copy of a field that already exists.
 *
 * **It must never be reachable from `World`.** A snapshot costs eight
 * kilobytes today against the megabyte it used to; forty thousand heights in
 * it would undo that work in one line. So it belongs to the renderer, is
 * regenerated with the map from the same seed, and is stored as one byte per
 * tile in a flat array — the same reason `PathFinder` works on a flat grid of
 * integers rather than on objects.
 */
final class Relief
{
    /** Steps between the lowest point of the map and the highest. */
    private const LEVELS = 255;

    /**
     * How far from flat a *typical* slope should look.
     *
     * Measured against the map's own average slope rather than multiplied by a
     * constant: the noise is smooth over some twenty tiles, so two neighbours
     * differ by a handful of steps out of two hundred and fifty five, and a
     * fixed multiplier would have to be retuned the day an octave is added.
     */
    private const TYPICAL = 0.22;

    private const DARKEST = 0.6;

    private const BRIGHTEST = 1.4;

    /**
     * @param list<int> $heights row major, 0..255
     */
    private function __construct(
        private array $heights,
        private int $width,
        private int $height,
        private float $scale,
    ) {
    }

    /**
     * @param list<list<float>> $field raw noise, in whatever range it came out
     */
    public static function fromField(array $field): self
    {
        $height = count($field);
        $width = count($field[0] ?? []);

        $low = PHP_FLOAT_MAX;
        $high = -PHP_FLOAT_MAX;

        foreach ($field as $row) {
            foreach ($row as $value) {
                $low = min($low, $value);
                $high = max($high, $value);
            }
        }

        $span = $high - $low;
        $heights = [];

        foreach ($field as $row) {
            foreach ($row as $value) {
                $heights[] = $span > 0.0 ? (int) round(($value - $low) / $span * self::LEVELS) : 0;
            }
        }

        return new self($heights, $width, $height, self::scaleFor($heights, $width, $height));
    }

    /** How high a tile stands, between 0 and 255. */
    public function heightAt(int $x, int $y): int
    {
        $x = max(0, min($this->width - 1, $x));
        $y = max(0, min($this->height - 1, $y));

        return $this->heights[$y * $this->width + $x] ?? 0;
    }

    /**
     * How steeply the ground falls away, as a factor to multiply a colour by.
     *
     * Lit from the north west, which is where every relief map ever printed
     * puts the sun: a slope facing up and left is brighter than the flat
     * around it, one facing down and right darker. Backwards, the hills read
     * as holes — instantly, and with no way to say why.
     */
    public function light(int $x, int $y): float
    {
        $slope = self::slopeAt($this->heights, $this->width, $this->height, $x, $y);

        return max(self::DARKEST, min(self::BRIGHTEST, 1.0 + $slope * $this->scale));
    }

    /**
     * @param list<int> $heights
     */
    private static function scaleFor(array $heights, int $width, int $height): float
    {
        $total = 0.0;
        $count = 0;

        // Sampled: this only needs the order of magnitude of a slope, and
        // every sixteenth tile gives it to three decimals for a sixteenth of
        // the work.
        for ($y = 1; $y < $height - 1; $y += 4) {
            for ($x = 1; $x < $width - 1; $x += 4) {
                $total += abs(self::slopeAt($heights, $width, $height, $x, $y));
                ++$count;
            }
        }

        return self::TYPICAL / max($count > 0 ? $total / $count : 0.0, 0.001);
    }

    /**
     * @param list<int> $heights
     */
    private static function slopeAt(array $heights, int $width, int $height, int $x, int $y): float
    {
        $at = static function (int $x, int $y) use ($heights, $width, $height): int {
            // Clamped at the edges rather than wrapped: a map does not come
            // round, and a wrapped read would draw a cliff along all four
            // borders out of nothing.
            return $heights[max(0, min($height - 1, $y)) * $width + max(0, min($width - 1, $x))] ?? 0;
        };

        return ($at($x - 1, $y) - $at($x + 1, $y)) + ($at($x, $y - 1) - $at($x, $y + 1));
    }
}
