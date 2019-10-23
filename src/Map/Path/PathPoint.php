<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 29/12/15
 * Time: 13:47
 */

namespace Map\Path;


use Map\Builder\MapBuilder;
use Map\Location\Direction;
use Map\Location\Point;
use Map\Player\Player;
use Map\World\World;
use Psr\Log\LogLevel;

class PathPoint {

    protected $player;

    protected $destination;

    protected $end;

    public function __construct(Player $player, Point $destination)
    {
        $this->player = $player;

        $this->destination = $destination;

        $this->end = false;
    }

    public function getDestination()
    {
        return $this->destination;
    }

    public function update(World $world)
    {
        $positionPlayer = $this->player->getPosition();

        if($positionPlayer->getX() == $this->destination->getX() && $positionPlayer->getY() == $this->destination->getY())
        {
            $this->end = true;
            return;
        }

        $direction = array(
            "X" => 0,
            "Y" => 0
        );

        //$world->getMap()->setItem($positionPlayer, MapBuilder::HERBE);

        if($positionPlayer->getX() < $this->destination->getX())
        {
            $direction["X"] = 1;
        }

        if($positionPlayer->getX() > $this->destination->getX())
        {
            $direction["X"] = -1;
        }

        if($positionPlayer->getY() < $this->destination->getY())
        {
            $direction["Y"] = 1;
        }

        if($positionPlayer->getY() > $this->destination->getY())
        {
            $direction["Y"] = -1;
        }

        $positionPlayer->setDirection(new Direction($direction["X"], $direction["Y"]));
        $positionPlayer->move();

        $world->getLogger()->log(LogLevel::INFO, "le joeur ".get_class($this->player). " est en X : ".$positionPlayer->getX()." Y : ".$positionPlayer->getY()." et il se déplace en X : ".$direction["X"]." Y : ".$direction["Y"]);
    }

    public function isEnd()
    {
        return $this->end;
    }
}