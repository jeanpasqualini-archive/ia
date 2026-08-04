<?php

declare(strict_types=1);

namespace InputController;

interface InputControllerInterface
{
    /**
     * The arrow keys, surfaced as keys rather than as a direction.
     *
     * They used to steer the cat, and became the way to move the view once
     * the map outgrew the screen. Sentinel strings rather than characters, so
     * they cannot collide with something the user actually typed and the loop
     * can keep a single match over everything that can be pressed.
     */
    public const UP = 'ArrowUp';
    public const DOWN = 'ArrowDown';
    public const LEFT = 'ArrowLeft';
    public const RIGHT = 'ArrowRight';

    /**
     * Drain whatever the user typed since the last call.
     */
    public function update(): void;

    /**
     * Last key pressed, or null when nothing was typed during this tick.
     */
    public function getKey(): ?string;
}
