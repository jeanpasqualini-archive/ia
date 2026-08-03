<?php

declare(strict_types=1);

namespace InputController;

use Map\Location\Direction;

interface InputControllerInterface
{
    /**
     * Drain whatever the user typed since the last call.
     */
    public function update(): void;

    /**
     * Last key pressed, or null when nothing was typed during this tick.
     */
    public function getKey(): ?string;

    public function getDirection(): Direction;
}
