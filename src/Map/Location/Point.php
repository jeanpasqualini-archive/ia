<?php

declare(strict_types=1);

namespace Map\Location;

use Stringable;

class Point implements Stringable
{
    public function __construct(private int $x, private int $y, private int $speed = 1)
    {
    }

    public function setSpeed(int $speed): void
    {
        $this->speed = $speed;
    }

    public function increaseSpeed(): void
    {
        $this->speed++;
    }

    public function decreaseSpeed(): void
    {
        if ($this->speed < 2) {
            return;
        }

        $this->speed--;
    }

    public function getX(): int
    {
        return $this->x;
    }

    public function getY(): int
    {
        return $this->y;
    }

    public function setX(int $x): void
    {
        $this->x = $x;
    }

    public function setY(int $y): void
    {
        $this->y = $y;
    }

    /**
     * Manhattan distance, the metric the map uses to rank nearby items.
     */
    public function distanceTo(self $other): int
    {
        return abs($this->x - $other->x) + abs($this->y - $other->y);
    }

    public function equals(self $other): bool
    {
        return $this->x === $other->x && $this->y === $other->y;
    }

    public function __toString(): string
    {
        return $this->y . ';' . $this->x;
    }
}
