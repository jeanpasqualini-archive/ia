<?php

namespace Map\Builder;
use Logger\MultipleLogger;
use Map\Location\Point;
use Map\Player\Player;

/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 10:53
 */
class MapBuilder
{
    protected $layers;

    private $finalLayer = [];

    const HERBE = "X";
    const ARBRE = "Y";
    const EAU = "E";
    const FLEUR = "F";

    protected static $allowedItems = array(
        self::HERBE,
        self::ARBRE,
        self::EAU,
        self::FLEUR
    );

    /** @var int[] */
    private $positionLayers = [
        'map' => 1,
        'player' => 2,
    ];

    /** @var MultipleLogger */
    private $logger;

    public function __construct(array $map, MultipleLogger $logger)
    {
        $this->layers['map'] = $this->transformRawMap($map);
        $this->logger = $logger;
    }

    public static function getAllowedItems()
    {
        return self::$allowedItems;
    }

    public function findItems(Point $point, $item, $layer = 'map')
    {
        $found = [];

        foreach($this->layers[$layer] as $y => $line)
        {
            foreach($line as $x => $colonne)
            {
                if($this->layers[$layer][$y][$x] == $item)
                {
                    $found[] = [
                        'distance' => abs($point->getX() - $x) + abs($point->getY() - $y),
                        'point' => new Point($x, $y),
                    ];
                }
            }
        }

        usort($found, function($a, $b) {
           if ($a['distance'] == $b['distance'])
           {
               return 0;
           }

           return ($a['distance'] < $b['distance']) ? -1 : 1;
        });

        return $found;
    }

    public function getItem(Point $position, $layer = 'map')
    {
        return $this->layers[$layer][$position->getY()][$position->getX()];
    }

    public function crop($x, $y)
    {
        $this->layers = array_slice($this->layers, 0, $y);

        foreach($this->layers as $line)
        {
            foreach($line as $colonne)
            {
                $this->layers[$y] = array_slice($this->layers[$y], 0, $x);
            }
        }
    }

    protected function transformRawMap($map)
    {
        $mapArray = array();

        foreach($map as $line)
        {
            $mapArray[] = str_split($line);
        }

        return $mapArray;
    }

    public function setItem(Point $position, $item, $layer = 'map')
    {
        $this->layers[$layer][$position->getY()][$position->getX()] = $item;

        //$maximumVisible = max($this->positionLayers);
    }

    public function clearLayer($layer)
    {
        $this->layers[$layer] = [];
    }

    public function updateFinalLayer()
    {
        foreach ($this->layers as $layer)
        {
            foreach($layer as $y => $line)
            {
                foreach($line as $x => $colonne)
                {
                    $this->finalLayer[$y][$x] = $layer[$y][$x];
                }
            }
        }
    }

    public function getFinalMap()
    {
        return $this->finalLayer;
    }
}