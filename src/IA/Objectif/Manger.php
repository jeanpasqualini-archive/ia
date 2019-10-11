<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 29/12/15
 * Time: 12:58
 */

namespace IA\Objectif;


use Map\Builder\MapBuilder;
use Map\Location\Direction;
use Map\Location\Point;
use Map\Path\PathPoint;
use Map\Player\Player;
use Map\Player\PlayerHasEstomac;
use Map\World\World;
use Psr\Log\LogLevel;

class Manger {

    protected $player;

    /** @var PathPoint */
    protected $path;

    public function __construct(PlayerHasEstomac $player)
    {
        $this->player = $player;
    }

    public function update(World $world)
    {
        if($this->path !== null && $this->path->isEnd())
        {
            $item = $world->getMap()->getItem($this->path->getDestination());

            if($item == MapBuilder::FLEUR)
            {
                $world->getLogger()->log(LogLevel::INFO, "le chat mange");

                $this->player->getEstomac()->setNouriture($this->player->getEstomac()->getNouriture() + 10);

                $world->getMap()->setItem($this->path->getDestination(), MapBuilder::HERBE);
            }

            $this->path = null;
        }

        if($this->path === null)
        {
            $newPoint = $world->getMap()->findItem(MapBuilder::FLEUR);

            if($newPoint !== null)
            {
                $world->getLogger()->log(LogLevel::INFO, "le chat part vers le point ".$newPoint->getX().", ".$newPoint->getY());

                $this->path = new PathPoint($this->player, $newPoint);
            }
        }

        if($this->path !== null)
        {
            $world->getLogger()->log(LogLevel::INFO, "le chat est à la recherche de nourriture");

            $this->path->update($world);
        }
        else
        {
            $world->getLogger()->log(LogLevel::INFO, "le chat n'a plus de nourriture");
        }
    }
}