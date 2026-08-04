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

    public function getIa(): IAInterface;

    public function update(World $world): void;
}
