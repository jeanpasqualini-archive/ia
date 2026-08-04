<?php

declare(strict_types=1);

namespace Map\Player;

use Map\Player\Chat\Peur;

/**
 * A player that remembers what hurt it. Kept beside PlayerHasEstomac rather
 * than folded into PlayerInterface: something may well end up in this world
 * that feels nothing.
 */
interface PlayerHasPeur extends PlayerInterface
{
    public function getPeur(): Peur;
}
