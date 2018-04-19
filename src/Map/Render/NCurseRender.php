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
use Map\Builder\MapBuilder;
use Map\Render\Curse\LogView;
use Map\Render\Curse\Map;
use Map\Render\Curse\Statictic;
use Map\Render\Curse\Timer;
use CurseFramework\Listbox;

class NCurseRender extends Ncurses implements MapRenderInterface {

    protected $standardOutput;

    protected $window;

    protected $cursor;

    protected $map;

    protected $listbox;

    protected $statistic;

    protected $timer;

    protected $loggerView;

    protected $logger;

    protected static $instance;

    public function getWindow()
    {
        return $this->map;
    }

    public static function getInstance()
    {
        return self::$instance;
    }

    public function setLogger($logger)
    {
        $this->logger = $logger;
    }

    public function __construct($line, $colonne)
    {
        parent::__construct();

        self::$instance = $this;

        if(!extension_loaded("ncurses"))
        {
            throw new \Exception("curse not supported");
        }

        $this->window = new Window();
        $this->window->border();
        $this->window->getMaxYX($y, $x);

        $this->timer = new Timer(3, 10, 5, 1);
        $this->timer->border();

        $this->listbox = new Listbox($this->window, 5, 20, 10, 1);
        $this->listbox->border();
        $this->listbox->setItems(array(
            array("title" => "Quitter", "bold" => false),
            array("title" => "Regenerer map", "bold" => false),
        ));

        $this->statistic = new Statictic($this->window, 5, 20, 20, 1);
        $this->statistic->border();

        $this->loggerView = new LogView(10, $x - 2, $y - 12, 1);
        $this->loggerView->border();

        $this->map = new Map($y/2, $x/2, 5, ($x/2/2));
        $this->map->border();

        $this->cursor = new Cursor(0, 0, false);
        ncurses_keypad($this->window->getWindow(), true);
    }

    public function getSize()
    {
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

        $this->map->setChanged(true);
        $this->map->draw($map);
        $this->map->refresh();

        $this->timer->setChanged(true);
        $this->timer->draw();
        $this->timer->refresh();

        $this->loggerView->setChanged(true);
        $this->loggerView->draw();
        $this->loggerView->refresh();

        //$this->listbox->setChanged(false);
        $this->listbox->drawList();
        $this->listbox->refresh();
        //usleep(100000);
    }
    public function clear($map)
    {
    }


}