<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Map\Render\IsoSprites;
use Map\Render\IsoView;
use Map\Render\Pixels;
use Map\Render\TilePalette;
use PHPUnit\Framework\TestCase;
use Runtime\Camera;

/**
 * The world seen from the side, composed with no window anywhere — the same
 * seam the map view is checked through.
 */
final class IsoViewTest extends TestCase
{
    /**
     * Pixel art has no compiler. A row one character short shifts everything
     * below it, and a tree comes out with a canopy hanging off its trunk.
     */
    public function testEveryRowOfEverySpriteIsTheSameWidth(): void
    {
        foreach (IsoSprites::all() as $name => $sprite) {
            $widths = array_map('strlen', $sprite['rows']);

            self::assertCount(1, array_unique($widths), sprintf('le sprite %s a des lignes inegales', $name));
        }
    }

    /**
     * The anchor is the foot of the drawing, and it has to fall inside it.
     * Outside, a whole forest stands half a tile up the hill from the ground
     * it grows in.
     */
    public function testEverySpriteStandsInsideItsOwnAnchor(): void
    {
        foreach (IsoSprites::all() as $name => $sprite) {
            [$x, $y] = $sprite['anchor'];

            self::assertGreaterThanOrEqual(0, $x, $name);
            self::assertLessThanOrEqual(strlen($sprite['rows'][0]), $x, $name);
            self::assertSame(count($sprite['rows']), $y, sprintf('le sprite %s ne pose pas ses pieds', $name));
        }
    }

    /**
     * The whole area is covered, corners included.
     *
     * A diamond lattice fans out from a point, so drawn from the top of the
     * view it leaves both upper corners bare — the first version did exactly
     * that and half the screen was sky. It has to start above the view and be
     * clipped.
     */
    public function testTheLatticeFillsTheScreenRightIntoTheCorners(): void
    {
        $pixels = $this->paint(array_fill(0, 80, array_fill(0, 80, 'X')));
        $sky = 0;

        foreach ([[1, 1], [198, 1], [1, 94], [198, 94], [100, 2]] as [$x, $y]) {
            if (0xFF0E1418 === $pixels->at($x, $y)) {
                ++$sky;
            }
        }

        self::assertSame(0, $sky, 'des coins du maillage sont restes vides');
    }

    /**
     * The ground is lit above and shaded below. One flat colour reads as
     * squares seen edge on rather than as a landscape: the two faces are what
     * make a tile a solid thing.
     */
    public function testATileHasTwoFacesOrItIsNotASolid(): void
    {
        $pixels = $this->paint(array_fill(0, 80, array_fill(0, 80, 'X')));
        $middle = intdiv(200, 2);

        // Straight down through the middle of a diamond: the upper half and
        // the lower half cannot be the same colour.
        $top = $pixels->at($middle, 40);
        $bottom = $pixels->at($middle, 43);

        self::assertNotSame($top, $bottom, 'le sol est un aplat');
    }

    /**
     * The colours come from the palette rather than from a second table, so
     * the meadow keeps the hashed grain it has on the map and the lake keeps
     * its swell. A second table is one that drifts.
     */
    public function testTheGroundIsPaintedFromTheSamePaletteAsTheMap(): void
    {
        $palette = new TilePalette(trueColor: true);
        $lake = array_fill(0, 80, array_fill(0, 80, 'E'));
        $pixels = (new IsoView($palette))->paint($lake, new Camera(), 200, 96);

        $seen = [];

        for ($y = 20; $y < 80; $y++) {
            for ($x = 20; $x < 180; $x++) {
                $seen[$pixels->at($x, $y)] = true;
            }
        }

        // Every water shade of the palette, lit or shaded, and nothing else.
        $water = [];

        for ($x = 0; $x < 40; $x++) {
            $water[Pixels::pack($palette->pixel('E', $x, 0)) & 0xFF0000] = true;
        }

        self::assertNotEmpty($water);
        self::assertGreaterThan(2, count($seen), 'le lac est un aplat, la houle a disparu');
    }

    /**
     * A cat is the one thing the map view cannot say at all — there it is a
     * single coloured tile — so it has to be drawn where its tile is.
     */
    public function testACatIsDrawnOnItsOwnTile(): void
    {
        $coat = 0xFFFF5C5C;
        $ground = array_fill(0, 80, array_fill(0, 80, 'X'));
        $pixels = $this->paint($ground, ['10;10' => [
            'coat' => $coat,
            'patch' => 0xFF9E3535,
            'eye' => 0xFFFFE98A,
            'outline' => 0xFF14110F,
        ]]);

        $found = false;

        for ($y = 0; $y < 96 && !$found; $y++) {
            for ($x = 0; $x < 200; $x++) {
                if ($coat === $pixels->at($x, $y)) {
                    $found = true;

                    break;
                }
            }
        }

        self::assertTrue($found, 'le chat n est pas dans la scene');
    }

    /**
     * @param array<int, array<int, string>> $map
     * @param array<string, array{coat: int, patch: int, eye: int, outline: int}> $cats
     */
    private function paint(array $map, array $cats = []): Pixels
    {
        return (new IsoView(new TilePalette(trueColor: true)))
            ->paint($map, new Camera(), 200, 96, $cats);
    }
}
