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
 * **Water is the exception, and is the only thing here that moves.** It is
 * drawn from a swell — two travelling waves summed — read from a phase in
 * seconds of wall clock that `animate()` sets once per frame. Ground is
 * still; a lake that were would be the one thing on this map that looks
 * painted on.
 *
 * True colour is not universal, so a sixteen colour fallback is kept. It
 * cannot express shades and does not try. Which one is used comes from
 * `COLORTERM`, which is *inherited* and therefore wrong in both directions —
 * hence `--colours`, which settles it by hand rather than by guessing at the
 * far end of the pipe.
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
        // The still water, kept for the panel and for the sixteen colour
        // fallback. On the map itself the lake is drawn by swell() instead,
        // which is the one thing here that moves.
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
     * The trough and the crest of the swell, interpolated between rather than
     * stepped through.
     *
     * Everything else on this map picks one of four shades because its grain
     * is a hash and four draws are enough to break the flatness. A wave is
     * continuous, and quantising it to four steps draws contour lines instead
     * of water — the bands would be exactly what one sees.
     */
    private const SWELL_TROUGH = '#1a4468';

    private const SWELL_CREST = '#2a6396';

    /** Radians a second the swell travels. Slow: a lake is not a river. */
    private const SWELL_SPEED = 1.4;

    /**
     * Steps the swell is allowed to take between trough and crest.
     *
     * **This number is a bandwidth budget, not a taste.** php-tui sends the
     * terminal only the cells that changed since the last frame, so a
     * *continuous* colour guarantees that every cell of every lake changed:
     * measured, 93% of water tiles a frame, which came to 35 070 bytes of
     * escape sequences a frame — half a megabyte a second, forever. Quantised
     * to six levels a tile only speaks when the wave carries it over a step:
     * 8% of tiles, 4 980 bytes, 73 KB/s.
     *
     * That is not an optimisation, it is the difference between a terminal
     * that keeps up and one that does not — and a write to the tty *blocks*,
     * so a terminal that cannot swallow the frame stalls the simulation behind
     * it, not just the picture.
     *
     * The wave itself is unchanged: same crests, same drift. The slope becomes
     * a flight of plateaus, which on moving water reads as ripples, and is the
     * same vocabulary as the four hashed shades every other terrain uses.
     */
    private const SWELL_STEPS = 5;

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
     * The second colour is a *darker fur of the same animal*, never a cream or
     * a white. Two of them were, and the right half of the cat melted into the
     * cloud it was sitting on: `#ffe3d2` against a `#f4f7fb` cloud is no edge
     * at all, so what one saw was a red shape, a pale shape and some white,
     * rather than one cat. A marking has to stay darker than the sky it is
     * drawn against.
     *
     * @var list<array{glyph: string, color: string, patch: string}>
     */
    private const PLAYERS = [
        ['glyph' => '●', 'color' => '#ff5c5c', 'patch' => '#9e3535'],
        ['glyph' => '◆', 'color' => '#ffd24a', 'patch' => '#8a5a1c'],
        ['glyph' => '▲', 'color' => '#6ec1ff', 'patch' => '#2c5f8f'],
        ['glyph' => '★', 'color' => '#d98cf0', 'patch' => '#6b3a8a'],
    ];

    private const PLAYER_BG = '#20201c';

    /**
     * What every floating cat shares, whichever player it belongs to. Only the
     * coat tells them apart — eyes and muzzle at this size are two tiles and
     * three, and colouring those per cat would say nothing anyone could read.
     */
    /**
     * Amber, because the eye has to land on the bright fur and on the dark
     * marking alike — one tile has no room to be legible on only one of them.
     * It is also what a cat's eye actually is.
     */
    private const CAT_EYE = '#ffe98a';

    private const CAT_SNOUT = '#f2a6ad';

    /**
     * The line round the whole animal. Near black rather than a dark version
     * of the coat: the point is to separate the cat from *whatever* is behind
     * it, and the meadow, the lake and the wood are not the same colour.
     */
    private const CAT_OUTLINE = '#14110f';

    private const CLOUD = '#f4f7fb';

    private const CLOUD_SHADE = '#c2ccda';

    /** @var array<string, Style> */
    private array $cache = [];

    /**
     * Where the swell stands, in seconds.
     *
     * Seconds of wall clock, never ticks: an animation is a way of looking at
     * the simulation and not something the world does, exactly like the
     * camera. Read from the tick it would boil at x1000 and stop dead while
     * paused — which is how the game starts.
     */
    private float $phase = 0.0;

    /** @var array{0: RgbColor, 1: RgbColor}|null Swell ends, parsed once. */
    private ?array $swell = null;

    public function __construct(private bool $trueColor = false)
    {
    }

    /**
     * Move the water on. Called once per frame, so a whole frame is drawn at
     * a single instant — read per tile, the top of the screen would be older
     * than the bottom.
     */
    public function animate(float $seconds): void
    {
        $this->phase = $seconds;
    }

    /**
     * True colour terminals advertise themselves through COLORTERM. Anything
     * silent is assumed to be limited to the ANSI palette.
     */
    /**
     * How many colours the terminal can be given: 16, or all of them.
     *
     * **There is deliberately no 256 colour rung, and it was tried.** The
     * xterm cube steps each channel through 0, 95, 135, 175, 215, 255, and
     * this map's palette sits in the first gap: three of the four meadow
     * greens (`#3f6b36`, `#375f30`, `#436f39`) land on `#5f5f5f`, which is a
     * *grey*. All four greens of the wood collapse onto one entry, and the
     * thicket and the bramble both come out pure black. That is not a coarser
     * picture — the meadow stops meaning meadow, which is worse than the flat
     * sixteen colour map, where at least green stays green.
     *
     * **`COLORTERM` is inherited, so it is wrong in both directions.** It
     * describes the terminal that started the shell rather than the one
     * drawing the frame: exported from a profile, or carried across an ssh or
     * a tmux, it speaks for something else entirely. There was a table of
     * terminals to disbelieve here, holding Terminal.app on the grounds that
     * it has no 24 bit colour — measured on a real one, it has. Guessing at
     * the far end of the pipe is what `--colours` exists to stop.
     */
    public static function depth(): int
    {
        return in_array(getenv('COLORTERM'), ['truecolor', '24bit'], true) ? 16777216 : 16;
    }

    public static function detect(): self
    {
        return new self(self::depth() > 16);
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
        // escapes such a terminal cannot honour. It also means the lake is
        // still there: one blue is one blue, and a swell has nothing to move
        // through.
        if (!$this->trueColor) {
            return $this->ansiPixel($tile);
        }

        $variant = $this->variant($x, $y);
        $index = self::playerIndex($tile);

        if (null !== $index) {
            return RgbColor::fromHex(self::PLAYERS[$index % count(self::PLAYERS)]['color']);
        }

        return match ($tile) {
            MapBuilder::EAU => $this->swell($x, $y),
            MapBuilder::FLEUR => RgbColor::fromHex(self::BLOOMS[$variant]),
            MapBuilder::DIGITALE => RgbColor::fromHex(self::POISON),
            MapBuilder::RONCE => RgbColor::fromHex(self::THORN),
            MapBuilder::CHAMPIGNON => RgbColor::fromHex(self::MUSHROOM),
            default => $this->shade($tile, $variant),
        };
    }

    /**
     * The one terrain that moves.
     *
     * **Two waves rather than one.** A single travelling band is a ruler
     * sliding across the lake and its period is plain within a second; summed
     * at different angles, wavelengths and speeds, the crests meet somewhere
     * new each time and the water never quite repeats. It is the same problem
     * the hashed grain solves for the ground, and the same answer: what the
     * eye must not find is a period.
     *
     * The lake is drawn from world coordinates like everything else, so the
     * swell stays where it is while the view slides over it — panning must not
     * push the water along.
     */
    private function swell(int $x, int $y): Color
    {
        $time = $this->phase * self::SWELL_SPEED;

        $height = sin(($x + $y) * 0.45 - $time)
            + sin($x * 0.31 - $y * 0.57 + $time * 0.62);

        $this->swell ??= [
            RgbColor::fromHex(self::SWELL_TROUGH),
            RgbColor::fromHex(self::SWELL_CREST),
        ];

        [$trough, $crest] = $this->swell;
        $ratio = round(($height + 2.0) / 4.0 * self::SWELL_STEPS) / self::SWELL_STEPS;

        return RgbColor::fromRgb(
            (int) round($trough->r + ($crest->r - $trough->r) * $ratio),
            (int) round($trough->g + ($crest->g - $trough->g) * $ratio),
            (int) round($trough->b + ($crest->b - $trough->b) * $ratio),
        );
    }

    /**
     * A tile as one of the sixteen, for the half block view.
     *
     * **It cannot be read off {@see ansi()}, and reading it off the background
     * was a bug that emptied the map.** That method describes a *cell*: a
     * flower is a magenta character standing on a green background, because
     * the terrain is the background and the plant is the glyph. Take the
     * background of it and a flower, a foxglove and a bramble all come back
     * green — the same green as the meadow they grow in. Over four thousand
     * of them vanished into the grass, and the sixteen colour map came out
     * looking like a lawn.
     *
     * Here there is no cell to split: a tile is half of one and has a single
     * colour, so what matters is *what is there* rather than what it stands
     * on. Sixteen colours barely stretch to it — the thicket has to borrow the
     * grey of the rock, and the bramble takes red for want of a dark left.
     */
    private function ansiPixel(string $tile): AnsiColor
    {
        $index = self::playerIndex($tile);

        if (null !== $index) {
            return match ($index % 4) {
                0 => AnsiColor::LightRed,
                1 => AnsiColor::LightYellow,
                2 => AnsiColor::LightBlue,
                default => AnsiColor::LightMagenta,
            };
        }

        return match ($tile) {
            MapBuilder::HERBE => AnsiColor::LightGreen,
            MapBuilder::ARBRE => AnsiColor::Green,
            MapBuilder::FOURRE => AnsiColor::DarkGray,
            MapBuilder::EAU => AnsiColor::Blue,
            MapBuilder::TROU => AnsiColor::Black,
            // The plants, which the background hid. Each one has to be a
            // colour of its own or the thing a cat has to learn is invisible.
            MapBuilder::FLEUR => AnsiColor::Magenta,
            MapBuilder::DIGITALE => AnsiColor::LightCyan,
            MapBuilder::RONCE => AnsiColor::Red,
            MapBuilder::CHAMPIGNON => AnsiColor::White,
            MapBuilder::CAVERNE => AnsiColor::Yellow,
            MapBuilder::ROCHE => AnsiColor::DarkGray,
            MapBuilder::GALERIE => AnsiColor::Gray,
            default => AnsiColor::LightGreen,
        };
    }

    public function sightEdgeColour(): Color
    {
        return RgbColor::fromHex(self::SIGHT_EDGE);
    }

    /**
     * Everything {@see CatSprite} needs to paint one floating cat.
     *
     * The coat starts from the colour the cat is already drawn with on the
     * map, so the marker and the dot it hangs over are visibly the same
     * animal — read from two tables they would drift apart the first time one
     * of them was edited. The second colour is what makes it a coat rather
     * than a silhouette.
     *
     * @return array<string, Color>
     */
    public function catColours(int $player): array
    {
        $cat = self::PLAYERS[$player % count(self::PLAYERS)];

        if (!$this->trueColor) {
            // Sixteen colours have nothing to spare for a second shade of the
            // same fur, so the patch takes white: the split still reads, and
            // nothing pretends to a subtlety the terminal cannot draw.
            return [
                'coat' => $this->ansi((string) (($player % 9) + 1))->fg ?? AnsiColor::White,
                'patch' => AnsiColor::DarkGray,
                'eye' => AnsiColor::LightYellow,
                'snout' => AnsiColor::LightRed,
                'cloud' => AnsiColor::White,
                'shade' => AnsiColor::Gray,
                'outline' => AnsiColor::Black,
            ];
        }

        return [
            'coat' => RgbColor::fromHex($cat['color']),
            'patch' => RgbColor::fromHex($cat['patch']),
            'eye' => RgbColor::fromHex(self::CAT_EYE),
            'snout' => RgbColor::fromHex(self::CAT_SNOUT),
            'cloud' => RgbColor::fromHex(self::CLOUD),
            'shade' => RgbColor::fromHex(self::CLOUD_SHADE),
            'outline' => RgbColor::fromHex(self::CAT_OUTLINE),
        ];
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
