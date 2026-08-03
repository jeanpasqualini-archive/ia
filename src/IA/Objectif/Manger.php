<?php

declare(strict_types=1);

namespace IA\Objectif;

use Map\Builder\MapBuilder;
use Map\Path\PathFinder;
use Map\Path\Route;
use Map\Player\PlayerHasEstomac;
use Map\World\World;
use Psr\Log\LogLevel;

/**
 * Walk to the closest reachable flower, eat it, repeat.
 */
class Manger implements ObjectifInterface
{
    private const NOURISHMENT = 10;

    /**
     * Ticks to wait before searching again after a failed search.
     *
     * A failed search is the expensive case: it floods everything reachable
     * before giving up. And eating only ever removes flowers, so the answer
     * can hardly turn positive on its own — this is deliberately generous. At
     * x1 it is twenty seconds of waiting; at x1000, a third of a second.
     */
    private const RETRY_EVERY = 300;

    private ?Route $route = null;

    private int $nextSearch = 0;

    public function __construct(private PlayerHasEstomac $player)
    {
    }

    public function describe(): string
    {
        $destination = $this->route?->getDestination();

        if (null === $destination) {
            return 'Manger : cherche une fleur';
        }

        return sprintf(
            'Manger : va en %s (%d cases)',
            (string) $destination,
            $this->route?->remaining() ?? 0
        );
    }

    public function update(World $world): void
    {
        if (null !== $this->route && $this->route->isEnd()) {
            $this->eat($world);
            $this->route = null;
        }

        $this->route ??= $this->findFood($world);

        if (null === $this->route) {
            $world->getLogger()->log(LogLevel::INFO, "le chat n'a plus de nourriture accessible");

            return;
        }

        $this->route->update($world);
    }

    private function eat(World $world): void
    {
        $destination = $this->route?->getDestination();

        if (null === $destination || MapBuilder::FLEUR !== $world->getMap()->getItem($destination)) {
            return;
        }

        // Only eat what we are standing on: another cat may have got there
        // first, and the route may have been cut short by a terrain change.
        if (!$this->player->getPosition()->equals($destination)) {
            return;
        }

        $world->getLogger()->log(LogLevel::INFO, 'le chat mange');

        $this->player->getEstomac()->setNouriture(
            $this->player->getEstomac()->getNouriture() + self::NOURISHMENT
        );

        $world->getMap()->setItem($destination, MapBuilder::HERBE);
    }

    private function findFood(World $world): ?Route
    {
        $tick = $world->getTimer()->getTick();

        if ($tick < $this->nextSearch) {
            return null;
        }

        $steps = (new PathFinder($world->getMap()))
            ->toNearest($this->player->getPosition(), MapBuilder::FLEUR);

        if (null === $steps || [] === $steps) {
            $this->nextSearch = $tick + self::RETRY_EVERY;

            return null;
        }

        $this->nextSearch = 0;

        $route = new Route($this->player, $steps);

        $world->getLogger()->log(
            LogLevel::INFO,
            sprintf(
                '[flower] le chat part vers %s en %d cases',
                (string) $route->getDestination(),
                count($steps)
            ),
            ['pid' => $this->player->getIdentifiant()]
        );

        return $route;
    }
}
