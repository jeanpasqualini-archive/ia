<?php

declare(strict_types=1);

namespace Map\Player\Chat\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * The stomach still has food: pending eating goals can be dropped.
 */
final class FullEvent extends Event
{
    public const NAME = 'estomac.full';
}
