<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\MemoryUsage;

final class MemoryUsageTest extends TestCase
{
    public function testItReadsThePhpFootprint(): void
    {
        $usage = new MemoryUsage();

        self::assertGreaterThan(0, $usage->phpCurrent());
        self::assertGreaterThanOrEqual($usage->phpCurrent(), $usage->phpPeak());
    }

    public function testAnUncappedCeilingHasNoRatio(): void
    {
        self::assertNull(MemoryUsage::ratio(1024, null));
        self::assertNull(MemoryUsage::ratio(null, 1024));
        self::assertNull(MemoryUsage::ratio(1024, 0));
    }

    public function testRatioIsTheFractionOfTheLimitUsed(): void
    {
        self::assertSame(0.5, MemoryUsage::ratio(512, 1024));
    }

    public function testBytesAreFormattedForAOneLinePanel(): void
    {
        self::assertSame('512o', MemoryUsage::format(512));
        self::assertSame('1.0K', MemoryUsage::format(1024));
        self::assertSame('1.5M', MemoryUsage::format(1024 * 1024 * 3 / 2));
        self::assertSame('2.0G', MemoryUsage::format(1024 ** 3 * 2));
        self::assertSame('-', MemoryUsage::format(null), 'une limite absente reste lisible');
    }
}
