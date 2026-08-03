<?php

declare(strict_types=1);

namespace Tests\Map\Builder;

use Map\Builder\MapBuilder;
use Map\Location\Point;
use PHPUnit\Framework\TestCase;

final class MapBuilderTest extends TestCase
{
    public function testFindItemsReturnsClosestFirst(): void
    {
        $map = new MapBuilder([
            'XXXF',
            'XXXX',
            'FXXX',
        ]);

        $found = $map->findItems(new Point(0, 2), MapBuilder::FLEUR);

        self::assertCount(2, $found);
        self::assertSame(0, $found[0]['distance']);
        self::assertSame('2;0', (string) $found[0]['point']);
        self::assertSame('0;3', (string) $found[1]['point']);
    }

    public function testPlayerLayerIsFlattenedOverTheMap(): void
    {
        $map = new MapBuilder(['XX', 'XX']);

        $map->setItem(new Point(1, 0), 'P', MapBuilder::LAYER_PLAYER);
        $map->updateFinalLayer();

        self::assertSame([['X', 'P'], ['X', 'X']], $map->getFinalMap());
    }

    public function testClearingThePlayerLayerRestoresTheGround(): void
    {
        $map = new MapBuilder(['XX', 'XX']);

        $map->setItem(new Point(1, 0), 'P', MapBuilder::LAYER_PLAYER);
        $map->updateFinalLayer();
        $map->clearLayer(MapBuilder::LAYER_PLAYER);
        $map->updateFinalLayer();

        self::assertSame([['X', 'X'], ['X', 'X']], $map->getFinalMap());
    }

    public function testClampKeepsAPositionInsideTheMap(): void
    {
        $map = new MapBuilder(['XXX', 'XXX']);
        $point = new Point(99, -4);

        $map->clamp($point);

        self::assertSame(2, $point->getX());
        self::assertSame(0, $point->getY());
    }

    public function testReadingOutsideTheMapReturnsNullInsteadOfWarning(): void
    {
        $map = new MapBuilder(['XX']);

        self::assertNull($map->getItem(new Point(50, 50)));
    }
}
