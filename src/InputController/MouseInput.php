<?php

declare(strict_types=1);

namespace InputController;

/**
 * A mouse event reduced to what the game does with it.
 *
 * php-tui reports eight kinds, three buttons and a modifier mask; the loop
 * cares about four gestures and where they happened. Keeping our own shape
 * here means `NullInputController` and the tests never have to build a
 * terminal event.
 *
 * Coordinates are screen cells, counted from the top left of the terminal —
 * the renderer is what turns them into anything meaningful.
 */
final readonly class MouseInput
{
    public function __construct(
        public MouseAction $action,
        public int $column,
        public int $row,
    ) {
    }
}
