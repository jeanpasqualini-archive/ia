<?php

declare(strict_types=1);

namespace Map\Player\Chat;

use Map\Player\Chat\Event\FullEvent;
use Map\Player\Chat\Event\HungryEvent;
use Map\Player\Player;
use Map\World\World;
use Psr\Log\LogLevel;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Estomac
{
    /** Ticks between two digestions. */
    private const DIGESTION_RATE = 10;

    private int $nouriture = 10;

    private ?EventDispatcher $eventDispatcher = null;

    public function __construct(private Player $player)
    {
    }

    public function getNouriture(): int
    {
        return $this->nouriture;
    }

    public function setNouriture(int $nouriture): void
    {
        $this->nouriture = max(0, $nouriture);
    }

    public function update(World $world): void
    {
        $world->getLogger()->log(
            LogLevel::INFO,
            sprintf('[ESTOMAC] %s nourriture : %d', $this->player->getIdentifiant(), $this->nouriture)
        );

        if (0 === $this->nouriture) {
            $this->getEventDispatcher()->dispatch(new HungryEvent(), HungryEvent::NAME);

            return;
        }

        $this->getEventDispatcher()->dispatch(new FullEvent(), FullEvent::NAME);

        if ($world->getTimer()->isTime(self::DIGESTION_RATE)) {
            $this->nouriture--;
        }
    }

    /**
     * Built on demand: after a snapshot restore, CatIA::__wakeup may ask for
     * the dispatcher before this object's own __wakeup has run.
     */
    public function getEventDispatcher(): EventDispatcher
    {
        return $this->eventDispatcher ??= new EventDispatcher();
    }

    /**
     * The dispatcher holds closures bound to the AI, which cannot be
     * serialized; CatIA re-subscribes on wake up.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['nouriture', 'player'];
    }
}
