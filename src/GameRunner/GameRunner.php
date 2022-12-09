<?php

/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 10:24
 */

namespace GameRunner;

use Logger\FileLogger;
use Logger\MultipleLogger;
use Map\Builder\MapBuilder;
use Map\Provider\RandomMapProvider;
use Map\Player\Chat;
use Map\Render\MapRenderInterface;
use Map\Render\NCurseRender;
use Map\World\World;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Snapshot\Instant;
use Symfony\Component\Console\Input\InputInterface;

class GameRunner
{
    const UPDATE_NORMAL = 1;
    const UPDATE_NONE = 2;
    const UPDATE_SLOW = 3;
    const UPDATE_STEP_BY_STEP = 4;

    private $quit = false;

    private $timeMachineMode = false;
    private $runMode = self::UPDATE_STEP_BY_STEP;
    private $size;

    private $flashName = 'game';

    public function configure(array $options)
    {
        $this->size = exec('tput lines')."x".exec('tput cols');
    }

    public function execute()
    {

            list($line, $colonne) = explode("x", $this->size);

            $logger = new MultipleLogger();
            $logger->addLogger(new FileLogger("/tmp/log/dev.log"));
            $worldContainer = new WorldContainer();
            $memoryManager = new MemoryManager();
            $mapRender = new NCurseRender($line, $colonne, $logger, $worldContainer, $memoryManager);
            $memoryManager->setId($this->flashName);

            $world = $this->createWorld($mapRender, $logger);
            $worldContainer->setWorld($world);

            $mapRender->init();

            $this->render($mapRender, $world);

            while(1) {

                $world->getInputController()->update();

                if ($this->runMode == self::UPDATE_STEP_BY_STEP) {
                    $memoryManager->getFlashMemory()->addInstant(
                        new Instant($world)
                    );

                    $world->getLogger()->info(sprintf(
                        'nombre instantané : %d',
                        $memoryManager->getFlashMemory()->count()
                    ));

                    while ($world->getInputController()->getKey() != 'n') {
                        $world->getInputController()->update();
                        usleep(200);

                        $world = $this->bindController(
                            $world,
                            $worldContainer,
                            $memoryManager,
                            $logger,
                            $mapRender
                        );

                    }
                }

                if ($this->runMode == self::UPDATE_SLOW) {
                    usleep(200);
                    $this->bindController(
                        $world,
                        $worldContainer,
                        $memoryManager,
                        $logger,
                        $mapRender
                    );
                }
                if ($this->update($world)) {
                    $this->render($mapRender, $world);
                }
            }
    }

    protected function bindController(World $world, WorldContainer $worldContainer, MemoryManager $memoryManager, LoggerInterface $logger, MapRenderInterface $mapRender): World
    {
        if($world->getInputController()->getKey() == "x")
        {
            $hash = $memoryManager->persist();
            $logger->log(LogLevel::INFO, 'persist snapshop memory on hash '.$hash);

            $this->render($mapRender, $world);
        }

        if($world->getInputController()->getKey() == "r")
        {
            $logger->log(LogLevel::INFO, 'soft reload game');
            $world = $this->createWorld(
                $mapRender,
                $logger
            );

            $this->render($mapRender, $world);
        }

        if($world->getInputController()->getKey() == "q")
        {
            exit(0);
        }

        if($world->getInputController()->getKey() == "t")
        {
            if ($this->timeMachineMode) {
                $this->timeMachineMode = false;
                $logger->info('mode timemachine désactivé');
            } else {
                $this->timeMachineMode = true;
                $logger->info('mode timemachine activé');
            }

            $this->render($mapRender, $world);
        }

        if($world->getInputController()->getKey() == "s")
        {
            $this->runMode = self::UPDATE_SLOW;
            $logger->info('change mode = slow');
            $this->render($mapRender, $world);
        }

        if($world->getInputController()->getKey() == "b")
        {
            $this->runMode = self::UPDATE_STEP_BY_STEP;
            $logger->info('change mode = step by step');
            $this->render($mapRender, $world);
        }

        if ($this->timeMachineMode) {
            if($world->getInputController()->getKey() == "p")
            {
                if ($instant = $memoryManager->getFlashMemory()->previous()) {
                    /** @var World $world */
                    $world = $instant->getData();

                    $worldContainer->setWorld($world);
                    $world->setLogger($logger);
                } else {
                    $logger->error('unabled to previous time machine');
                }

                $this->render($mapRender, $world);
            }
            if($world->getInputController()->getKey() == "a")
            {
                if ($instant = $memoryManager->getFlashMemory()->after()) {
                    /** @var World $world */
                    $world = $instant->getData();

                    $worldContainer->setWorld($world);
                    $world->setLogger($logger);
                } else {
                    $logger->error('unabled to after time machine');
                }

                $this->render($mapRender, $world);
            }
        }

        return $world;
    }


    protected function addChat($x, $y, $speed = 1)
    {
        $chat = new Chat();
        // $chat->getPosition()->setDirection(new Direction(1, 0));

        $chat->getPosition()->setX($x);

        $chat->getPosition()->setY($y);

       // $chat->getPosition()->setSpeed($speed);

        return $chat;
    }

    public function update(World $world) {
        if ($this->timeMachineMode) {
            return true;
        }

        return $world->update();
    }

    public function render(MapRenderInterface $mapRender, World $world)
    {
        $players = $world->getPlayerCollection();

        $world->getMap()->clearLayer('player');

        foreach($players as $player)
        {
            $world->getMap()->setItem($player->getPosition(), "P", 'player');
        }

        $world->getMap()->updateFinalLayer();
        $mapRender->render($world->getMap()->getFinalMap());

        $mapRender->clear($world->getMap()->getFinalMap());
    }

    /**
     * @param InputInterface $input
     * @param $mapRender
     * @param MultipleLogger $logger
     * @return World
     */
    protected function createWorld(MapRenderInterface $mapRender, MultipleLogger $logger): World
    {
        $sizeMap = $mapRender->getSize();

        $mapProvider = new RandomMapProvider($sizeMap["y"], $sizeMap["x"]);

        $map = new MapBuilder($mapProvider->getMap(), $logger);

        $world = new World($map, array(
            $this->addChat(5, 5, 1),
            $this->addChat(60, 15, 2)
        ), $logger);

        return $world;
    }

    public function __destruct()
    {
        if(extension_loaded("ncurses")) {
            ncurses_end();
        }
    }
}
