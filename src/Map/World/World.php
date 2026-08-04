<?php

declare(strict_types=1);

namespace Map\World;

use IA\ApplicationIA;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Player\PlayerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Timer;

class World
{
    private ApplicationIA $worldIA;

    private Timer $timer;

    private EventDispatcher $eventDispatcher;

    /**
     * @param list<PlayerInterface> $players
     */
    public function __construct(
        private MapBuilder $map,
        private array $players = [],
        private LoggerInterface $logger = new MultipleLogger(),
    ) {
        $this->worldIA = new ApplicationIA();
        $this->timer = new Timer();
        $this->eventDispatcher = new EventDispatcher();
    }

    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher;
    }

    /**
     * Advance the simulation by one tick.
     *
     * Pacing belongs to the game loop, not here: the world used to sleep on
     * its own to hold 15 ticks per second, which capped every speed above x1
     * and had a domain object calling usleep().
     */
    public function update(): void
    {
        $this->logger->log(LogLevel::INFO, 'Update world');

        // Input is drained by the game loop, not here: both draining the same
        // event stream would make each of them miss half the key presses.
        $this->timer->update();
        $this->worldIA->update($this);
    }

    public function getTimer(): Timer
    {
        return $this->timer;
    }

    public function getMap(): MapBuilder
    {
        return $this->map;
    }

    /**
     * @return list<PlayerInterface>
     */
    public function getPlayerCollection(): array
    {
        return $this->players;
    }

    /**
     * Only the simulated state travels through a snapshot. Services (logger,
     * input, dispatcher) are rebuilt on wake up and re-injected by the caller,
     * which is what keeps the terminal handle out of the serialized payload.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['map', 'players', 'worldIA', 'timer'];
    }

    public function __wakeup(): void
    {
        $this->logger = new MultipleLogger();
        $this->eventDispatcher = new EventDispatcher();
    }
}
