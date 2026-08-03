<?php

declare(strict_types=1);

namespace Map\Player;

use IA\IAInterface;
use Map\Location\Point;
use Map\World\World;

interface PlayerInterface
{
    public function move(): void;

    public function getPosition(): Point;

    public function getIdentifiant(): string;

    public function getIa(): IAInterface;

    public function update(World $world): void;
}
