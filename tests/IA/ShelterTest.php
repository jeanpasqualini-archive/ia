<?php

declare(strict_types=1);

namespace Tests\IA;

use IA\Objectif\SeMettreAlAbri;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Player\Chat;
use Map\World\World;
use PHPUnit\Framework\TestCase;

final class ShelterTest extends TestCase
{
    public function testACatWalksOnTheSurfaceUntilItGoesThroughACavern(): void
    {
        $world = $this->world(['XCX'], ['GGG']);
        $chat = $this->chat($world);

        self::assertSame(World::SURFACE, $chat->getNiveau());
        self::assertSame(MapBuilder::CAVERNE, $world->mapFor($chat)->getItem($chat->getPosition()));
    }

    /**
     * The same coordinates on both maps, so the tunnel comes out under the
     * hole it was entered by.
     */
    public function testTheLevelIsWhatDecidesWhichMapAPlayerIsReadAgainst(): void
    {
        $world = $this->world(['XXX'], ['GMG']);
        $chat = $this->chat($world, 1);

        self::assertSame(MapBuilder::HERBE, $world->mapFor($chat)->getItem($chat->getPosition()));

        $chat->setNiveau(World::SOUTERRAIN);

        self::assertSame(MapBuilder::CHAMPIGNON, $world->mapFor($chat)->getItem($chat->getPosition()));
    }

    /**
     * The arbitration, and the first one in this world: a badly hurt cat
     * stops looking for food and looks for cover.
     */
    public function testAHurtCatWithAWayDownInSightGoesToGround(): void
    {
        $world = $this->world(['XCXXF'], ['GGGGG']);
        $chat = $this->chat($world);
        $chat->getEstomac()->setNouriture(0);
        $chat->hurt(6);

        for ($tick = 0; $tick < 6; $tick++) {
            $world->update();
        }

        self::assertSame(World::SOUTERRAIN, $chat->getNiveau(), 'il est descendu');
    }

    /**
     * Taken unconditionally, a shelter goal that cannot be satisfied outranks
     * hunger for ever and the cat simply stops eating.
     */
    public function testAHurtCatWithNoWayDownKeepsEating(): void
    {
        $world = $this->world(['XXXXF'], ['GGGGG']);
        $chat = $this->chat($world);
        $chat->getEstomac()->setNouriture(0);
        $chat->hurt(6);

        for ($tick = 0; $tick < 10; $tick++) {
            $world->update();
        }

        self::assertGreaterThan(0, $chat->getEstomac()->getNouriture(), 'il a mange malgre ses blessures');
    }

    /**
     * The goal is what walks the cat back out. Left to hunger, it never
     * happened: coming up was meant to follow from having nothing left to eat
     * down there, and sight covers two thousand tiles — measured, cats went
     * down once and stayed for three quarters of the run.
     */
    public function testTheWholeCycleGoesDownMendsAndComesBackUp(): void
    {
        // The cavern has to exist on *both* maps at the same coordinates —
        // it is one hole seen from two sides. Cut into the surface only, a
        // cat goes down and then has no exit to find.
        $world = $this->world(['XCXXXF'], ['GCGGGG']);
        $chat = $this->chat($world, 0);
        $chat->hurt(6);

        $wentDown = false;

        // Long enough to walk down, heal a point every forty ticks and climb
        // back out — and no longer. Left running, the cat eats the one flower
        // this world holds, goes hungry again and quite rightly sets off
        // hunting, which is a different behaviour from the one under test.
        for ($tick = 0; $tick < 260; $tick++) {
            $world->update();
            $wentDown = $wentDown || World::SOUTERRAIN === $chat->getNiveau();
        }

        self::assertTrue($wentDown, 'il est descendu se mettre a l abri');
        self::assertGreaterThanOrEqual(SeMettreAlAbri::RECOVERED, $chat->getLife(), 'il s est remis');
        self::assertSame(World::SURFACE, $chat->getNiveau(), 'et il est ressorti');
    }

    /**
     * @param list<string> $surface
     * @param list<string> $under
     */
    private function world(array $surface, array $under): World
    {
        return new World(
            new MapBuilder($surface),
            [new Chat()],
            new MultipleLogger(),
            [World::SOUTERRAIN => new MapBuilder($under)]
        );
    }

    private function chat(World $world, int $x = 1): Chat
    {
        $chat = $world->getPlayerCollection()[0];
        self::assertInstanceOf(Chat::class, $chat);

        $chat->getPosition()->setX($x);
        $chat->getPosition()->setY(0);

        return $chat;
    }
}
