<?php

namespace Map\Player;
use IA\CatIA;
use Map\Location\Direction;
use Map\Location\Point;
use Map\Player\Chat\Estomac;
use Map\Render\NCurseRender;
use Map\World\World;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 11:37
 */
class Chat extends Player implements PlayerHasEstomac
{
    protected $ia;

    protected $estomac;

    public function __construct()
    {
        $this->position = new Point(5, 5);

        $this->estomac = new Estomac($this);

        $this->eventDispatcher = new EventDispatcher();

        $this->ia = new CatIA($this);
    }

    public function getEstomac()
    {
        return $this->estomac;
    }

    public function move()
    {
        $this->position->move();
    }

    public function getIa()
    {
        return $this->ia;
    }

    public function update(World $world)
    {
        parent::update($world);

        //$key = ncurses_getch();

        $this->getPosition()->setDirection($world->getInputController()->getDirection());

        $this->estomac->update($world);
    }

    public function __sleep()
    {
        return array(
            "position",
            "ia",
            "estomac"
        );
    }
}