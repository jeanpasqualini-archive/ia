<?php

declare(strict_types=1);

namespace IA\Objectif;

use Map\Builder\MapBuilder;
use Map\Path\PathPoint;
use Map\Player\PlayerHasEstomac;
use Map\World\World;
use Psr\Log\LogLevel;

/**
 * Walk to the closest flower, eat it, repeat.
 */
class Manger implements ObjectifInterface
{
    private const NOURISHMENT = 10;

    private ?PathPoint $path = null;

    public function __construct(private PlayerHasEstomac $player)
    {
    }

    public function describe(): string
    {
        if (null === $this->path) {
            return 'Manger : cherche une fleur';
        }

        return sprintf('Manger : va en %s', (string) $this->path->getDestination());
    }

    public function update(World $world): void
    {
        if (null !== $this->path && $this->path->isEnd()) {
            $this->eat($world);
            $this->path = null;
        }

        $this->path ??= $this->findFood($world);

        if (null === $this->path) {
            $world->getLogger()->log(LogLevel::INFO, "le chat n'a plus de nourriture");

            return;
        }

        $world->getLogger()->log(LogLevel::INFO, sprintf(
            'le chat recherche la nourriture (target => %s)',
            (string) $this->path->getDestination()
        ));

        $this->path->update($world);
    }

    private function eat(World $world): void
    {
        $destination = $this->path?->getDestination();

        if (null === $destination || MapBuilder::FLEUR !== $world->getMap()->getItem($destination)) {
            return;
        }

        $world->getLogger()->log(LogLevel::INFO, 'le chat mange');

        $this->player->getEstomac()->setNouriture(
            $this->player->getEstomac()->getNouriture() + self::NOURISHMENT
        );

        $world->getMap()->setItem($destination, MapBuilder::HERBE);
    }

    private function findFood(World $world): ?PathPoint
    {
        $founds = $world->getMap()->findItems($this->player->getPosition(), MapBuilder::FLEUR);

        if ([] === $founds) {
            return null;
        }

        $destination = $founds[0]['point'];

        $world->getLogger()->log(
            LogLevel::INFO,
            '[flower] le chat part vers le point ' . (string) $destination,
            ['pid' => $this->player->getIdentifiant()]
        );

        return new PathPoint($this->player, $destination);
    }
}
