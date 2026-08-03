<?php

declare(strict_types=1);

namespace Map\Path;

use Map\Location\Direction;
use Map\Location\Point;
use Map\Player\PlayerInterface;
use Map\World\World;
use Psr\Log\LogLevel;

/**
 * Greedy walk towards a destination: one step per tick on each axis, terrain
 * is ignored (nothing blocks movement in this world yet).
 */
class PathPoint
{
    private bool $end = false;

    public function __construct(private PlayerInterface $player, private Point $destination)
    {
    }

    public function getDestination(): Point
    {
        return $this->destination;
    }

    public function update(World $world): void
    {
        $position = $this->player->getPosition();

        if ($position->equals($this->destination)) {
            $this->end = true;

            return;
        }

        $direction = new Direction(
            $this->step($position->getX(), $this->destination->getX()),
            $this->step($position->getY(), $this->destination->getY()),
        );

        $position->setDirection($direction);
        $position->move();

        $world->getLogger()->log(LogLevel::INFO, sprintf(
            '%s est en %s et se deplace en %d;%d',
            $this->player->getIdentifiant(),
            (string) $position,
            $direction->getY(),
            $direction->getX(),
        ));
    }

    public function isEnd(): bool
    {
        return $this->end;
    }

    private function step(int $from, int $to): int
    {
        return $to <=> $from;
    }
}
