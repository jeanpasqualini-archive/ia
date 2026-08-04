<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Map\Render\CatSprite;
use Map\Render\TilePalette;
use PhpTui\Tui\Color\RgbColor;
use PHPUnit\Framework\TestCase;

final class CatSpriteTest extends TestCase
{
    /**
     * Pixel art has no compiler. A row one character short shifts everything
     * to its right and the cat comes out with one eye lower than the other,
     * which is exactly the kind of thing nobody notices until it is drawn.
     */
    public function testEveryRowOfTheArtIsTheSameWidth(): void
    {
        foreach (CatSprite::art() as $row => $line) {
            self::assertSame(
                CatSprite::WIDTH,
                mb_strlen($line),
                sprintf('la ligne %d du dessin', $row)
            );
        }
    }

    /**
     * The silhouette has to be closed: no part of the cat may touch the world
     * directly, only the outline, the cloud, or more cat.
     *
     * This is the bug the first drawing had. On a meadow carrying some hundred
     * and ninety flowers, brambles and holes per screen, a shape with a bare
     * edge has no silhouette and dissolves into the speckle — and it does not
     * look like a missing outline when it happens, it looks like a rendering
     * fault. A hole anywhere in the ring is enough.
     */
    public function testTheCatIsOutlinedAllTheWayRound(): void
    {
        $art = CatSprite::art();
        $body = ['B', 'P', 'I', 'E', 'M'];
        $covered = array_merge($body, ['o', 'C', 's']);

        foreach ($art as $row => $line) {
            foreach (str_split($line) as $column => $char) {
                if (!in_array($char, $body, true)) {
                    continue;
                }

                foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$dx, $dy]) {
                    $neighbour = $art[$row + $dy][$column + $dx] ?? '.';

                    self::assertContains(
                        $neighbour,
                        $covered,
                        sprintf('le chat est a nu en %d;%d', $column, $row)
                    );
                }
            }
        }
    }

    /**
     * A marking has to stay darker than the sky it is drawn against. The first
     * pair was a red coat and a near white cream, over a near white cloud: the
     * right half of the cat melted into the thing it was sitting on, and what
     * one saw was a red shape, a pale shape and some white — never one animal.
     */
    public function testNoMarkingCanMeltIntoTheCloud(): void
    {
        $palette = new TilePalette(trueColor: true);

        $luminance = static function (RgbColor $colour): float {
            return (0.299 * $colour->r + 0.587 * $colour->g + 0.114 * $colour->b) / 255;
        };

        for ($player = 0; $player < 4; $player++) {
            $colours = $palette->catColours($player);

            foreach (['coat', 'patch'] as $fur) {
                self::assertLessThan(
                    $luminance($colours['cloud']) - 0.15,
                    $luminance($colours[$fur]),
                    sprintf('le %s du chat %d se confond avec son nuage', $fur, $player)
                );
            }
        }
    }

    public function testTheCatRisesAndComesBackDown(): void
    {
        $sprite = new CatSprite();
        $palette = new TilePalette(trueColor: true);
        $heights = [];

        for ($step = 0; $step < 40; $step++) {
            $heights[min(array_keys($sprite->over($palette, 0, $step * 0.1)))] = true;
        }

        // Several heights, and it comes back: a marker that drifted off would
        // be a bug that only shows itself after a minute of watching.
        self::assertGreaterThan(2, count($heights), 'le chat ne flotte pas');
        self::assertLessThan(6, max(array_keys($heights)) - min(array_keys($heights)), 'il s envole');
    }

    /**
     * The marker exists to point at one tile, so it must never be the thing
     * covering it. The bob is what makes this worth asserting: measured at
     * rest the clearance looks fine, and the cloud comes down on the cat once
     * a cycle.
     */
    public function testTheMarkerNeverCoversTheCatItPointsAt(): void
    {
        $sprite = new CatSprite();
        $palette = new TilePalette(trueColor: true);

        for ($step = 0; $step < 60; $step++) {
            $lowest = max(array_keys($sprite->over($palette, 0, $step * 0.1)));

            self::assertLessThan(0, $lowest, sprintf('a t=%.1f le nuage descend sur le chat', $step * 0.1));
        }
    }

    /**
     * Bobbing in step, several cats read as one animation copied a few times
     * over. Each is given its own place in the cycle for the same reason the
     * water is drawn from two waves rather than one.
     */
    public function testEachCatFloatsInItsOwnTime(): void
    {
        $sprite = new CatSprite();
        $palette = new TilePalette(trueColor: true);
        $apart = 0;

        for ($step = 0; $step < 30; $step++) {
            $phase = $step * 0.1;
            $first = min(array_keys($sprite->over($palette, 0, $phase)));
            $second = min(array_keys($sprite->over($palette, 1, $phase)));

            if ($first !== $second) {
                ++$apart;
            }
        }

        self::assertGreaterThan(15, $apart, 'les chats flottent au meme rythme');
    }

    /**
     * A coat of two colours, and both of them the cat's own: read from one
     * table as the dot on the map, so the marker and the tile it hangs over
     * are visibly the same animal.
     */
    public function testTheCoatIsTwoColoursAndTellsTheCatsApart(): void
    {
        $sprite = new CatSprite();
        $palette = new TilePalette(trueColor: true);

        $worn = static function (int $player) use ($sprite, $palette): array {
            $found = [];

            foreach ($sprite->over($palette, $player, 0.0) as $line) {
                foreach ($line as $colour) {
                    self::assertInstanceOf(RgbColor::class, $colour);
                    $found[$colour->toHex()] = true;
                }
            }

            return $found;
        };

        $first = $worn(0);
        $second = $worn(1);

        self::assertArrayHasKey($palette->catColours(0)['coat']->toHex(), $first);
        self::assertArrayHasKey($palette->catColours(0)['patch']->toHex(), $first);
        self::assertNotSame(
            $palette->catColours(0)['coat']->toHex(),
            $palette->catColours(0)['patch']->toHex(),
            'un pelage d une seule couleur'
        );

        // The cloud is shared, the coat is not.
        self::assertArrayNotHasKey($palette->catColours(1)['coat']->toHex(), $first);
        self::assertArrayNotHasKey($palette->catColours(0)['coat']->toHex(), $second);
    }

    /**
     * The face is what must sit over the cat, not the bounding box: the tail
     * hangs out to the right and would drag the whole drawing off to the left
     * to make room for itself. Muzzle and inner ears are the only things the
     * snout colour paints, and they are symmetrical, so their columns have to
     * mirror around nothing.
     */
    public function testTheFaceSitsOverTheCat(): void
    {
        $palette = new TilePalette(trueColor: true);
        $snout = $palette->catColours(0)['snout']->toHex();
        $columns = [];

        foreach ((new CatSprite())->over($palette, 0, 0.0) as $line) {
            foreach ($line as $column => $colour) {
                if ($snout === $colour->toHex()) {
                    $columns[$column] = true;
                }
            }
        }

        self::assertNotEmpty($columns);

        foreach (array_keys($columns) as $column) {
            self::assertArrayHasKey(-$column, $columns, sprintf('la colonne %d n a pas de miroir', $column));
        }
    }
}
