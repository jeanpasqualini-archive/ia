<?php

declare(strict_types=1);

namespace Map\Location;

use Stringable;

class Point implements Stringable
{
    private Direction $direction;

    public function __construct(private int $x, private int $y, private int $speed = 1)
    {
        $this->direction = new Direction(0, 0);
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

    public function move(): void
    {
        $this->x += $this->direction->getX() * $this->speed;
        $this->y += $this->direction->getY() * $this->speed;
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

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    public function setDirection(Direction $direction): void
    {
        $this->direction = $direction;
    }

    public function __toString(): string
    {
        return $this->y . ';' . $this->x;
    }
}
