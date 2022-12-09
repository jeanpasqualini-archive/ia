<?php

namespace Map\Player;
use Map\World\World;
use Map\Location\Point;

/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 11:37
 */
abstract class Player implements PlayerInterface
{
    private static $generatorId = 0;

    const FOODS = ["burger", "salad", "tomato", "oignon"];

    private $identifiant;

    protected $position;

    protected $life = 10;

    protected $resistance = 0;

    protected $puissance = 1;

    protected $eventDispatcher;

    public function __construct()
    {
        $this->identifiant = self::FOODS[self::$generatorId];
        self::$generatorId++;
    }

    public function getIdentifiant(): string
    {
        return $this->identifiant;
    }

    public function getEventDispatcher()
    {
        return $this->eventDispatcher;
    }

    /**
     * @return Point
     */
    public function getPosition(): Point
    {
        return $this->position;
    }

    public function getLife()
    {
        return $this->life;
    }

    public function setLife($life)
    {
        $this->life = $life;
    }

    public function attackBy(\GunInterface $gun)
    {
        $this->setLife($this->getLife() - ($gun->getPuissance() - $this->getResistance()));
    }

    public function attack(Player $player)
    {
        $this->attackBy($this);
    }

    public function getResistance()
    {
        return $this->resistance;
    }

    public function update(World $world)
    {
        if($this->life <= 1)
        {
            throw new \Exception("you are dead");
        }
    }
}