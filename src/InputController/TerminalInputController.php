<?php

declare(strict_types=1);

namespace InputController;

use Map\Location\Direction;
use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\Terminal;

/**
 * Reads the keyboard through php-tui's event stream.
 *
 * The previous implementation reopened php://stdin on every tick and read two
 * raw bytes, which lost keys and could not decode escape sequences. The
 * terminal is in raw mode here, so events are already parsed: arrow keys work
 * alongside the historic zqsd bindings.
 */
class TerminalInputController implements InputControllerInterface
{
    private ?string $key = null;

    private Direction $direction;

    public function __construct(private Terminal $terminal)
    {
        $this->direction = new Direction(0, 0);
    }

    public function update(): void
    {
        $this->key = null;

        while (null !== $event = $this->terminal->events()->next()) {
            if ($event instanceof CharKeyEvent) {
                $this->key = $event->char;
                $this->applyDirection($event->char);

                continue;
            }

            if ($event instanceof CodedKeyEvent) {
                // Tab has no character of its own: surface it as one so the
                // game loop can treat every binding uniformly.
                if (KeyCode::Tab === $event->code) {
                    $this->key = "\t";

                    continue;
                }

                $this->direction = match ($event->code) {
                    KeyCode::Left => new Direction(-1, 0),
                    KeyCode::Right => new Direction(1, 0),
                    KeyCode::Up => new Direction(0, -1),
                    KeyCode::Down => new Direction(0, 1),
                    default => $this->direction,
                };
            }
        }
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function getDirection(): Direction
    {
        return $this->direction;
    }

    private function applyDirection(string $char): void
    {
        $this->direction = match ($char) {
            'q' => new Direction(-1, 0),
            'z' => new Direction(0, -1),
            'd' => new Direction(1, 0),
            's' => new Direction(0, 1),
            default => new Direction(0, 0),
        };
    }
}
