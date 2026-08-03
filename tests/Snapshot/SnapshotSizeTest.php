<?php

declare(strict_types=1);

namespace Tests\Snapshot;

use Memory\FlashMemory;
use PHPUnit\Framework\TestCase;
use Snapshot\Instant;
use Tests\WorldFactory;

/**
 * The snapshot ring is the biggest thing this program holds; the memory panel
 * reports it, so its accounting has to be right.
 */
final class SnapshotSizeTest extends TestCase
{
    public function testAnInstantReportsItsPayloadSize(): void
    {
        $small = new Instant(WorldFactory::fromRows(['XX']));
        $big = new Instant(WorldFactory::fromRows(array_fill(0, 40, str_repeat('X', 80))));

        self::assertGreaterThan(0, $small->size());
        self::assertGreaterThan($small->size(), $big->size(), 'une grande map pese plus lourd');
    }

    public function testTheFlashMemorySumsWhatItHolds(): void
    {
        $memory = new FlashMemory(3);
        $world = WorldFactory::fromRows(['XXX']);

        self::assertSame(0, $memory->bytes());

        $memory->addInstant($instant = new Instant($world));
        self::assertSame($instant->size(), $memory->bytes());

        $memory->addInstant(new Instant($world));
        self::assertSame($instant->size() * 2, $memory->bytes());
    }

    public function testDroppedInstantsStopCounting(): void
    {
        $memory = new FlashMemory(2);
        $world = WorldFactory::fromRows(['XXX']);

        for ($i = 0; $i < 6; $i++) {
            $memory->addInstant(new Instant($world));
        }

        self::assertSame(2, $memory->count());
        self::assertSame(
            (new Instant($world))->size() * 2,
            $memory->bytes(),
            'la mémoire plafonne au lieu de croitre sans fin'
        );
    }
}
