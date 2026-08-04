<?php

declare(strict_types=1);

namespace Map\Path;

use Map\Location\Point;
use Map\Player\PlayerInterface;
use Map\World\World;
use Psr\Log\LogLevel;

/**
 * A precomputed route, walked one tile per tick.
 *
 * This replaces the previous greedy walk, which stepped towards the target on
 * both axes and ignored the terrain entirely — fine while the map was noise,
 * absurd once there are lakes to walk into.
 */
class Route
{
    /** @var list<Point> */
    private array $steps;

    private int $index = 0;

    /**
     * @param list<Point> $steps tiles to walk through, destination last
     */
    public function __construct(private PlayerInterface $player, array $steps)
    {
        $this->steps = $steps;
    }

    public function getDestination(): ?Point
    {
        return $this->steps[count($this->steps) - 1] ?? null;
    }

    public function remaining(): int
    {
        return max(0, count($this->steps) - $this->index);
    }

    public function isEnd(): bool
    {
        return $this->index >= count($this->steps);
    }

    public function update(World $world): void
    {
        if ($this->isEnd()) {
            return;
        }

        $position = $this->player->getPosition();
        $next = $this->steps[$this->index];

        // The map changes under the route: another cat may have eaten the
        // flower we were heading for, or the ground itself may have changed.
        if (!$world->getMap()->isWalkable($next)) {
            $this->index = count($this->steps);

            return;
        }

        $position->setX($next->getX());
        $position->setY($next->getY());

        $this->index++;

        $world->getLogger()->log(LogLevel::INFO, sprintf(
            '%s avance en %s, %d cases restantes',
            $this->player->getIdentifiant(),
            (string) $position,
            $this->remaining(),
        ));
    }
}
