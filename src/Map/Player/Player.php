<?php

declare(strict_types=1);

namespace Map\Player;

use Map\Location\Point;
use Map\World\World;

abstract class Player implements PlayerInterface
{
    private static int $generatorId = 0;

    private const FOODS = ['burger', 'salad', 'tomato', 'oignon'];

    public const MAX_LIFE = 10;

    /** Ticks between two points of life coming back. */
    private const HEALING_RATE = 40;

    protected string $identifiant;

    protected Point $position;

    /**
     * A running account of how much pain has been taken, not a countdown to
     * dying. See update() for why there is no death.
     */
    protected int $life = self::MAX_LIFE;

    protected int $resistance = 0;

    protected int $puissance = 1;

    /**
     * Wide enough that a cat in a meadow usually has a flower in sight, tight
     * enough that a bounded search stays cheap: the flood visits on the order
     * of a couple of thousand tiles rather than the whole map.
     */
    protected int $vision = 25;

    /**
     * Which level the player is standing on. A cat that walks into a cavern
     * changes this and nothing else — its coordinates are the same on both
     * maps, so the tunnel comes out under the hole it went in by.
     */
    protected string $niveau = World::SURFACE;

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

    public function getNiveau(): string
    {
        return $this->niveau;
    }

    public function setNiveau(string $niveau): void
    {
        $this->niveau = $niveau;
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

    /**
     * Take damage, and answer how much actually landed. Nothing goes below
     * zero: a wound that cannot be felt is not a wound.
     */
    public function hurt(int $amount): int
    {
        $taken = max(0, min($amount - $this->resistance, $this->life));
        $this->life -= $taken;

        return $taken;
    }

    public function update(World $world): void
    {
        // There is deliberately no death. A cat that died would have to leave
        // the world, the AI panel and the tab selection, and mortality is not
        // what pain is here for — life is the running account of how much of
        // it was taken, which is what makes a wary cat measurably better off
        // than a reckless one.
        //
        // It heals slowly so that account is about recent experience rather
        // than about the whole run.
        if ($this->life < self::MAX_LIFE && $world->getTimer()->isTime(self::HEALING_RATE)) {
            $this->life++;
        }
    }
}
