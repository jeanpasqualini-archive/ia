<?php

declare(strict_types=1);

namespace IA;

use Map\World\World;

/**
 * Drives every player's own AI, once per tick.
 */
class ApplicationIA implements IAInterface
{
    public function update(World $world): void
    {
        foreach ($world->getPlayerCollection() as $player) {
            $player->getIa()->update($world);
            $player->update($world);

            // Single authority on bounds: whatever moved the player (a goal,
            // the keyboard, a stale direction), it cannot leave the map.
            $world->getMap()->clamp($player->getPosition());
        }
    }
}
