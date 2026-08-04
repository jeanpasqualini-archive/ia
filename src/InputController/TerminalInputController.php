<?php

declare(strict_types=1);

namespace InputController;

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

    public function __construct(private Terminal $terminal)
    {
    }

    public function update(): void
    {
        $this->key = null;

        while (null !== $event = $this->terminal->events()->next()) {
            if ($event instanceof CharKeyEvent) {
                $this->key = $event->char;

                continue;
            }

            if ($event instanceof CodedKeyEvent) {
                // Tab has no character of its own: surface it as one so the
                // game loop can treat every binding uniformly.
                if (KeyCode::Tab === $event->code) {
                    $this->key = "\t";

                    continue;
                }

                // Arrows move the view. Surfaced as keys so the loop keeps
                // one match over everything the user can press.
                $this->key = match ($event->code) {
                    KeyCode::Left => self::LEFT,
                    KeyCode::Right => self::RIGHT,
                    KeyCode::Up => self::UP,
                    KeyCode::Down => self::DOWN,
                    default => $this->key,
                };
            }
        }
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

}
