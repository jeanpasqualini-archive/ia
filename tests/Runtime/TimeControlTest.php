<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\TimeControl;

final class TimeControlTest extends TestCase
{
    public function testTheLadderClimbsToAThousandAndStopsThere(): void
    {
        $time = new TimeControl();

        for ($i = 0; $i < 50; $i++) {
            $time->faster();
        }

        self::assertSame(1000.0, $time->multiplier());
        self::assertSame('x1000', $time->speedLabel());
        self::assertTrue($time->isFastest());
    }

    public function testTheLadderBottomsOutAtAQuarter(): void
    {
        $time = new TimeControl();

        for ($i = 0; $i < 50; $i++) {
            $time->slower();
        }

        self::assertSame(0.25, $time->multiplier());
        self::assertSame('x0.25', $time->speedLabel());
        self::assertTrue($time->isSlowest());
    }

    public function testEachStepIsAMeaningfulJump(): void
    {
        $time = new TimeControl();
        $seen = [$time->multiplier()];

        while (!$time->isFastest()) {
            $time->faster();
            $seen[] = $time->multiplier();
        }

        self::assertSame($seen, array_unique($seen));

        for ($i = 1; $i < count($seen); $i++) {
            self::assertGreaterThanOrEqual(
                2.0,
                $seen[$i] / $seen[$i - 1],
                'chaque cran double au moins'
            );
        }
    }

    /**
     * Below the render rate the delay carries the speed and a frame is a
     * single tick.
     */
    public function testSlowSpeedsSleepLongerRatherThanBatch(): void
    {
        $time = new TimeControl();
        $time->slower();
        $time->slower();

        self::assertSame(0.25, $time->multiplier());
        self::assertSame(1, $time->ticksPerFrame());
        self::assertSame(266_667, $time->frameDelay());
    }

    public function testAtOneTheSimulationRunsAtTheBaseRate(): void
    {
        $time = new TimeControl();

        self::assertSame(1.0, $time->multiplier());
        self::assertSame(1, $time->ticksPerFrame());
        self::assertSame(66_667, $time->frameDelay());
    }

    /**
     * Past the render rate there is no sleep left to shave, so speed has to
     * come from computing more per frame.
     */
    public function testHighSpeedsBatchTicksInsteadOfSleepingLess(): void
    {
        $time = new TimeControl();

        while (!$time->isFastest()) {
            $time->faster();
        }

        self::assertSame(1000, $time->ticksPerFrame());
        self::assertSame(
            66_667,
            $time->frameDelay(),
            'on ne dessine jamais plus vite que le taux de rendu'
        );
    }

    public function testTheRequestedRateIsTicksPerFrameTimesFramesPerSecond(): void
    {
        $time = new TimeControl();

        while (!$time->isFastest()) {
            $time->faster();

            $delivered = $time->ticksPerFrame() * (1_000_000 / $time->frameDelay());

            self::assertEqualsWithDelta(
                $time->multiplier() * TimeControl::BASE_RATE,
                $delivered,
                $time->multiplier() * TimeControl::BASE_RATE * 0.02
            );
        }
    }

    public function testAMachineThatKeepsUpIsNotReportedAsLagging(): void
    {
        $time = new TimeControl();
        $time->observe(TimeControl::BASE_RATE);

        self::assertFalse($time->isLagging());
        self::assertEqualsWithDelta(1.0, $time->observedMultiplier() ?? 0.0, 0.01);
    }

    /**
     * Asking for x1000 does not make the machine deliver it; the readout has
     * to admit it rather than show a comfortable lie.
     */
    public function testFallingBehindIsReported(): void
    {
        $time = new TimeControl();

        while (!$time->isFastest()) {
            $time->faster();
        }

        for ($i = 0; $i < 10; $i++) {
            $time->observe(TimeControl::BASE_RATE * 300);
        }

        self::assertTrue($time->isLagging());
        self::assertEqualsWithDelta(300.0, $time->observedMultiplier() ?? 0.0, 5.0);
    }

    public function testChangingSpeedForgetsTheOldMeasurement(): void
    {
        $time = new TimeControl();
        $time->observe(TimeControl::BASE_RATE * 900);

        $time->faster();

        self::assertNull($time->observedMultiplier());
        self::assertFalse($time->isLagging(), 'sans mesure, pas d accusation');
    }
}
