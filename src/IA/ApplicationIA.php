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
            $position = $player->getPosition();
            $before = [$position->getX(), $position->getY()];

            $player->getIa()->update($world);
            $player->update($world);

            // Single authority on where a player may stand: whatever moved it
            // (a route, the keyboard, a stale direction), it cannot leave the
            // map nor end up in the water.
            $world->getMap()->clamp($position);

            if (!$world->getMap()->isWalkable($position)) {
                $position->setX($before[0]);
                $position->setY($before[1]);
            }
        }
    }
}
