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
     * @param list<Point> $route
     *
     * @return list<string>
     */
    private function trace(array $route): array
    {
        return array_map(static fn (Point $point): string => (string) $point, $route);
    }
}
