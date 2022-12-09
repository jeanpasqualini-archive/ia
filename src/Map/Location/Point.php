<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 10:58
 */

namespace Map\Location;


class Point
{
    protected $x;

    protected $y;

    protected $speed = 1;

    protected $direction;

    public function __construct($x, $y)
    {
        $this->direction = new Direction(0, 0);

        $this->x = $x;

        $this->y = $y;
    }

    public function setSpeed($speed)
    {
        $this->speed = $speed;
    }

    public function increaseSpeed()
    {
        $this->speed ++;
    }

    public function decreaseSpeed()
    {
        if($this->speed < 2) return;

        $this->speed --;
    }

    public function getX()
    {
        return $this->x;
    }

    public function getY()
    {
        return $this->y;
    }

    public function setX($x)
    {
        $this->x = $x;
    }

    public function setY($y)
    {
        $this->y = $y;
    }

    public function move()
    {
        $this->x = $this->x + ($this->direction->getX() * $this->speed);

        $this->y = $this->y + ($this->direction->getY() * $this->speed);
    }

    public function setDirection(Direction $direction)
    {
        $this->direction = $direction;
    }

    public function __toString()
    {
        return $this->y.";".$this->x;
    }
}