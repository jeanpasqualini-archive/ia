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
}
