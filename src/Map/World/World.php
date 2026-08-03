<?php

declare(strict_types=1);

namespace Map\World;

use IA\ApplicationIA;
use InputController\InputControllerInterface;
use InputController\NullInputController;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Player\PlayerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Timer;

class World
{
    /** Target simulation rate, in updates per second. */
    private const UPDATES_PER_SECOND = 15;

    private ApplicationIA $worldIA;

    private Timer $timer;

    private EventDispatcher $eventDispatcher;

    private float $lastUpdateTime = 0.0;

    /**
     * @param list<PlayerInterface> $players
     */
    public function __construct(
        private MapBuilder $map,
        private array $players = [],
        private LoggerInterface $logger = new MultipleLogger(),
        private InputControllerInterface $inputController = new NullInputController(),
    ) {
        $this->worldIA = new ApplicationIA();
        $this->timer = new Timer();
        $this->eventDispatcher = new EventDispatcher();
    }

    public function getInputController(): InputControllerInterface
    {
        return $this->inputController;
    }

    public function setInputController(InputControllerInterface $inputController): void
    {
        $this->inputController = $inputController;
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
     * Returns false when the call was too early and nothing changed, so the
     * caller can skip the render.
     */
    public function update(): bool
    {
        $now = microtime(true);
        $minimumInterval = 1 / self::UPDATES_PER_SECOND;
        $elapsed = $now - $this->lastUpdateTime;

        if ($elapsed < $minimumInterval) {
            // usleep() takes microseconds: the original code passed seconds
            // here, which rounded down to 0 and burned a full core.
            usleep((int) (($minimumInterval - $elapsed) * 1_000_000));

            return false;
        }

        $this->lastUpdateTime = $now;

        $this->logger->log(LogLevel::INFO, 'Update world');

        // Input is drained by the game loop, not here: both draining the same
        // event stream would make each of them miss half the key presses.
        $this->timer->update();
        $this->worldIA->update($this);

        return true;
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
        return ['map', 'players', 'worldIA', 'timer', 'lastUpdateTime'];
    }

    public function __wakeup(): void
    {
        $this->logger = new MultipleLogger();
        $this->inputController = new NullInputController();
        $this->eventDispatcher = new EventDispatcher();
    }
}
