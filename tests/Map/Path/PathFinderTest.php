<?php

declare(strict_types=1);

namespace Tests\Map\Path;

use Map\Builder\MapBuilder;
use Map\Location\Point;
use Map\Path\PathFinder;
use PHPUnit\Framework\TestCase;

final class PathFinderTest extends TestCase
{
    public function testItWalksStraightAcrossOpenGround(): void
    {
        $finder = $this->finder([
            'XXXX',
            'XXXX',
        ]);

        $route = $finder->to(new Point(0, 0), new Point(3, 0));

        self::assertNotNull($route);
        self::assertSame(['0;1', '0;2', '0;3'], $this->trace($route));
    }

    public function testItRefusesToEnterTheWater(): void
    {
        $finder = $this->finder([
            'XEX',
            'XEX',
            'XEX',
        ]);

        self::assertNull($finder->to(new Point(0, 1), new Point(2, 1)), 'le lac coupe la carte en deux');
        self::assertNull($finder->to(new Point(0, 1), new Point(1, 1)), 'et on ne s y arrete pas non plus');
    }

    public function testItWalksAroundALake(): void
    {
        $finder = $this->finder([
            'XXXXX',
            'XEEEX',
            'XXXXX',
        ]);

        $route = $finder->to(new Point(0, 1), new Point(4, 1));

        self::assertNotNull($route);
        self::assertSame('1;4', (string) end($route));

        foreach ($route as $step) {
            self::assertNotSame(MapBuilder::EAU, $this->map->getItem($step), 'aucun pas dans l eau');
        }
    }

    /**
     * Undergrowth costs three times grass, so slipping around it beats
     * walking through it.
     */
    public function testItPrefersGrassToUndergrowth(): void
    {
        $finder = $this->finder([
            'XYX',
            'XXX',
        ]);

        $route = $finder->to(new Point(0, 0), new Point(2, 0));

        self::assertNotNull($route);

        foreach ($route as $step) {
            self::assertNotSame(MapBuilder::ARBRE, $this->map->getItem($step));
        }
    }

    public function testItStillCrossesAWoodWhenThereIsNoWayAround(): void
    {
        $finder = $this->finder([
            'EEEEE',
            'XXYXX',
            'EEEEE',
        ]);

        $route = $finder->to(new Point(0, 1), new Point(4, 1));

        self::assertNotNull($route, 'le sous-bois ralentit, il ne bloque pas');
        self::assertSame('1;4', (string) end($route));
    }

    public function testDiagonalsCannotSlipBetweenTwoLakes(): void
    {
        $finder = $this->finder([
            'XE',
            'EX',
        ]);

        self::assertNull($finder->to(new Point(0, 0), new Point(1, 1)));
    }

    public function testTheNearestFlowerIsTheNearestReachableOne(): void
    {
        // The flower one tile away is on an island; the walkable one is far.
        $finder = $this->finder([
            'XEFEX',
            'XEEEX',
            'XXXXX',
            'XXXXF',
        ]);

        $route = $finder->toNearest(new Point(0, 0), MapBuilder::FLEUR);

        self::assertNotNull($route);
        self::assertSame('3;4', (string) end($route), 'l ile est ignoree');
    }

    public function testNoRouteWhenEveryFlowerIsUnreachable(): void
    {
        $finder = $this->finder([
            'XEF',
            'XEX',
        ]);

        self::assertNull($finder->toNearest(new Point(0, 0), MapBuilder::FLEUR));
    }

    public function testStandingOnAFlowerStillLooksForTheNextOne(): void
    {
        $finder = $this->finder(['FXXF']);

        $route = $finder->toNearest(new Point(0, 0), MapBuilder::FLEUR);

        self::assertNotNull($route);
        self::assertSame('0;3', (string) end($route));
    }

    private MapBuilder $map;

    /**
     * @param list<string> $rows
     */
    private function finder(array $rows): PathFinder
    {
        $this->map = new MapBuilder($rows);

        return new PathFinder($this->map);
    }

    /**
     * A search bounded by what the searcher can see is what lets the map grow
     * without the simulation slowing down. Measured on a 256x160 world with a
     * flower that exists but cannot be reached — the case that forces the
     * flood to give up — an unbounded search took 32 ms; bounded, it does not
     * register.
     */
    public function testAFlowerOutOfSightIsNotFound(): void
    {
        $finder = $this->finder([
            'XXXXXXXXXXF',
        ]);

        self::assertNull($finder->toNearest(new Point(0, 0), MapBuilder::FLEUR, 5), 'hors de vue');
        self::assertNotNull($finder->toNearest(new Point(0, 0), MapBuilder::FLEUR, 10), 'a portee');
    }

    public function testWithoutARangeEverythingIsStillVisible(): void
    {
        $finder = $this->finder([
            'XXXXXXXXXXXXXXXXXXXXF',
        ]);

        self::assertNotNull($finder->toNearest(new Point(0, 0), MapBuilder::FLEUR));
    }

    /**
     * The budget is spent in cost, not in distance, and undergrowth costs
     * three times what grass does. A cat therefore sees three times less far
     * through a wood than across a meadow — behaviour that would otherwise
     * have had to be written by hand.
     */
    public function testAWoodShortensTheSightLine(): void
    {
        $meadow = $this->finder(['XXXXXF']);
        $wood = $this->finder(['XYYYYF']);
        $from = new Point(0, 0);

        self::assertNotNull($meadow->toNearest($from, MapBuilder::FLEUR, 5), 'cinq cases de prairie');
        self::assertNull($wood->toNearest($from, MapBuilder::FLEUR, 5), 'les memes cinq cases de sous-bois');
        self::assertNotNull($wood->toNearest($from, MapBuilder::FLEUR, 14), 'assez de budget pour traverser');
    }

    /**
     * What a cat can see is never a circle, which is exactly why the field of
     * view is drawn from this rather than from a radius: a lake cuts it off,
     * and the far side stays hidden however close it is as the crow flies.
     */
    public function testSightIsCutOffByWaterRatherThanByDistance(): void
    {
        $finder = $this->finder([
            'XXEXX',
            'XXEXX',
            'XXEXX',
        ]);

        $seen = $finder->costsWithin(new Point(0, 1), 10);

        self::assertArrayHasKey(5 + 1, $seen, 'la case voisine, a droite');
        self::assertArrayNotHasKey(5 + 2, $seen, "l'eau elle meme n'est jamais atteinte");
        self::assertArrayNotHasKey(5 + 3, $seen, "ni l'autre rive, pourtant a trois cases");
    }

    public function testUndergrowthEatsTheSightBudgetThreeTimesFaster(): void
    {
        $meadow = $this->finder(['XXXXXX'])->costsWithin(new Point(0, 0), 3);
        $wood = $this->finder(['XYYYYY'])->costsWithin(new Point(0, 0), 3);

        self::assertArrayHasKey(3, $meadow, 'trois cases de prairie');
        self::assertArrayNotHasKey(3, $wood, 'trois cases de sous-bois coutent le triple');
        self::assertArrayHasKey(1, $wood, 'la premiere reste a portee');
    }

    public function testNothingBeyondTheBudgetIsReported(): void
    {
        $seen = $this->finder(['XXXXXXXXXX'])->costsWithin(new Point(0, 0), 4);

        self::assertArrayHasKey(4, $seen);
        self::assertArrayNotHasKey(5, $seen);
    }

    /**
     * A searcher's own opinion changes which way it goes, and nothing else.
     */
    public function testABiasSendsTheRouteRoundRatherThanThrough(): void
    {
        $rows = [
            'XXXXXXXXXX',
            'XRRRRRRRRX',
            'XXXXXXXXXF',
        ];
        $map = new MapBuilder($rows);
        $from = new Point(0, 1);

        $straight = (new PathFinder($map))->toNearest($from, MapBuilder::FLEUR, 40);
        $wary = (new PathFinder($map, new AvoidsBrambles()))->toNearest($from, MapBuilder::FLEUR, 40);

        self::assertNotNull($straight);
        self::assertNotNull($wary);
        self::assertGreaterThan(0, $this->brambles($map, $straight), 'sans opinion, il coupe au plus court');
        self::assertSame(0, $this->brambles($map, $wary), 'avec, il contourne');
    }

    /**
     * The bug this guards against is subtle and was real: the range is what a
     * searcher can *see*, the bias is what it *prefers*, and adding the
     * second to the first makes a cat stop seeing food at the end of a path
     * it dislikes. Fear must never blind.
     */
    public function testABiasDoesNotShortenTheSightLine(): void
    {
        // The only way through is over brambles, and it is well within range.
        $map = new MapBuilder([
            'EEEEE',
            'XRRRX',
            'EEEEF',
        ]);
        $from = new Point(0, 1);

        $route = (new PathFinder($map, new AvoidsBrambles()))->toNearest($from, MapBuilder::FLEUR, 10);

        self::assertNotNull($route, 'la fleur reste visible, meme si le chemin lui deplait');
    }

    /**
     * @param list<Point> $route
     */
    private function brambles(MapBuilder $map, array $route): int
    {
        $count = 0;

        foreach ($route as $point) {
            if (MapBuilder::RONCE === $map->tileAt($point->getX(), $point->getY())) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * A thicket blocks the view, and nothing about the view says so.
     *
     * Sight is spent in cost rather than in distance, so ground that is twice
     * as hard to push through is seen half as far into. Writing an opacity
     * anywhere would have been a second mechanism saying the same thing.
     */
    public function testAThicketIsSeenIntoLessFarThanOpenForest(): void
    {
        $from = new Point(0, 0);

        $open = $this->finder(['XYYYYYYYYY'])->costsWithin($from, 12);
        $dense = $this->finder(['XUUUUUUUUU'])->costsWithin($from, 12);
        $meadow = $this->finder(['XXXXXXXXXX'])->costsWithin($from, 12);

        self::assertGreaterThan(count($open), count($meadow), 'on voit loin en prairie');
        self::assertGreaterThan(count($dense), count($open), 'moins loin sous les arbres');
        self::assertLessThan(4, count($dense), 'et a peine dans un fourre');
    }

    public function testTheRangeBoundsAPlainRouteToo(): void
    {
        $finder = $this->finder(['XXXXXXXXXX']);

        self::assertNull($finder->to(new Point(0, 0), new Point(9, 0), 4));
        self::assertNotNull($finder->to(new Point(0, 0), new Point(9, 0), 9));
    }

    /**
     * @param list<Point> $route
     *
     * @return list<string>
     */
    private function trace(array $route): array
    {
        return array_map(static fn (Point $point): string => (string) $point, $route);
    }
}
