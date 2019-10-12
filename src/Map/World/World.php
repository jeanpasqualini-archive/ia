<?php
namespace Map\World;
use IA\ApplicationIA;
use InputController\KeyboardInputController;
use Logger\FileLogger;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Memory\MemoryManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Snapshot\Instant;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Timer;

/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 14:02
 */
class World
{
    protected $players;

    protected $map;

    protected $worldIA;

    protected $timer;

    protected $inputController;

    protected $logger;

    protected $eventDispatcher;

    protected $lastUpdateTime = 0;

    protected static $instance;

    /**
     * @return World
     */
    public static function getInstance()
    {
        return self::$instance;
    }

    public function __construct(MapBuilder $map, array $players = array(), LoggerInterface $logger = null)
    {
        self::$instance = $this;

        $this->players = $players;

        $this->map = $map;

        $this->worldIA = new ApplicationIA();

        $this->timer = new Timer();

        $this->inputController = new KeyboardInputController($logger);

        $this->logger = $logger;

        $this->eventDispatcher = new EventDispatcher();
    }

    public function getCachePath()
    {
        return __DIR__."/../../../app/cache/";
    }

    public function getLogPath()
    {
        return __DIR__."/../../../app/logs/";
    }

    public function getInputController()
    {
        return $this->inputController;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    public function update(): bool
    {
        $updateTime = microtime(true);

        $limit = 1000 / 15 / 1000;

        $betweenUpdate = $updateTime - $this->lastUpdateTime;

        if($betweenUpdate < $limit)
        {
            usleep($limit - $betweenUpdate);
            return false;
        }

        $this->lastUpdateTime = $updateTime;

        $this->logger->log(LogLevel::INFO, "Update world");

        $this->timer->update();

        $this->inputController->update();

        $this->worldIA->update($this);

        return true;

   //     $this->memoryManager->getFlashMemory()->addInstant(new Instant($this));
    }

    public function getTimer()
    {
        return $this->timer;
    }

    public function getMap()
    {
        return $this->map;
    }

    public function getPlayerCollection()
    {
        return $this->players;
    }

    public function __wakeup()
    {
        $this->logger = new MultipleLogger();
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }
}