<?php

declare(strict_types=1);

namespace InputController;

/**
 * Keyboard-less controller, for the tests and for anything driving the game
 * without a terminal attached.
 */
class NullInputController implements InputControllerInterface
{
    public function update(): void
    {
    }

    public function getKey(): ?string
    {
        return null;
    }
}
