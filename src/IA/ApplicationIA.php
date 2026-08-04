<?php

declare(strict_types=1);

namespace IA;

use Map\Player\Chat\Peur;
use Map\Player\PlayerHasPeur;
use Map\Player\PlayerInterface;
use Map\World\World;
use Psr\Log\LogLevel;

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

            $this->feelGround($world, $player);
        }
    }

    /**
     * Some ground bites, and being the authority on where a player stands is
     * also being the only thing that knows it is standing there.
     *
     * The memory is written from the same place the damage is taken, so what
     * the cat learns is exactly what it felt — cues read at the tile it is on
     * at the moment it is hurt, not reconstructed afterwards.
     */
    private function feelGround(World $world, PlayerInterface $player): void
    {
        $map = $world->getMap();
        $position = $player->getPosition();
        $damage = $map->hurts($position);

        if (0 === $damage) {
            return;
        }

        $taken = $player->hurt($damage);

        if (0 === $taken) {
            return;
        }

        $world->getLogger()->log(LogLevel::INFO, sprintf(
            '[douleur] %s se pique en %s, vie %d',
            $player->getIdentifiant(),
            (string) $position,
            $player->getLife(),
        ), ['pid' => $player->getIdentifiant()]);

        if ($player instanceof PlayerHasPeur) {
            $player->getPeur()->remember(
                Peur::cues($map, $position->getX(), $position->getY()),
                (float) $taken
            );
        }
    }
}
