<?php

declare(strict_types=1);

namespace Map\Render;

use Map\Builder\MapBuilder;
use Map\Relief;
use Runtime\Camera;

/**
 * The world seen from the side, as a lattice of diamonds.
 *
 * **The map view and this one are two ways of looking, not two worlds.** They
 * share the camera, so panning and zooming land in the same place in both, and
 * they share {@see TilePalette}, so the meadow keeps its hashed grain and the
 * lake its animated swell without a second table of colours existing anywhere.
 * What this adds is shape: a tree that is a trunk and a canopy where the map
 * has a dark green square, a foxglove standing taller than the blooms around
 * it, a cat seen from the side rather than as one coloured tile.
 *
 * **Composed at half the size of the area it fills and blown up by the GPU.**
 * Measured: a whole screen of ground costs 15 ms a frame at full resolution
 * against 4.8 ms at half, and the objects on top still have to be paid for.
 * The chunky pixel it produces is the same one the map view already shows, so
 * the two modes look like the same game rather than like two programs.
 *
 * **Drawn back to front, by depth.** A diamond lattice has no row order that
 * is also a depth order — screen height depends on the *sum* of the two world
 * axes — so the loop walks that sum. Iterating rows instead puts trees in
 * front of the cats standing before them, which is the one mistake in an
 * isometric view that everybody makes once.
 */
final class IsoView
{
    /** Half a tile, which is one step of the lattice on each axis. */
    private const STEP_X = IsoSprites::TILE_WIDTH / 2;
    private const STEP_Y = IsoSprites::TILE_HEIGHT / 2;

    private const SKY = 0xFF0E1418;
    private const OUTLINE = 0xFF14110F;
    private const TRUNK = 0xFF5A3A22;
    private const STEM = 0xFF3F6B36;
    private const MUSHROOM_STEM = 0xFFC9BB94;
    private const FOAM = 0xFFD8ECF6;

    /** How high the swell has to stand before it breaks. */
    private const FOAM_CREST = 0.72;
    private const CAVERN_DARK = 0xFF120E0C;

    /**
     * How many pixels the highest ground stands above the lowest.
     *
     * Small on purpose. The first relief demo raised a wood ten rows above the
     * meadow and the picture *shredded* — one read a torn map rather than a
     * hill, because neighbouring tiles no longer looked like neighbours. The
     * illusion wants the smallest displacement that still says which way is
     * up, and a diamond is only eight pixels tall.
     */
    private const MAX_LIFT = 10;

    public function __construct(
        private TilePalette $palette,
        private ?Relief $relief = null,
    ) {
    }

    public function setRelief(?Relief $relief): void
    {
        $this->relief = $relief;
    }

    /**
     * How many cells of world the lattice covers to fill a given area.
     *
     * Both axes run diagonally, so a rectangle of screen is a diamond of
     * world: covering the height takes the *sum* of the two, and the corners
     * fall outside and are clipped. Reported as a view size so the camera can
     * clamp against it like any other.
     *
     * @return array{x: int, y: int}
     */
    public static function coverage(int $width, int $height): array
    {
        // Solved rather than guessed. A screen position comes from the sum
        // and the difference of the two axes, so the far corner needs
        // (depth + halfWidth) / 2 of each — counting the depth alone gives a
        // lattice that narrows towards the bottom exactly as it does towards
        // the top, and leaves the two lower corners bare.
        $down = ($height + self::overhang($width)) / self::STEP_Y;
        $across = $width / (2 * self::STEP_X);
        $side = (int) ceil(($down + $across) / 2) + 2;

        return ['x' => max(4, $side), 'y' => max(4, $side)];
    }

    /**
     * How far above the screen the lattice has to start. Nothing to do with
     * the ground's own height, which is `lift()`.
     *
     * A diamond lattice fans out from a point, so drawn from the top of the
     * area it leaves the two upper corners bare — the first version did
     * exactly that and half the screen was sky. The rows have to begin above
     * the view and be clipped: the width is only fully covered once the
     * lattice has spread half a screen, which takes width / (2 * STEP_X)
     * steps of STEP_Y each.
     */
    private static function overhang(int $width): int
    {
        return intdiv($width * self::STEP_Y, 2 * self::STEP_X);
    }

    /**
     * @param array<int, array<int, string>> $map
     * @param array<int, array{coat: int, patch: int, eye: int, outline: int}> $cats
     *        keyed by "worldX;worldY"
     */
    public function paint(array $map, Camera $camera, int $width, int $height, array $cats = []): Pixels
    {
        $pixels = new Pixels($width, $height, self::SKY);

        $worldHeight = count($map);
        $worldWidth = count($map[0] ?? []);
        $view = self::coverage($width, $height);

        $camera->clamp($worldWidth, $worldHeight, $view['x'], $view['y']);

        $scale = $camera->scale();
        $originX = $camera->x();
        $originY = $camera->y();

        // The lattice runs from the top corner of the screen, so column zero
        // sits at the far right of the world shown and the offset is what
        // brings the leftmost diamond back on screen.
        $offsetX = intdiv($width, 2) - self::STEP_X;
        $offsetY = -self::overhang($width);

        $diamond = IsoSprites::diamond();
        $columns = $view['x'];
        $rows = $view['y'];

        for ($depth = 0; $depth < $columns + $rows - 1; $depth++) {
            $screenY = $depth * self::STEP_Y + $offsetY;

            if ($screenY > $height) {
                break;
            }

            // A sprite is taller than its tile, so a row whose ground is just
            // off the top can still have a tree reaching onto the screen.
            if ($screenY < -IsoSprites::TILE_HEIGHT * 3) {
                continue;
            }

            for ($column = max(0, $depth - $rows + 1); $column <= min($columns - 1, $depth); $column++) {
                $row = $depth - $column;

                $worldX = $originX + $column * $scale;
                $worldY = $originY + $row * $scale;

                if ($worldX >= $worldWidth || $worldY >= $worldHeight) {
                    continue;
                }

                $screenX = ($column - $row) * self::STEP_X + $offsetX;

                if ($screenX + IsoSprites::TILE_WIDTH < 0 || $screenX > $width) {
                    continue;
                }

                $tile = $map[$worldY][$worldX] ?? MapBuilder::HERBE;
                $lift = $this->lift($tile, $worldX, $worldY);

                // The ground is what the tile *stands on*, never the tile's
                // own colour: a flower is a plant in the meadow, so its
                // diamond is grass and the bloom is what is drawn on it.
                // A local, and never `$screenY` itself: that one is computed
                // once for the whole depth, and subtracting from it inside
                // the column loop lifted each tile by the sum of the heights
                // of every tile drawn before it on the same row. The
                // landscape drifted upwards across each row and tore open
                // behind itself, which is exactly what it looked like.
                $top = $screenY - $lift;

                $this->ground(
                    $pixels,
                    $screenX,
                    $top,
                    $diamond,
                    TilePalette::groundFor($tile),
                    $worldX,
                    $worldY,
                    $lift
                );

                $cat = $cats[$worldX . ';' . $worldY] ?? null;

                if (null !== $cat) {
                    $this->stamp($pixels, IsoSprites::cat(), $screenX, $top, $this->catRoles($cat));

                    continue;
                }

                $standing = IsoSprites::standing($tile);

                if (null !== $standing) {
                    $this->stamp($pixels, $standing, $screenX, $top, $this->roles($tile, $worldX, $worldY));

                    continue;
                }

                // The lake breaks where the swell stands highest, on the same
                // wave that gives it its colour rather than on a second one.
                if (MapBuilder::EAU === $tile) {
                    if ($this->palette->swellLevel($worldX, $worldY) > self::FOAM_CREST) {
                        $this->stamp($pixels, IsoSprites::foam(), $screenX, $top, ['w' => self::FOAM]);
                    }

                    continue;
                }

                if (MapBuilder::HERBE === $tile) {
                    $tuft = IsoSprites::tuft(self::grain($worldX, $worldY));

                    if (null !== $tuft) {
                        $this->stamp($pixels, $tuft, $screenX, $top, [
                            'o' => self::OUTLINE,
                            'g' => self::shade($this->palette->packed($tile, $worldX, $worldY), 1.45),
                        ]);
                    }
                }
            }
        }

        return $pixels;
    }

    /**
     * The diamond of ground, lit on its upper half and shaded on its lower.
     *
     * One flat colour makes a lattice of squares seen edge on rather than a
     * landscape: the eye needs the two faces to read a tile as a solid thing.
     * The colour itself comes from the palette, so this is the same meadow as
     * in the map view, grain and all.
     *
     * @param list<array{int, int}> $diamond
     */
    private function ground(
        Pixels $pixels,
        int $x,
        int $y,
        array $diamond,
        string $tile,
        int $worldX,
        int $worldY,
        int $lift = 0,
    ): void {
        $colour = $this->palette->packed($tile, $worldX, $worldY);
        $lit = self::shade($colour, 1.12);
        $dark = self::shade($colour, 0.78);

        // The side of the block, drawn first and always the full lift: what
        // of it is hidden by the tile in front is hidden by the painter, and
        // what shows is exactly the ground that steps down. Without it a
        // raised tile is a diamond floating over a hole.
        if ($lift > 0) {
            // **The silhouette extruded straight down, and never tapered.**
            // The first version narrowed the side by a pixel a row, which
            // finishes in a point: every raised tile then left two black
            // wedges open at its corners, and the whole landscape came out
            // torn. A block's side is the shape above it dropped vertically —
            // column by column, from the lowest row of the diamond that covers
            // it, down by exactly the lift, which lands back on the plane an
            // unraised tile would have sat on. That is what closes the gaps:
            // every tile reaches the same floor whatever its height.
            $left = self::shade($colour, 0.5);
            $right = self::shade($colour, 0.36);

            foreach (self::silhouette($diamond) as $column => $bottom) {
                $pixels->rect(
                    $x + $column,
                    $y + $bottom + 1,
                    1,
                    $lift,
                    $column < IsoSprites::TILE_WIDTH / 2 ? $left : $right
                );
            }
        }

        foreach ($diamond as $row => [$left, $width]) {
            $pixels->rect(
                $x + $left,
                $y + $row,
                $width,
                1,
                $row < IsoSprites::TILE_HEIGHT / 2 ? $lit : $dark
            );
        }
    }

    /**
     * The lowest row of the diamond covering each column — its underside.
     *
     * Computed once and kept: the shape never changes, and it is asked for on
     * every raised tile of every frame.
     *
     * @param list<array{int, int}> $diamond
     *
     * @return array<int, int>
     */
    private static function silhouette(array $diamond): array
    {
        static $cache = null;

        if (null !== $cache) {
            return $cache;
        }

        $bottom = [];

        foreach ($diamond as $row => [$left, $width]) {
            for ($column = $left; $column < $left + $width; $column++) {
                $bottom[$column] = $row;
            }
        }

        return $cache = $bottom;
    }

    /**
     * How far a tile stands above the lowest ground.
     *
     * **Water is flat, whatever the field says underneath it.** The elevation
     * carries on below the water line — being low is what made it a lake — so
     * lifting a lake by it would draw the bottom of the lake as though that
     * were its surface, and a bay would come out with a hillside in it. A
     * surface is a surface.
     */
    private function lift(string $tile, int $worldX, int $worldY): int
    {
        if (null === $this->relief || MapBuilder::EAU === $tile) {
            return 0;
        }

        return (int) round($this->relief->heightAt($worldX, $worldY) * self::MAX_LIFT / 255);
    }

    /**
     * Put a sprite down so it stands on its tile.
     *
     * The anchor is the foot of the drawing, and the foot goes on the *middle*
     * of the diamond rather than on its top corner — anchored anywhere else a
     * tree stands half a tile up the hill from the ground it grows in.
     *
     * @param array{rows: list<string>, anchor: array{int, int}} $sprite
     * @param array<string, int> $roles
     */
    private function stamp(Pixels $pixels, array $sprite, int $x, int $y, array $roles): void
    {
        [$anchorX, $anchorY] = $sprite['anchor'];
        $left = $x + intdiv(IsoSprites::TILE_WIDTH, 2) - $anchorX;
        $top = $y + intdiv(IsoSprites::TILE_HEIGHT, 2) - $anchorY;

        foreach ($sprite['rows'] as $row => $line) {
            foreach (str_split($line) as $column => $char) {
                if ('.' === $char) {
                    continue;
                }

                $colour = $roles[$char] ?? null;

                if (null !== $colour) {
                    $pixels->set($left + $column, $top + $row, $colour);
                }
            }
        }
    }

    /**
     * What each letter of a sprite is painted with.
     *
     * Read from the palette wherever the map already has an answer — a bloom
     * keeps the colour it has as a square, so the foxglove is still the cold
     * one among the warm and a cat can still be wrong about it.
     *
     * @return array<string, int>
     */
    private function roles(string $tile, int $worldX, int $worldY): array
    {
        $own = $this->palette->packed($tile, $worldX, $worldY);

        return [
            'o' => self::OUTLINE,
            'F' => $own,
            'f' => self::shade($own, 1.35),
            'T' => self::TRUNK,
            'B' => $own,
            'S' => self::STEM,
            'R' => $own,
            'M' => $own,
            'm' => self::MUSHROOM_STEM,
            'K' => $own,
            'd' => self::CAVERN_DARK,
        ];
    }

    /**
     * @param array{coat: int, patch: int, eye: int, outline: int} $cat
     *
     * @return array<string, int>
     */
    private function catRoles(array $cat): array
    {
        return [
            'o' => $cat['outline'],
            'B' => $cat['coat'],
            'P' => $cat['patch'],
            'E' => $cat['eye'],
        ];
    }

    /**
     * The same avalanche hash the palette grains the ground with, so a tuft
     * keeps its place and its shape frame after frame — drawn afresh each
     * time, the meadow would crawl.
     */
    private static function grain(int $x, int $y): int
    {
        $hash = (($x * 0x27D4EB2D) ^ ($y * 0x165667B1)) & 0xFFFFFFFF;
        $hash ^= $hash >> 15;
        $hash = ($hash * 0x2545F491) & 0xFFFFFFFF;

        return $hash ^ ($hash >> 13);
    }

    private static function shade(int $colour, float $factor): int
    {
        $r = min(255, (int) round((($colour >> 16) & 0xFF) * $factor));
        $g = min(255, (int) round((($colour >> 8) & 0xFF) * $factor));
        $b = min(255, (int) round(($colour & 0xFF) * $factor));

        return 0xFF000000 | ($r << 16) | ($g << 8) | $b;
    }
}
