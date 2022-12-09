<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 27/12/15
 * Time: 17:29
 */

namespace Map\Render;


use CurseFramework\Cursor;
use CurseFramework\Ncurses;
use CurseFramework\Window;
use Logger\BufferLogger;
use Map\Builder\MapBuilder;
use Map\Render\Curse\DebugView;
use Map\Render\Curse\LogView;
use Map\Render\Curse\Map;
use Map\Render\Curse\Statictic;
use Map\Render\Curse\TimeMachineView;
use Map\Render\Curse\Timer;
use CurseFramework\Listbox;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use Psr\Log\LoggerInterface;

class NCurseRender extends Ncurses implements MapRenderInterface {

    protected $standardOutput;

    protected $window;

    protected $cursor;

    /** @var Map */
    protected $map;

    protected $listbox;

    protected $statistic;

    protected $timer;

    protected $loggerView;

    protected $timeMachineView;

    /** @var LoggerInterface */
    protected $logger;

    protected static $instance;
    /**
     * @var WorldContainer
     */
    private $worldContainer;
    /**
     * @var MemoryManager
     */
    private $memoryManager;

    /** @var bool */
    private $initialized = false;

    public function getWindow()
    {
        return $this->map;
    }

    public static function getInstance()
    {
        return self::$instance;
    }

    public function init()
    {
        if ($this->initialized) {
            return;
        }

        if(!extension_loaded("ncurses"))
        {
            throw new \Exception("curse not supported");
        }

        ncurses_init();
        $this->initialized = true;

        $this->window = new Window();
        $this->window->border();
        $this->window->getMaxYX($y, $x);

        $this->timer = new Timer(3, 10, 5, 1);
        $this->timer->border();

        $this->listbox = new Listbox($this->window, 12, 25, 8, 1);
        $this->listbox->border();
        $this->listbox->setItems(array(
            array("title" => "Aide ?", "bold" => false),
            array("title" => "q: Quitter", "bold" => false),
            array("title" => "x: Persister memoire", "bold" => false),
            array("title" => "r: Nouvelle map", "bold" => false),
            array("title" => "t: Mode timemachine", "bold" => false),
            array("title" => "s: Mode lent", "bold" => false),
            array("title" => "b: Mode pas a pas", "bold" => false),
            array("title" => "p: Previous snapshot", "bold" => false),
            array("title" => "a: Next snapshot", "bold" => false),
            array("title" => "n: Prochaine iteration", "bold" => false),
        ));

        $this->statistic = new Statictic($this->window, 5, 20, 20, 1);
        $this->statistic->border();

        $bufferLogger = new BufferLogger();
        $this->logger->addLogger($bufferLogger);
        $this->loggerView = new LogView(10, $x - 2, $y - 12, 1);
        $this->loggerView->border();
        $this->loggerView->setBufferLog($bufferLogger);

        $this->map = new Map($y/2, $x/2, 5, ($x/2/2));
        $this->map->border();

        $this->timeMachineView = new TimeMachineView(3, 15, $y - 15, ($x / 2) - 5);
        $this->timeMachineView->setMemoryManager($this->memoryManager);
        $this->timeMachineView->border();


        $this->debug = new DebugView($this->window, 20, 28, 5, $x - 30);
        $this->debug->border();
        $this->debug->setWorldContainer($this->worldContainer);

        $this->cursor = new Cursor(0, 0, false);
        ncurses_keypad($this->window->getWindow(), true);
    }

    public function __construct($line, $colonne, LoggerInterface $logger, WorldContainer $worldContainer, MemoryManager $memoryManager)
    {
        $this->logger = $logger;
        $this->worldContainer = $worldContainer;
        $this->memoryManager = $memoryManager;

        self::$instance = $this;
    }

    public function getSize(): array
    {
        $this->init();
        $this->window->getMaxYX($y, $x);
        return array(
            "x" => $x/2,
            "y" => $y/2
        );
    }

    public function render($map)
    {
        $this->window->setChanged(true);
        $this->window->refresh();

        $this->statistic->update();
        $this->statistic->drawList();
        $this->statistic->refresh();

        $this->debug->update();
        $this->debug->drawList();
        $this->debug->refresh();

        $this->map->setChanged(true);
        $this->map->draw($map);
        $this->map->refresh();

        $this->timer->setChanged(true);
        $this->timer->draw();
        $this->timer->refresh();

        $this->loggerView->setChanged(true);
        $this->loggerView->draw();
        $this->loggerView->refresh();

        $this->timeMachineView->setChanged(true);
        $this->timeMachineView->draw();
        $this->timeMachineView->refresh();

        //$this->listbox->setChanged(false);
        $this->listbox->drawList();
        $this->listbox->refresh();
        //usleep(100000);
    }
    public function clear($map)
    {
    }

    public function close()
    {
        ncurses_end();
    }


}