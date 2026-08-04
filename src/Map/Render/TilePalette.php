<?php

declare(strict_types=1);

namespace Map\Render;

use Map\Builder\MapBuilder;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Style\Style;
use PhpTui\Tui\Text\Span;

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
 *
 * A tile is painted on TWO columns. A terminal cell is about twice as tall as
 * it is wide, so one cell per tile squashed the whole map vertically: round
 * lakes came out as ovals and a diagonal step looked like 27 degrees instead
 * of 45. Two columns make a tile square.
 *
 * Every character used here is one column wide, emoji included — that is, they
 * are excluded. php-tui's paragraph rendering stores a grapheme per cell
 * without accounting for its display width, so a two column emoji occupies one
 * cell and two columns: everything after it on that row shifts right and the
 * block border lands one column off. This holds in the side panels too, not
 * just on the grid.
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

    /**
     * One glyph and one colour per player.
     *
     * Single column characters on purpose: an emoji is two columns wide, so on
     * a grid of one-column tiles it would eat its neighbour and shift the rest
     * of the row. Cats get emoji in the side panel instead, where text flows.
     *
     * @var list<array{glyph: string, color: string}>
     */
    private const PLAYERS = [
        ['glyph' => '●', 'color' => '#ff5c5c'],
        ['glyph' => '◆', 'color' => '#ffd24a'],
        ['glyph' => '▲', 'color' => '#6ec1ff'],
        ['glyph' => '★', 'color' => '#d98cf0'],
    ];

    private const PLAYER_BG = '#20201c';

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
        $player = self::playerIndex($tile);

        if (null !== $player) {
            return self::PLAYERS[$player % count(self::PLAYERS)]['glyph'];
        }

        return match ($tile) {
            // Every terrain is ground, and ground is a colour. The forest used
            // to be the exception, drawn as a club suit — which macOS renders
            // from the colour emoji font, two columns wide, shifting the whole
            // row. mb_strwidth answers 1 for it, so the frame width test never
            // saw it: Unicode says narrow, the font substitution says
            // otherwise. A canopy is better read as a dark mass anyway.
            MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE => ' ',
            MapBuilder::FLEUR => '✿',
            default => $tile,
        };
    }

    /**
     * Players are stamped on their own layer as 1..9, so each one keeps its
     * own colour instead of every cat being an identical letter.
     */
    public static function playerIndex(string $tile): ?int
    {
        return 1 === preg_match('/^[1-9]$/', $tile) ? (int) $tile - 1 : null;
    }

    /** Columns per tile. */
    public const TILE_WIDTH = 2;

    /**
     * The shape identifying a player, used on the map and in the panel alike
     * so the two read as the same cat.
     */
    public static function playerMarker(int $index): string
    {
        return self::PLAYERS[$index % count(self::PLAYERS)]['glyph'];
    }

    /**
     * The tile as it is drawn: always exactly TILE_WIDTH columns.
     */
    public function cell(string $tile, int $x, int $y): Span
    {
        $style = $this->style($tile, $x, $y);
        $index = self::playerIndex($tile);

        if (null !== $index) {
            return Span::styled(self::playerMarker($index) . ' ', $style);
        }

        $glyph = $this->glyph($tile);

        if (' ' === $glyph) {
            return Span::styled('  ', $style);
        }

        // Flowers lean left or right depending on the tile, which keeps a bed
        // of them from looking like a printed grid. They are the only thing
        // still drawn as a character on the ground.
        return Span::styled(
            0 === $this->variant($x, $y) % 2 ? $glyph . ' ' : ' ' . $glyph,
            $style
        );
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
            // Nothing is drawn on top of these, so they carry a background
            // and no foreground at all.
            MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE => Style::default()
                ->bg($this->shade($tile, $variant)),
            // A flower sits in the meadow, so it keeps the grass underneath.
            MapBuilder::FLEUR => Style::default()
                ->bg($this->shade(MapBuilder::HERBE, $variant))
                ->fg(RgbColor::fromHex(self::BLOOMS[$variant])),
            default => $this->playerStyle($tile) ?? Style::default(),
        };
    }

    private function playerStyle(string $tile): ?Style
    {
        $index = self::playerIndex($tile);

        if (null === $index) {
            return null;
        }

        return Style::default()
            ->bg(RgbColor::fromHex(self::PLAYER_BG))
            ->fg(RgbColor::fromHex(self::PLAYERS[$index % count(self::PLAYERS)]['color']));
    }

    private function ansi(string $tile): Style
    {
        return match ($tile) {
            // Sixteen colours have no shades to spare, and the forest is no
            // longer marked by a glyph, so the two greens have to do the work
            // on their own: the meadow takes the light one, the wood the dark.
            MapBuilder::HERBE => Style::default()->bg(AnsiColor::LightGreen)->fg(AnsiColor::LightGreen),
            MapBuilder::ARBRE => Style::default()->bg(AnsiColor::Green)->fg(AnsiColor::Green),
            MapBuilder::EAU => Style::default()->bg(AnsiColor::Blue)->fg(AnsiColor::Blue),
            MapBuilder::FLEUR => Style::default()->bg(AnsiColor::LightGreen)->fg(AnsiColor::Magenta),
            default => null === self::playerIndex($tile)
                ? Style::default()
                : Style::default()->bg(AnsiColor::Black)->fg(match (self::playerIndex($tile) % 4) {
                    0 => AnsiColor::LightRed,
                    1 => AnsiColor::LightYellow,
                    2 => AnsiColor::LightBlue,
                    default => AnsiColor::LightMagenta,
                }),
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
