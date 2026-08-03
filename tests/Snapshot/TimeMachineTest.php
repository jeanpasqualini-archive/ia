<?php

declare(strict_types=1);

namespace Tests\Snapshot;

use Map\World\World;
use Memory\FlashMemory;
use PHPUnit\Framework\TestCase;
use Snapshot\Instant;
use Tests\WorldFactory;

final class TimeMachineTest extends TestCase
{
    public function testASnapshotRestoresThePositionTheWorldHadWhenItWasTaken(): void
    {
        $world = WorldFactory::fromRows(['XXXX', 'XXXX'], 0, 0);
        $instant = new Instant($world);

        WorldFactory::chat($world)->getPosition()->setX(3);

        $restored = $instant->getData();
        self::assertInstanceOf(World::class, $restored);
        self::assertSame('0;0', (string) WorldFactory::chat($restored)->getPosition());
        self::assertSame('0;3', (string) WorldFactory::chat($world)->getPosition());
    }

    public function testARestoredWorldStillReactsToHunger(): void
    {
        $world = WorldFactory::fromRows(['XXF', 'XXX']);
        WorldFactory::chat($world)->getEstomac()->setNouriture(0);

        $restored = (new Instant($world))->getData();
        self::assertInstanceOf(World::class, $restored);

        $chat = WorldFactory::chat($restored);
        $chat->update($restored);

        // The stomach listeners are closures: they only survive because
        // CatIA re-subscribes on wake up.
        self::assertCount(1, $chat->getIa()->getObjectifs());
    }

    public function testTheFlashMemoryDropsTheOldestInstantWhenFull(): void
    {
        $memory = new FlashMemory(3);
        $world = WorldFactory::fromRows(['XX']);

        for ($i = 0; $i < 5; $i++) {
            WorldFactory::chat($world)->getPosition()->setX($i);
            $memory->addInstant(new Instant($world));
        }

        self::assertSame(3, $memory->count());
        self::assertCount(3, $memory->all());
    }

    public function testWalkingBackAndForthThroughTheSnapshots(): void
    {
        $memory = new FlashMemory(5);
        $world = WorldFactory::fromRows(['XXXXX']);

        for ($x = 0; $x < 3; $x++) {
            WorldFactory::chat($world)->getPosition()->setX($x);
            $memory->addInstant(new Instant($world));
        }

        $previous = $memory->previous();
        self::assertNotNull($previous);
        self::assertSame('0;1', (string) WorldFactory::chat($previous->getData())->getPosition());

        $next = $memory->after();
        self::assertNotNull($next);
        self::assertSame('0;2', (string) WorldFactory::chat($next->getData())->getPosition());

        self::assertNull($memory->after(), 'on ne depasse pas le dernier instant');
    }

    public function testTheCursorStopsAtTheOldestInstant(): void
    {
        $memory = new FlashMemory(5);
        $world = WorldFactory::fromRows(['XX']);
        $memory->addInstant(new Instant($world));

        self::assertNull($memory->previous());
    }
}
