<?php

declare(strict_types=1);

namespace IA;

use IA\Objectif\Manger;
use IA\Objectif\ObjectifInterface;
use IA\Objectif\SeMettreAlAbri;
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
        // Hunger never interrupts sheltering: the arbitration is what drops
        // the shelter goal, once the cat is well enough to care about food.
        if ([] !== $this->objectifs) {
            return;
        }

        $this->objectifs[] = new Manger($this->chat);
    }

    public function onEstomacFull(): void
    {
        if ([] === $this->objectifs || $this->sheltering()) {
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

    /**
     * Life at which a cat gives up on eating and goes to ground, and the one
     * at which it comes back out. The two are apart on purpose: a single
     * threshold would send a cat up and down at every point of healing.
     */
    private const HURT = 5;

    public function update(World $world): void
    {
        $this->arbitrate($world);

        // Goals own the movement, and now they own all of it. There used to
        // be a free-roam step here, driven by the arrow keys, which the map
        // outgrowing the screen made both awkward — the arrows are how one
        // looks around — and pointless, since the AI walks the cat anyway.
        foreach ($this->objectifs as $objectif) {
            $objectif->update($world);
        }
    }

    /**
     * The first arbitration in this world, and the reason a second drive was
     * worth adding: a badly hurt cat stops looking for food and looks for
     * cover instead. Hunger does not go away — it is simply outranked, and
     * takes over again once the wounds have closed.
     */
    private function arbitrate(World $world): void
    {
        $sheltering = $this->sheltering();

        // Only worth outranking hunger if there is somewhere to go. Taken
        // unconditionally, a hurt cat with no cavern within sight kept the
        // goal for ever and simply stopped eating.
        if ($this->chat->getLife() <= self::HURT
            && !$sheltering
            && SeMettreAlAbri::shelterInSight($world, $this->chat)
        ) {
            $this->objectifs = [new SeMettreAlAbri($this->chat)];

            return;
        }

        // Dropped only once the cat is out again: the goal is what walks it
        // back to the daylight, so ending it underground would strand it
        // there with nothing but hunger to find its way out.
        if ($sheltering
            && $this->chat->getLife() >= SeMettreAlAbri::RECOVERED
            && World::SURFACE === $this->chat->getNiveau()
        ) {
            $this->objectifs = [];
        }
    }

    private function sheltering(): bool
    {
        foreach ($this->objectifs as $objectif) {
            if ($objectif instanceof SeMettreAlAbri) {
                return true;
            }
        }

        return false;
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
