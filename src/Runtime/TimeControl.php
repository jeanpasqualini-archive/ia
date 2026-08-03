<?php

declare(strict_types=1);

namespace Runtime;

/**
 * Everything the control bar drives: is the simulation running, how fast, and
 * whether we are browsing past instants instead of computing new ones.
 *
 * Holding this in one object is what lets the game loop and the renderer agree
 * on the state without the renderer reaching back into the runner.
 */
class TimeControl
{
    /**
     * Delay between two automatic ticks, in microseconds, slowest first.
     *
     * @var list<array{label: string, delay: int}>
     */
    private const SPEEDS = [
        ['label' => 'x0.25', 'delay' => 400_000],
        ['label' => 'x0.5', 'delay' => 200_000],
        ['label' => 'x1', 'delay' => 100_000],
        ['label' => 'x2', 'delay' => 50_000],
        ['label' => 'x4', 'delay' => 10_000],
    ];

    private const DEFAULT_SPEED = 2;

    private int $speed = self::DEFAULT_SPEED;

    public function __construct(
        private bool $paused = true,
        private bool $timeMachine = false,
    ) {
    }

    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function togglePause(): void
    {
        $this->paused = !$this->paused;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function play(): void
    {
        $this->paused = false;
    }

    public function faster(): void
    {
        $this->speed = min($this->speed + 1, count(self::SPEEDS) - 1);
    }

    public function slower(): void
    {
        $this->speed = max($this->speed - 1, 0);
    }

    public function isFastest(): bool
    {
        return $this->speed === count(self::SPEEDS) - 1;
    }

    public function isSlowest(): bool
    {
        return 0 === $this->speed;
    }

    public function delay(): int
    {
        return self::SPEEDS[$this->speed]['delay'];
    }

    public function speedLabel(): string
    {
        return self::SPEEDS[$this->speed]['label'];
    }

    public function isTimeMachine(): bool
    {
        return $this->timeMachine;
    }

    public function toggleTimeMachine(): void
    {
        $this->timeMachine = !$this->timeMachine;

        // Browsing the past while the simulation runs forward makes no sense:
        // entering the time machine always pauses.
        if ($this->timeMachine) {
            $this->paused = true;
        }
    }
}
