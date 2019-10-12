<?php


namespace Map\World;


class WorldContainer
{
    /**
     * @var World
     */
    private $world;

    public function setWorld(World $world)
    {
        $this->world = $world;
    }

    /**
     * @return World
     */
    public function getWorld()
    {
        return $this->world;
    }
}