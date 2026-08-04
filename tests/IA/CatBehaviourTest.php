<?php

declare(strict_types=1);

namespace Tests\IA;

use IA\Objectif\Manger;
use Map\Builder\MapBuilder;
use Map\Location\Point;
use PHPUnit\Framework\TestCase;
use Tests\WorldFactory;

final class CatBehaviourTest extends TestCase
{
    public function testAFullCatHasNoGoal(): void
    {
        $world = WorldFactory::fromRows(['XXX', 'XXX']);
        $chat = WorldFactory::chat($world);

        $world->update();

        self::assertSame([], $chat->getIa()->getObjectifs());
    }

    public function testAnEmptyStomachCreatesAnEatingGoal(): void
    {
        $world = WorldFactory::fromRows(['XXF', 'XXX']);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        $chat->update($world);

        $objectifs = $chat->getIa()->getObjectifs();
        self::assertCount(1, $objectifs);
        self::assertInstanceOf(Manger::class, $objectifs[0]);
    }

    public function testTheCatWalksToTheFlowerEatsItAndTurnsItIntoGrass(): void
    {
        $world = WorldFactory::fromRows([
            'XXXX',
            'XXXX',
            'XXXF',
        ]);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        // One tick to raise "hungry", then enough ticks to cross the map.
        for ($i = 0; $i < 8; $i++) {
            $chat->getIa()->update($world);
            $chat->update($world);
        }

        self::assertSame('2;3', (string) $chat->getPosition(), 'le chat rejoint la fleur');
        self::assertGreaterThan(0, $chat->getEstomac()->getNouriture(), 'le chat a mange');
        self::assertSame(
            MapBuilder::HERBE,
            $world->getMap()->getItem(new Point(3, 2)),
            'la fleur mangee laisse de l herbe'
        );
    }

    /**
     * End to end: the flower is straight ahead but a lake sits in between, so
     * the cat has to go round. The old greedy walk went through the water.
     */
    public function testTheCatWalksAroundALakeInsteadOfThroughIt(): void
    {
        $world = WorldFactory::fromRows([
            'XXXXX',
            'XEEEX',
            'XEFEX',
            'XEEEX',
            'XXXXX',
        ]);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        $visited = [];

        for ($i = 0; $i < 12; $i++) {
            $chat->getIa()->update($world);
            $chat->update($world);
            $world->getMap()->clamp($chat->getPosition());
            $visited[] = (string) $chat->getPosition();
        }

        foreach ($visited as $step) {
            [$y, $x] = array_map(intval(...), explode(';', $step));
            self::assertNotSame(
                MapBuilder::EAU,
                $world->getMap()->getItem(new Point($x, $y)),
                'le chat ne marche jamais sur l eau'
            );
        }

        // The flower is walled in by the lake: unreachable, so no goal is ever
        // completed and the cat stays hungry rather than teleporting to it.
        self::assertSame(0, $chat->getEstomac()->getNouriture());
    }

    public function testTheCatReachesAFlowerThatIsOnlyAccessibleTheLongWay(): void
    {
        $world = WorldFactory::fromRows([
            'XEEEE',
            'XEXXF',
            'XXXXX',
        ]);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        for ($i = 0; $i < 12; $i++) {
            $chat->getIa()->update($world);
            $chat->update($world);
        }

        self::assertSame('1;4', (string) $chat->getPosition(), 'il a fait le tour');
        self::assertGreaterThan(0, $chat->getEstomac()->getNouriture());
    }

    public function testAStarvingCatWithoutFoodKeepsItsGoalAndStaysAlive(): void
    {
        $world = WorldFactory::fromRows(['XXX', 'XXX']);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        for ($i = 0; $i < 5; $i++) {
            $chat->getIa()->update($world);
            $chat->update($world);
        }

        self::assertCount(1, $chat->getIa()->getObjectifs());
        self::assertSame(0, $chat->getEstomac()->getNouriture());
    }

    /**
     * The foxglove is what a cat cannot tell from a meal until it has eaten
     * one. Having eaten one, it should walk past the next and take the longer
     * way to a real flower.
     */
    public function testACatPoisonedOnceWalksPastTheNextFoxglove(): void
    {
        // Three rows, so there is a way round. On a single row the flower
        // sits *behind* the foxglove and walking to it means stepping on the
        // thing being avoided — the cat then rightly eats the near one, and
        // the test would be measuring the map rather than the memory.
        $world = WorldFactory::fromRows([
            'XXXXXX',
            'XDXDXF',
            'XXXXXX',
        ], chatX: 2, chatY: 1);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        for ($tick = 0; $tick < 4; $tick++) {
            $world->update();
        }

        self::assertGreaterThan(0.0, $chat->getPeur()->expect(['sol:D']), 'il a goute, il sait');
        self::assertLessThan(10, $chat->getLife(), 'et il l a paye');

        $map = $world->getMap();

        // Run until the real flower is gone. What matters is the order: the
        // cat walks past the foxglove next to it to reach the flower further
        // away. Left long enough it will eat that foxglove too, once there is
        // nothing else — which is the right call for a hungry animal and not
        // what is being tested here.
        for ($tick = 0; $tick < 30 && [] !== $map->positionsOf(MapBuilder::FLEUR); $tick++) {
            $world->update();
        }

        self::assertCount(0, $map->positionsOf(MapBuilder::FLEUR), 'la vraie fleur a ete mangee');
        self::assertCount(1, $map->positionsOf(MapBuilder::DIGITALE), 'et la digitale voisine etait encore la');
    }

    /**
     * A cat only sees so far, so on a map larger than its sight it will often
     * have nothing to walk towards. Standing still would read as a broken cat
     * rather than as a hungry one.
     */
    public function testAHungryCatWithNothingInSightGoesLooking(): void
    {
        $world = WorldFactory::fromRows(['XXXXX', 'XXXXX', 'XXXXX']);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);
        $start = (string) $chat->getPosition();

        for ($i = 0; $i < 4; $i++) {
            $chat->getIa()->update($world);
            $chat->update($world);
        }

        self::assertNotSame($start, (string) $chat->getPosition(), 'il est parti voir ailleurs');
    }

    public function testAFlowerBeyondSightIsNotWalkedToStraightAway(): void
    {
        $world = WorldFactory::fromRows([str_repeat('X', 30) . 'F']);
        $chat = WorldFactory::chat($world);
        $chat->getEstomac()->setNouriture(0);

        // One tick to raise "hungry" — the stomach speaks on the player's own
        // update, after the AI's — then one for the goal to act on it.
        for ($i = 0; $i < 3; $i++) {
            $chat->getIa()->update($world);
            $chat->update($world);
        }

        $objectifs = $chat->getIa()->getObjectifs();

        self::assertCount(1, $objectifs);
        self::assertStringStartsWith(
            'Explore',
            $objectifs[0]->describe(),
            'la fleur est a trente cases, il en voit vingt cinq'
        );
    }
}
