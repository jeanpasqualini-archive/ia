<?php

declare(strict_types=1);

namespace InputController;

use Map\Location\Direction;

/**
 * Keyboard-less controller, used by the tests and after a snapshot restore
 * (a deserialized World has no terminal attached until one is injected back).
 */
class NullInputController implements InputControllerInterface
{
    private Direction $direction;

    public function __construct()
    {
        $this->direction = new Direction(0, 0);
    }

    public function update(): void
    {
    }

    public function getKey(): ?string
    {
        return null;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }
}
