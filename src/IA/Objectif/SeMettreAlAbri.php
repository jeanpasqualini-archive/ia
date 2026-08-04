<?php

declare(strict_types=1);

namespace IA\Objectif;

use Map\Builder\MapBuilder;
use Map\Path\CostBiasInterface;
use Map\Path\PathFinder;
use Map\Path\Route;
use Map\Player\PlayerHasPeur;
use Map\Player\PlayerInterface;
use Map\World\World;
use Psr\Log\LogLevel;

/**
 * Get out of the weather and let the wounds close.
 *
 * This is the second drive, and the underground exists because of it. The
 * first reason tried for going down was hunger — descend when there is
 * nothing left to eat up here — and it could never fire: a cat sees twenty
 * five tiles in every direction, some two thousand of them, and the meadow
 * carries food on four percent of its surface. Measured over four thousand
 * ticks, a cat was hungry for a hundred and seventy six of them and *never
 * once* had nothing in sight. A condition that cannot occur is not a
 * behaviour.
 *
 * Being hurt does occur. And it points somewhere in particular: down there
 * nothing stings, nothing poisons and the ground does not give way, so
 * sheltering is not an arbitrary errand — it is the one place in this world
 * that is safe, which is what makes the caverns worth cutting.
 */
class SeMettreAlAbri implements ObjectifInterface
{
    /** Life at which the wounds are closed enough to go back out. */
    public const RECOVERED = 9;

    private ?Route $route = null;

    public function __construct(private PlayerInterface $player)
    {
    }

    /**
     * Whether a way down is within sight. Asked before the goal is taken,
     * because a shelter goal that cannot be satisfied outranks hunger for
     * ever.
     */
    public static function shelterInSight(World $world, PlayerInterface $player): bool
    {
        if (!$world->hasUnderground() || World::SOUTERRAIN === $player->getNiveau()) {
            return false;
        }

        return null !== (new PathFinder($world->mapFor($player)))->toNearest(
            $player->getPosition(),
            MapBuilder::CAVERNE,
            $player->getVision()
        );
    }

    public function describe(): string
    {
        if (World::SOUTERRAIN === $this->player->getNiveau()) {
            return sprintf('A l abri, se remet (%d)', $this->player->getLife());
        }

        $destination = $this->route?->getDestination();

        return null === $destination
            ? 'Cherche un abri'
            : sprintf('Vers l abri -> %s (%d)', (string) $destination, $this->route?->remaining() ?? 0);
    }

    public function update(World $world): void
    {
        if (null !== $this->route && $this->route->isEnd()) {
            $this->route = null;
        }

        if (World::SOUTERRAIN === $this->player->getNiveau()) {
            // Still mending: stay put and let the healing in Player::update
            // do its work. Mended: climb back out the way one came.
            //
            // The goal has to walk the cat back up itself. Leaving that to
            // hunger does not work: coming out was meant to happen when there
            // was nothing left to eat down here, and with sight covering two
            // thousand tiles that condition never arrives. Measured, cats went
            // down once and spent seventy eight percent of the run below.
            if ($this->player->getLife() < self::RECOVERED) {
                // Rest *beside* the way out, never on it. A cat healing on
                // the cavern tile itself could not find its exit afterwards:
                // toNearest excludes the tile one is standing on, and the
                // next cavern is thirty tiles away, well out of sight.
                $this->route ??= $this->stepOffTheCavern($world);
                $this->route?->update($world);

                return;
            }

            $this->route ??= $this->findCavern($world);
            $this->route?->update($world);

            return;
        }

        $this->route ??= $this->findCavern($world);

        // No way down within sight. The cat stays where it is rather than
        // wandering off hurt — it will look again as the search is retried.
        $this->route?->update($world);
    }

    /**
     * One step off the entrance, if that is where the cat is standing.
     */
    private function stepOffTheCavern(World $world): ?Route
    {
        $map = $world->mapFor($this->player);

        if (MapBuilder::CAVERNE !== $map->getItem($this->player->getPosition())) {
            return null;
        }

        $steps = (new PathFinder($map, $this->bias()))->toNearest(
            $this->player->getPosition(),
            MapBuilder::GALERIE,
            3
        );

        return null === $steps || [] === $steps ? null : new Route($this->player, $steps);
    }

    private function findCavern(World $world): ?Route
    {
        if (!$world->hasUnderground()) {
            return null;
        }

        $steps = (new PathFinder($world->mapFor($this->player), $this->bias()))->toNearest(
            $this->player->getPosition(),
            MapBuilder::CAVERNE,
            $this->player->getVision()
        );

        if (null === $steps || [] === $steps) {
            return null;
        }

        $world->getLogger()->log(
            LogLevel::INFO,
            sprintf(
                '[abri] %s cherche une caverne pour %s',
                $this->player->getIdentifiant(),
                World::SOUTERRAIN === $this->player->getNiveau() ? 'ressortir' : 'se terrer'
            ),
            ['pid' => $this->player->getIdentifiant()]
        );

        return new Route($this->player, $steps);
    }

    private function bias(): ?CostBiasInterface
    {
        return $this->player instanceof PlayerHasPeur ? $this->player->getPeur() : null;
    }
}
