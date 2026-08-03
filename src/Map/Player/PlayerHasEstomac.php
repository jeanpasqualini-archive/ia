<?php

declare(strict_types=1);

namespace Map\Player;

use Map\Player\Chat\Estomac;

interface PlayerHasEstomac extends PlayerInterface
{
    public function getEstomac(): Estomac;
}
