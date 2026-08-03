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
    /** Ticks per second at x1. */
    public const BASE_RATE = 15;

    /** Frames per second, whatever the speed. Nobody can watch 15000. */
    public const RENDER_RATE = 15;

    /**
     * Speed ladder, in 1-2-5 steps so each press is a meaningful jump rather
     * than a rounding difference.
     *
     * @var list<float>
     */
    private const SPEEDS = [0.25, 0.5, 1, 2, 5, 10, 20, 50, 100, 200, 500, 1000];

    private const DEFAULT_SPEED = 2;

    private int $speed = self::DEFAULT_SPEED;

    /** Smoothed tick rate actually achieved, null until measured. */
    private ?float $observedRate = null;

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

    /**
     * Jump to the ladder step closest to $multiplier.
     */
    public function setMultiplier(float $multiplier): void
    {
        $best = 0;

        foreach (self::SPEEDS as $index => $speed) {
            if (abs($speed - $multiplier) < abs(self::SPEEDS[$best] - $multiplier)) {
                $best = $index;
            }
        }

        $this->speed = $best;
        $this->observedRate = null;
    }

    public function faster(): void
    {
        $this->speed = min($this->speed + 1, count(self::SPEEDS) - 1);
        $this->observedRate = null;
    }

    public function slower(): void
    {
        $this->speed = max($this->speed - 1, 0);
        $this->observedRate = null;
    }

    public function isFastest(): bool
    {
        return $this->speed === count(self::SPEEDS) - 1;
    }

    public function isSlowest(): bool
    {
        return 0 === $this->speed;
    }

    public function multiplier(): float
    {
        return self::SPEEDS[$this->speed];
    }

    public function speedLabel(): string
    {
        $multiplier = $this->multiplier();

        return 'x' . ($multiplier < 1 ? rtrim(rtrim(number_format($multiplier, 2), '0'), '.') : (string) (int) $multiplier);
    }

    /**
     * Ticks to run before drawing again.
     *
     * Past the render rate, going faster cannot mean sleeping less — there is
     * no sleep left. It means computing more per frame, which is why high
     * multipliers batch instead of spinning.
     */
    public function ticksPerFrame(): int
    {
        $wanted = $this->multiplier() * self::BASE_RATE;

        return max(1, (int) round($wanted / self::RENDER_RATE));
    }

    /**
     * Microseconds to sleep after a frame. Below the render rate the delay
     * carries the speed; above it, the batch does and the delay just keeps
     * the display watchable.
     */
    public function frameDelay(): int
    {
        $wanted = $this->multiplier() * self::BASE_RATE;
        $rate = min($wanted, self::RENDER_RATE);

        return (int) round(1_000_000 / $rate);
    }

    /**
     * Record the tick rate actually reached, smoothed so the readout does not
     * jitter. Asking for x1000 does not make the machine deliver it.
     */
    public function observe(float $ticksPerSecond): void
    {
        $this->observedRate = null === $this->observedRate
            ? $ticksPerSecond
            : $this->observedRate * 0.7 + $ticksPerSecond * 0.3;
    }

    public function observedMultiplier(): ?float
    {
        return null === $this->observedRate ? null : $this->observedRate / self::BASE_RATE;
    }

    /**
     * True when the machine is not keeping up with the requested speed.
     */
    public function isLagging(): bool
    {
        $observed = $this->observedMultiplier();

        return null !== $observed && $observed < $this->multiplier() * 0.8;
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
