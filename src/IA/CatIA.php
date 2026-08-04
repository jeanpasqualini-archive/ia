<?php

declare(strict_types=1);

namespace IA;

use IA\Objectif\Manger;
use IA\Objectif\ObjectifInterface;
use Map\Player\Chat;
use Map\Player\Chat\Event\FullEvent;
use Map\Player\Chat\Event\HungryEvent;
use Map\World\World;

/**
 * Reactive AI: the stomach emits events, the AI turns them into goals, and
 * every goal gets a chance to act on each tick.
 */
class CatIA implements IAInterface
{
    /** @var list<ObjectifInterface> */
    private array $objectifs = [];

    public function __construct(private Chat $chat)
    {
        $dispatcher = $this->chat->getEstomac()->getEventDispatcher();
        $dispatcher->addListener(HungryEvent::NAME, $this->onEstomacHungry(...));
        $dispatcher->addListener(FullEvent::NAME, $this->onEstomacFull(...));
    }

    public function onEstomacHungry(): void
    {
        if ([] !== $this->objectifs) {
            return;
        }

        $this->objectifs[] = new Manger($this->chat);
    }

    public function onEstomacFull(): void
    {
        if ([] === $this->objectifs) {
            return;
        }

        $this->objectifs = [];
    }

    /**
     * @return list<ObjectifInterface>
     */
    public function getObjectifs(): array
    {
        return $this->objectifs;
    }

    public function update(World $world): void
    {
        // Goals own the movement, and now they own all of it. There used to
        // be a free-roam step here, driven by the arrow keys, which the map
        // outgrowing the screen made both awkward — the arrows are how one
        // looks around — and pointless, since the AI walks the cat anyway.
        foreach ($this->objectifs as $objectif) {
            $objectif->update($world);
        }
    }

    /**
     * Listeners are closures bound to $this, which cannot be serialized: they
     * are dropped on sleep and rebuilt on wake up.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['chat', 'objectifs'];
    }

    public function __wakeup(): void
    {
        $dispatcher = $this->chat->getEstomac()->getEventDispatcher();
        $dispatcher->addListener(HungryEvent::NAME, $this->onEstomacHungry(...));
        $dispatcher->addListener(FullEvent::NAME, $this->onEstomacFull(...));
    }
}
