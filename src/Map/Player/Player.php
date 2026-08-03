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

    public function __construct()
    {
        $this->identifiant = self::FOODS[self::$generatorId % count(self::FOODS)];
        self::$generatorId++;
    }

    public function getIdentifiant(): string
    {
        return $this->identifiant;
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
