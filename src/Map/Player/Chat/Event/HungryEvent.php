<?php

declare(strict_types=1);

namespace Map\Player\Chat\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The stomach is empty: the cat should look for something to eat.
 */
final class HungryEvent extends Event
{
    public const NAME = 'estomac.hungry';
}
