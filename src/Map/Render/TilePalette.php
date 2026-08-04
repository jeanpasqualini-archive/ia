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
 * **The map is drawn in colour alone.** A tile is half a cell — two of them
 * share one character, an upper half block whose foreground is the tile above
 * and whose background the tile below — and a character cannot be cut in
 * half, so nothing on the ground has a shape any more. That is the trade the
 * resolution is bought with, and it cost nothing to make: the blooms, the
 * thorns and the cats were all coloured before they were ever shaped.
 *
 * Half a cell is square, so lakes stay round. The two column tile this
 * replaced was square for the same reason — a terminal cell being about twice
 * as tall as it is wide — and it fitted a quarter as many tiles on screen.
 *
 * Shapes remain in the side panel, where text flows, and the rule that governs
 * them still holds there: every character must be one column wide, emoji
 * excluded. php-tui's paragraph rendering stores a grapheme per cell without
 * accounting for its display width, so a two column emoji occupies one cell
 * and two columns, and the block border lands one column off.
 */
class TilePalette
{
    /** Number of variants per terrain. One draw feeds every table. */
    private const VARIANTS = 4;

    /** @var array<string, list<string>> */
    private const SHADES = [
        MapBuilder::HERBE => ['#3f6b36', '#48783d', '#375f30', '#436f39'],
        MapBuilder::ARBRE => ['#23461e', '#1d3c19', '#274c21', '#204219'],
        // Deeper and colder than open forest, so the heart of a wood reads as
        // somewhere one does not walk through lightly.
        MapBuilder::FOURRE => ['#12291a', '#0e2215', '#163020', '#102616'],
        MapBuilder::EAU => ['#1f4f7a', '#265a8a', '#1a4468', '#22537f'],
        // Nearly black: a hole is an absence, and it should read as one next
        // to ground that is merely dark.
        MapBuilder::TROU => ['#17120f', '#1d1713', '#120e0c', '#1a1511'],
        // Underground. Rock is the wall one cannot pass, the gallery is the
        // floor one walks on, and the cavern mouth is lit from the other side.
        MapBuilder::ROCHE => ['#2b2724', '#231f1d', '#332e2a', '#282320'],
        MapBuilder::GALERIE => ['#4a423a', '#544b42', '#443c35', '#4f463e'],
        MapBuilder::CAVERNE => ['#6e5a33', '#7a6439', '#63512e', '#755f36'],
    ];

    /** Flowers are not all the same colour, which is half of why meadows read well. */
    private const BLOOMS = ['#e8619d', '#f2d13c', '#e05c5c', '#d98cf0'];

    /**
     * Brambles were painted as their own brown ground at first, and read as
     * bare earth — a path, if anything, rather than a thing that stings. They
     * are not a terrain: they are a plant standing on the meadow, exactly
     * like a flower, so they are drawn the same way. The ground stays green
     * and the thorn is a dark tangle on it.
     */
    private const THORN = '#2f2119';

    /**
     * The foxglove. A cold colour among the warm blooms, because the player
     * has to be able to tell them apart at a glance — the cat is the one
     * meant to find out the hard way.
     */
    private const POISON = '#8fd0e8';

    /** What grows in the dark, pale for want of light. */
    private const MUSHROOM = '#e8d9b0';

    /**
     * The far edge of what a cat can see.
     *
     * One flat colour whatever is underneath, because it is an overlay and
     * not a terrain: it has to read as a line drawn over the ground rather
     * than as another kind of ground.
     */
    private const SIGHT_EDGE = '#b39a4d';

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

    /**
     * Players are stamped on their own layer as 1..9, so each one keeps its
     * own colour instead of every cat being an identical letter.
     */
    public static function playerIndex(string $tile): ?int
    {
        return 1 === preg_match('/^[1-9]$/', $tile) ? (int) $tile - 1 : null;
    }

    /**
     * The shape identifying a player, used on the map and in the panel alike
     * so the two read as the same cat.
     */
    public static function playerMarker(int $index): string
    {
        return self::PLAYERS[$index % count(self::PLAYERS)]['glyph'];
    }

    /**
     * Unlike the terrain, this has no shades: it is a boundary, and a
     * boundary that shimmered would be harder to follow, not prettier. The
     * sixteen colour fallback gets yellow, which is the one thing it can say
     * here that no terrain already says.
     */
    private function sightEdgeStyle(): Style
    {
        return $this->cache['sight'] ??= $this->trueColor
            ? Style::default()->bg(RgbColor::fromHex(self::SIGHT_EDGE))
            : Style::default()->bg(AnsiColor::Yellow)->fg(AnsiColor::Yellow);
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
            MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE,
            MapBuilder::TROU, MapBuilder::FOURRE,
            MapBuilder::ROCHE, MapBuilder::GALERIE => Style::default()
                ->bg($this->shade($tile, $variant)),
            MapBuilder::CAVERNE => Style::default()
                ->bg($this->shade(MapBuilder::CAVERNE, $variant))
                ->fg(RgbColor::fromHex('#1a1512')),
            MapBuilder::CHAMPIGNON => Style::default()
                ->bg($this->shade(MapBuilder::GALERIE, $variant))
                ->fg(RgbColor::fromHex(self::MUSHROOM)),
            MapBuilder::DIGITALE => Style::default()
                ->bg($this->shade(MapBuilder::HERBE, $variant))
                ->fg(RgbColor::fromHex(self::POISON)),
            // Standing in the meadow, like the flower it grows beside.
            MapBuilder::RONCE => Style::default()
                ->bg($this->shade(MapBuilder::HERBE, $variant))
                ->fg(RgbColor::fromHex(self::THORN)),
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
            MapBuilder::TROU => Style::default()->bg(AnsiColor::Black)->fg(AnsiColor::Black),
            MapBuilder::FOURRE => Style::default()->bg(AnsiColor::DarkGray)->fg(AnsiColor::DarkGray),
            MapBuilder::ROCHE => Style::default()->bg(AnsiColor::DarkGray)->fg(AnsiColor::DarkGray),
            MapBuilder::GALERIE => Style::default()->bg(AnsiColor::Gray)->fg(AnsiColor::Gray),
            MapBuilder::CAVERNE => Style::default()->bg(AnsiColor::Yellow)->fg(AnsiColor::Black),
            MapBuilder::CHAMPIGNON => Style::default()->bg(AnsiColor::Gray)->fg(AnsiColor::White),
            MapBuilder::RONCE => Style::default()->bg(AnsiColor::LightGreen)->fg(AnsiColor::Black),
            MapBuilder::FLEUR => Style::default()->bg(AnsiColor::LightGreen)->fg(AnsiColor::Magenta),
            MapBuilder::DIGITALE => Style::default()->bg(AnsiColor::LightGreen)->fg(AnsiColor::LightCyan),
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

    /**
     * A tile as a single colour, for the high resolution view.
     *
     * There, a tile is half a cell: two of them share one character, drawn as
     * an upper half block with the top tile as foreground and the bottom one
     * as background. No glyph can survive that — a character occupies the
     * whole cell — so what stands on the ground has to speak through its
     * colour instead. Everything already had one, since the flowers, the
     * thorns and the cats were coloured before they were shaped.
     */
    public function pixel(string $tile, int $x, int $y): Color
    {
        // Sixteen colours can still stack two tiles in a cell — a foreground
        // and a background is all it takes — they simply have sixteen answers
        // rather than shades. Handing back a true colour here would emit
        // escapes such a terminal cannot honour.
        if (!$this->trueColor) {
            return $this->ansi($tile)->bg ?? AnsiColor::Black;
        }

        $variant = $this->variant($x, $y);
        $index = self::playerIndex($tile);

        if (null !== $index) {
            return RgbColor::fromHex(self::PLAYERS[$index % count(self::PLAYERS)]['color']);
        }

        return match ($tile) {
            MapBuilder::FLEUR => RgbColor::fromHex(self::BLOOMS[$variant]),
            MapBuilder::DIGITALE => RgbColor::fromHex(self::POISON),
            MapBuilder::RONCE => RgbColor::fromHex(self::THORN),
            MapBuilder::CHAMPIGNON => RgbColor::fromHex(self::MUSHROOM),
            default => $this->shade($tile, $variant),
        };
    }

    public function sightEdgeColour(): Color
    {
        return RgbColor::fromHex(self::SIGHT_EDGE);
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
