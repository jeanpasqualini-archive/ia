<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Map\Render\IsoSprites;
use Map\Render\IsoView;
use Map\Render\Pixels;
use Map\Render\TilePalette;
use PHPUnit\Framework\TestCase;
use Map\Provider\TerrainMapProvider;
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
     * The ground is raised by the field, which is the whole point of the
     * isometric view: shading says which way is up, height *is* up.
     */
    public function testTheGroundIsRaisedByTheElevation(): void
    {
        $meadow = array_fill(0, 80, array_fill(0, 80, 'X'));

        $flat = $this->paint($meadow);
        $hilly = (new IsoView(new TilePalette(trueColor: true), $this->ramp()))
            ->paint($meadow, new Camera(), 200, 96);

        $moved = 0;

        for ($y = 0; $y < 96; $y += 2) {
            for ($x = 0; $x < 200; $x += 2) {
                if ($flat->at($x, $y) !== $hilly->at($x, $y)) {
                    ++$moved;
                }
            }
        }

        self::assertGreaterThan(500, $moved, 'le terrain est reste plat');
    }

    /**
     * **No hole may open between two tiles of raised ground.**
     *
     * The side of a block is its silhouette dropped straight down. The first
     * version narrowed it by a pixel a row, which finishes in a point, so
     * every raised tile left two black wedges at its corners and the whole
     * landscape came out torn. Sky *inside* the ground is the signature, and
     * it is what this counts.
     */
    public function testNoHoleOpensBetweenTwoTilesOfRaisedGround(): void
    {
        // Real terrain and not a smooth ramp: a gentle slope lifts
        // neighbouring tiles by the same amount and hides the fault entirely.
        // A ramp is what this test used at first, and it passed while a
        // seventh of the landscape was sky.
        $provider = new TerrainMapProvider(90, 90, 7);
        $map = array_map('str_split', $provider->getMap());
        $camera = new Camera();
        $camera->centreOn(45, 45);

        $pixels = (new IsoView(new TilePalette(trueColor: true), $provider->relief()))
            ->paint($map, $camera, 200, 96);

        $holes = 0;

        // Well inside the lattice, where every direction is covered ground.
        for ($y = 30; $y < 80; $y++) {
            for ($x = 40; $x < 160; $x++) {
                if (0xFF0E1418 === $pixels->at($x, $y)) {
                    ++$holes;
                }
            }
        }

        self::assertSame(0, $holes, 'le paysage est troue');
    }

    /**
     * **A lake has a surface, not a slope.** The elevation carries on below
     * the water line — being low is what made it a lake — so lifting one by
     * it would draw the bottom of the lake as though that were its top, and a
     * bay would come out with a hillside in it.
     */
    public function testALakeIsNeverRaised(): void
    {
        $lake = array_fill(0, 80, array_fill(0, 80, 'E'));

        $flat = $this->paint($lake);
        $hilly = (new IsoView(new TilePalette(trueColor: true), $this->ramp()))
            ->paint($lake, new Camera(), 200, 96);

        for ($y = 0; $y < 96; $y += 3) {
            for ($x = 0; $x < 200; $x += 3) {
                self::assertSame($flat->at($x, $y), $hilly->at($x, $y), sprintf('le lac a une pente en %d;%d', $x, $y));
            }
        }
    }

    /**
     * Grass had no asset at all and the ground alone said it, which made the
     * meadow the one terrain drawn here the way the *map* view draws
     * everything — a flat colour. A tuft on every tile is a lawn, and the same
     * tuft on every tile is wallpaper, so both are refused.
     */
    public function testTheMeadowHasTuftsAndIsNotACarpetOfThem(): void
    {
        $shapes = [];
        $bare = 0;

        for ($hash = 0; $hash < 60; $hash++) {
            $tuft = IsoSprites::tuft($hash);

            if (null === $tuft) {
                ++$bare;

                continue;
            }

            $shapes[implode('', $tuft['rows'])] = true;
        }

        self::assertGreaterThan(20, $bare, 'la prairie est une pelouse');
        self::assertCount(3, $shapes, 'toutes les touffes sont identiques');
    }

    /**
     * The foam follows the very wave that colours the water, and breaks only
     * near its crest. Computed from a second wave the two would drift apart
     * the first time either was tuned.
     */
    public function testTheLakeBreaksOnItsCrestsAndNowhereElse(): void
    {
        $palette = new TilePalette(trueColor: true);
        $palette->animate(1.3);

        $lake = array_fill(0, 80, array_fill(0, 80, 'E'));
        $pixels = (new IsoView($palette))->paint($lake, new Camera(), 200, 96);

        $foam = 0;
        $total = 0;

        for ($y = 10; $y < 86; $y++) {
            for ($x = 10; $x < 190; $x++) {
                ++$total;

                if (0xFFD8ECF6 === $pixels->at($x, $y)) {
                    ++$foam;
                }
            }
        }

        self::assertGreaterThan(0, $foam, 'le lac ne casse jamais');
        self::assertLessThan($total * 0.1, $foam, 'le lac est une nappe d ecume');
    }

    /** A ramp across the map, so some ground is high and some is low. */
    private function ramp(): \Map\Relief
    {
        $field = [];

        for ($y = 0; $y < 80; $y++) {
            $row = [];

            for ($x = 0; $x < 80; $x++) {
                $row[] = (float) ($x + $y);
            }

            $field[] = $row;
        }

        return \Map\Relief::fromField($field);
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
