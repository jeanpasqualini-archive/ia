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

    public function testWaterCostsNothingBecauseItCannotBeWalkedOn(): void
    {
        $map = new MapBuilder(['XYEF']);

        self::assertSame(1, $map->cost(new Point(0, 0)));
        self::assertSame(3, $map->cost(new Point(1, 0)), 'le sous-bois ralentit');
        self::assertNull($map->cost(new Point(2, 0)));
        self::assertSame(1, $map->cost(new Point(3, 0)));

        self::assertTrue($map->isWalkable(new Point(0, 0)));
        self::assertFalse($map->isWalkable(new Point(2, 0)));
        self::assertFalse($map->isWalkable(new Point(9, 9)), 'hors carte');
    }

    public function testASpawnInTheWaterIsPushedToTheNearestShore(): void
    {
        $map = new MapBuilder([
            'EEEE',
            'EEEE',
            'EEXE',
        ]);

        self::assertSame('2;2', (string) $map->nearestWalkable(new Point(0, 0)));
    }

    public function testAWalkableSpawnIsLeftAlone(): void
    {
        $map = new MapBuilder(['XX']);

        self::assertSame('0;1', (string) $map->nearestWalkable(new Point(1, 0)));
    }

    public function testReadingOutsideTheMapReturnsNullInsteadOfWarning(): void
    {
        $map = new MapBuilder(['XX']);

        self::assertNull($map->getItem(new Point(50, 50)));
    }

    /**
     * Scanning the whole map to keep the handful of tiles a cat can see is
     * the kind of cost that hides while the map is the size of the screen.
     */
    public function testPositionsCanBeLimitedToWhatIsWithinReach(): void
    {
        $map = new MapBuilder([
            'FXXXXF',
            'XXXXXX',
            'FXXXXF',
        ]);

        self::assertCount(4, $map->positionsOf(MapBuilder::FLEUR));

        // The box around 1;1 reaches the whole left column and neither of the
        // flowers on the right.
        self::assertSame([[0, 0], [2, 0]], $map->positionsOf(MapBuilder::FLEUR, new Point(1, 1), 1));
        self::assertCount(4, $map->positionsOf(MapBuilder::FLEUR, new Point(2, 1), 5), 'la boite deborde la carte sans casser');
    }

    /**
     * The grid is rebuilt only when the terrain changes. A cat moving writes
     * to the player layer every frame, and throwing the grid away each time
     * would undo the point of keeping it.
     */
    public function testTheCostGridIsKeptUntilTheTerrainChanges(): void
    {
        $map = new MapBuilder(['XEX', 'XXX']);
        $first = $map->costGrid();

        $map->setItem(new Point(0, 0), '1', MapBuilder::LAYER_PLAYER);

        self::assertSame($first, $map->costGrid(), 'un joueur qui bouge ne change pas les couts');

        $map->setItem(new Point(1, 0), MapBuilder::HERBE);

        self::assertNotSame($first, $map->costGrid(), 'le lac comble est devenu praticable');
        self::assertSame(1, $map->cost(new Point(1, 0)));
    }

    /**
     * Terrain rows are packed back into strings on the way out, which is what
     * makes a snapshot of a large map affordable. The player layer must not
     * be: it is sparse, written at whatever coordinates the cats stand on,
     * and imploding it would quietly move every one of them to the start of
     * its row.
     */
    public function testSerialisationKeepsTheSparsePlayerLayerWhereItIs(): void
    {
        $map = new MapBuilder(['XXFX', 'XEXX', 'XXXX']);
        $map->setItem(new Point(3, 2), '1', MapBuilder::LAYER_PLAYER);
        $map->updateFinalLayer();

        $restored = unserialize(serialize($map));

        self::assertInstanceOf(MapBuilder::class, $restored);
        self::assertSame('1', $restored->getItem(new Point(3, 2), MapBuilder::LAYER_PLAYER));
        self::assertSame($map->getFinalMap(), $restored->getFinalMap(), 'la carte a plat est identique');
    }

    public function testSerialisationPreservesEveryTerrainTile(): void
    {
        $rows = ['XXFYE', 'EEXXF', 'YXEXX'];
        $map = new MapBuilder($rows);

        $restored = unserialize(serialize($map));

        self::assertInstanceOf(MapBuilder::class, $restored);
        self::assertSame($map->getWidth(), $restored->getWidth());
        self::assertSame($map->getHeight(), $restored->getHeight());

        foreach ($rows as $y => $row) {
            foreach (str_split($row) as $x => $tile) {
                self::assertSame($tile, $restored->getItem(new Point($x, $y)), sprintf('tuile %d;%d', $x, $y));
            }
        }
    }

    /**
     * The flattened layer is derived, so it is not stored — it has to come
     * back rebuilt rather than empty, since the renderer reads nothing else.
     */
    public function testTheFlattenedLayerIsRebuiltRatherThanStored(): void
    {
        $map = new MapBuilder(['XF', 'EY']);
        $frozen = serialize($map);

        self::assertStringNotContainsString('finalLayer', $frozen);

        $restored = unserialize($frozen);

        self::assertInstanceOf(MapBuilder::class, $restored);
        self::assertNotSame([], $restored->getFinalMap());
    }
}
