<?php

declare(strict_types=1);

namespace Map\Render;

use Map\Builder\MapBuilder;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Style\Style;

/**
 * Turns a tile into the way it is painted.
 *
 * Terrain is drawn as the *background* of the cell rather than as a coloured
 * character: solid areas read as ground at a glance, and the glyph stays
 * available for whatever stands on top of it. Grass is therefore a plain
 * space — its colour carries the whole information.
 *
 * Each terrain has several shades, picked from the tile coordinates. A single
 * flat colour looks like paint; a shade drawn at random on every frame would
 * make the map shimmer fifteen times a second. Hashing the coordinates gives
 * a stable grain for free, with no state to keep.
 *
 * True colour is not universal — Terminal.app still tops out at 256 — so a
 * sixteen colour fallback is kept. It cannot express shades and does not try.
 */
class TilePalette
{
    /** Number of variants per terrain. One draw feeds every table. */
    private const VARIANTS = 4;

    /** @var array<string, list<string>> */
    private const SHADES = [
        MapBuilder::HERBE => ['#3f6b36', '#48783d', '#375f30', '#436f39'],
        MapBuilder::ARBRE => ['#23461e', '#1d3c19', '#274c21', '#204219'],
        MapBuilder::EAU => ['#1f4f7a', '#265a8a', '#1a4468', '#22537f'],
    ];

    /** Flowers are not all the same colour, which is half of why meadows read well. */
    private const BLOOMS = ['#e8619d', '#f2d13c', '#e05c5c', '#d98cf0'];

    private const FOREST_GLYPH = '#74a862';
    private const PLAYER_BG = '#c0392b';
    private const PLAYER_FG = '#ffffff';

    /** @var array<string, Style> */
    private array $cache = [];

    public function __construct(private bool $trueColor = false)
    {
    }

    /**
     * True colour terminals advertise themselves through COLORTERM. Anything
     * silent is assumed to be limited to the ANSI palette.
     */
    public static function detect(): self
    {
        return new self(in_array(getenv('COLORTERM'), ['truecolor', '24bit'], true));
    }

    public function glyph(string $tile): string
    {
        return match ($tile) {
            MapBuilder::HERBE, MapBuilder::EAU => ' ',
            MapBuilder::ARBRE => '♣',
            MapBuilder::FLEUR => '✿',
            'P' => '■',
            default => $tile,
        };
    }

    public function style(string $tile, int $x, int $y): Style
    {
        $variant = $this->variant($x, $y);

        return $this->cache[$tile . ':' . $variant] ??= $this->build($tile, $variant);
    }

    private function build(string $tile, int $variant): Style
    {
        if (!$this->trueColor) {
            return $this->ansi($tile);
        }

        return match ($tile) {
            MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE => Style::default()
                ->bg($this->shade($tile, $variant))
                ->fg(RgbColor::fromHex(self::FOREST_GLYPH)),
            // A flower sits in the meadow, so it keeps the grass underneath.
            MapBuilder::FLEUR => Style::default()
                ->bg($this->shade(MapBuilder::HERBE, $variant))
                ->fg(RgbColor::fromHex(self::BLOOMS[$variant])),
            'P' => Style::default()
                ->bg(RgbColor::fromHex(self::PLAYER_BG))
                ->fg(RgbColor::fromHex(self::PLAYER_FG)),
            default => Style::default(),
        };
    }

    private function ansi(string $tile): Style
    {
        return match ($tile) {
            MapBuilder::HERBE => Style::default()->bg(AnsiColor::Green)->fg(AnsiColor::Green),
            MapBuilder::ARBRE => Style::default()->bg(AnsiColor::Green)->fg(AnsiColor::Black),
            MapBuilder::EAU => Style::default()->bg(AnsiColor::Blue)->fg(AnsiColor::Blue),
            MapBuilder::FLEUR => Style::default()->bg(AnsiColor::Green)->fg(AnsiColor::LightMagenta),
            'P' => Style::default()->bg(AnsiColor::Red)->fg(AnsiColor::White),
            default => Style::default(),
        };
    }

    private function shade(string $tile, int $variant): Color
    {
        $shades = self::SHADES[$tile] ?? self::SHADES[MapBuilder::HERBE];

        return RgbColor::fromHex($shades[$variant]);
    }

    /**
     * Stable pseudo-random pick for a tile: same coordinates, same variant,
     * frame after frame.
     *
     * The mixing matters. crc32 is linear, so on neighbouring coordinates its
     * low bits stay correlated and the map comes out woven with regular
     * diagonal stripes — more distracting than a flat colour. This is an
     * avalanche hash instead: one changed bit of input scrambles the output.
     * Everything is masked back to 32 bits because PHP turns an overflowing
     * integer into a float, which would break the bitwise steps.
     */
    private function variant(int $x, int $y): int
    {
        $hash = (($x * 0x27D4EB2D) ^ ($y * 0x165667B1)) & 0xFFFFFFFF;
        $hash ^= $hash >> 15;
        $hash = ($hash * 0x2545F491) & 0xFFFFFFFF;
        $hash ^= $hash >> 13;

        return $hash % self::VARIANTS;
    }
}
