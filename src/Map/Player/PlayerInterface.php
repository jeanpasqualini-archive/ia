<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 11:37
 */

namespace Map\Player;

use IA\IAInterface;
use Map\Location\Point;
use Map\World\World;

interface PlayerInterface
{
    public function move();

    public function getPosition(): Point;

    /**
     * @return IAInterface
     */
    public function getIa();

    public function update(World $world);
}