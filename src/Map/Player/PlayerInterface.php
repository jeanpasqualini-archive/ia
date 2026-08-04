<?php

declare(strict_types=1);

namespace Map\Player;

use IA\IAInterface;
use Map\Location\Point;
use Map\World\World;

interface PlayerInterface
{
    public function getPosition(): Point;

    public function getIdentifiant(): string;

    /**
     * How far this player can see, in tiles. Bounds every search it makes.
     */
    public function getVision(): int;

    /**
     * Take damage, and answer how much actually landed.
     */
    public function hurt(int $amount): int;

    /** Which level of the world this player is standing on. */
    public function getNiveau(): string;

    public function setNiveau(string $niveau): void;

    public function getIa(): IAInterface;

    public function update(World $world): void;
}
