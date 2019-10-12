<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 14:41
 */

namespace Map\Player\Chat;


use Map\Player\Player;
use Map\World\World;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Estomac
{
    protected $nouriture = 10;

    protected $player;

    protected $eventDispatcher;

    public function __construct(Player $player)
    {
        $this->player = $player;

        $this->eventDispatcher = new EventDispatcher();
    }

    public function getNouriture()
    {
        return $this->nouriture;
    }

    public function setNouriture($nouriture)
    {
        $this->nouriture = $nouriture;
    }

    public function update(World $world)
    {
        $world->getLogger()->log(LogLevel::INFO, "[ESTOMAC] Nouriture : ".$this->getNouriture());

        if($this->nouriture == 0 && $world->getTimer()->isTime(1))
        {
            $this->getEventDispatcher()->dispatch("hungry", new Event());

            //$this->player->setLife($this->player->getLife() - 1);

            return;
        }
        else
        {
            $this->getEventDispatcher()->dispatch("full", new Event());
        }

        if($world->getTimer()->isTime(10))
        {
            $this->nouriture--;
        }
    }

    /**
     * @return EventDispatcher
     */
    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }
}