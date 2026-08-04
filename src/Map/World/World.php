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

    public const SURFACE = 'surface';
    public const SOUTERRAIN = 'souterrain';

    /**
     * One map per level, keyed by name.
     *
     * @var array<string, MapBuilder>
     */
    private array $levels;

    /**
     * @param list<PlayerInterface> $players
     * @param array<string, MapBuilder> $levels the surface, plus whatever lies under it
     */
    public function __construct(
        private MapBuilder $map,
        private array $players = [],
        private LoggerInterface $logger = new MultipleLogger(),
        array $levels = [],
    ) {
        $this->levels = [self::SURFACE => $map] + $levels;
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

    /**
     * The surface, which is what anything that does not care about levels
     * means by "the map".
     */
    public function getMap(): MapBuilder
    {
        return $this->map;
    }

    /**
     * The map a player is standing on. Everything that moves, searches or
     * hurts a player goes through this rather than through getMap().
     */
    public function mapFor(PlayerInterface $player): MapBuilder
    {
        return $this->levels[$player->getNiveau()] ?? $this->map;
    }

    public function levelNamed(string $name): ?MapBuilder
    {
        return $this->levels[$name] ?? null;
    }

    /**
     * @return array<string, MapBuilder>
     */
    public function getLevels(): array
    {
        return $this->levels;
    }

    /**
     * Whether a level exists to go down to. A world may perfectly well be all
     * surface — every test builds one.
     */
    public function hasUnderground(): bool
    {
        return isset($this->levels[self::SOUTERRAIN]);
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
        return ['map', 'levels', 'players', 'worldIA', 'timer'];
    }

    public function __wakeup(): void
    {
        $this->logger = new MultipleLogger();
        $this->eventDispatcher = new EventDispatcher();
    }
}
