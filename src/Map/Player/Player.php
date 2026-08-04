<?php

declare(strict_types=1);

namespace Map\Player;

use Map\Gun\GunInterface;
use Map\Location\Point;
use Map\World\World;
use RuntimeException;

abstract class Player implements PlayerInterface
{
    private static int $generatorId = 0;

    private const FOODS = ['burger', 'salad', 'tomato', 'oignon'];

    protected string $identifiant;

    protected Point $position;

    protected int $life = 10;

    protected int $resistance = 0;

    protected int $puissance = 1;

    /**
     * Wide enough that a cat in a meadow usually has a flower in sight, tight
     * enough that a bounded search stays cheap: the flood visits on the order
     * of a couple of thousand tiles rather than the whole map.
     */
    protected int $vision = 25;

    public function __construct()
    {
        $this->identifiant = self::FOODS[self::$generatorId % count(self::FOODS)];
        self::$generatorId++;
    }

    public function getIdentifiant(): string
    {
        return $this->identifiant;
    }

    /**
     * How far this player can see, in tiles.
     *
     * A cat used to know where every flower on the map was, which was fine
     * while the map was the size of the screen and is both wrong and
     * expensive once it is not: a search that finds nothing has to flood
     * everything reachable, so its cost followed the size of the world.
     * Seeing a limited distance bounds that, and is what a cat does anyway.
     *
     * Per player rather than a global constant, so a future breed — or a
     * biome that shortens sight — has somewhere to live.
     */
    public function getVision(): int
    {
        return $this->vision;
    }

    public function getPosition(): Point
    {
        return $this->position;
    }

    public function getLife(): int
    {
        return $this->life;
    }

    public function setLife(int $life): void
    {
        $this->life = $life;
    }

    public function getPuissance(): int
    {
        return $this->puissance;
    }

    public function getResistance(): int
    {
        return $this->resistance;
    }

    public function attackBy(GunInterface $gun): void
    {
        $this->setLife($this->getLife() - ($gun->getPuissance() - $this->getResistance()));
    }

    public function update(World $world): void
    {
        if ($this->life <= 1) {
            throw new RuntimeException(sprintf('%s est mort', $this->identifiant));
        }
    }
}
