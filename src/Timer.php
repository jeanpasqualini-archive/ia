<?php

declare(strict_types=1);

class Timer
{
    private int $tick = 0;

    public function update(): void
    {
        $this->tick++;
    }

    public function getTick(): int
    {
        return $this->tick;
    }

    /**
     * True once every $every ticks.
     */
    public function isTime(int $every): bool
    {
        return 0 === $this->tick % $every;
    }
}
