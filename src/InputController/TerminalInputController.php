<?php

declare(strict_types=1);

namespace InputController;

use PhpTui\Term\Event\CharKeyEvent;
use PhpTui\Term\Event\CodedKeyEvent;
use PhpTui\Term\Event\MouseEvent;
use PhpTui\Term\KeyCode;
use PhpTui\Term\MouseEventKind;
use PhpTui\Term\Terminal;

/**
 * Reads the keyboard and the mouse through php-tui's event stream.
 *
 * The previous implementation reopened php://stdin on every tick and read two
 * raw bytes, which lost keys and could not decode escape sequences. The
 * terminal is in raw mode here, so events are already parsed.
 */
class TerminalInputController implements InputControllerInterface
{
    private ?string $key = null;

    private ?MouseInput $mouse = null;

    public function __construct(private Terminal $terminal)
    {
    }

    public function update(): void
    {
        $this->key = null;
        $this->mouse = null;

        while (null !== $event = $this->terminal->events()->next()) {
            if ($event instanceof CharKeyEvent) {
                $this->key = $event->char;

                continue;
            }

            if ($event instanceof MouseEvent) {
                $this->readMouse($event);

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

    public function getMouse(): ?MouseInput
    {
        return $this->mouse;
    }

    /**
     * Capture is enabled with any-motion tracking, so a mouse merely crossing
     * the screen emits an event per cell. Those are dropped here rather than
     * carried into the loop, which would then have to ignore them on every
     * frame.
     */
    private function readMouse(MouseEvent $event): void
    {
        $action = match ($event->kind) {
            MouseEventKind::Down => MouseAction::Press,
            MouseEventKind::Drag => MouseAction::Drag,
            MouseEventKind::Up => MouseAction::Release,
            MouseEventKind::ScrollUp => MouseAction::ScrollUp,
            MouseEventKind::ScrollDown => MouseAction::ScrollDown,
            default => null,
        };

        if (null === $action) {
            return;
        }

        $this->mouse = new MouseInput($action, $event->column, $event->row);
    }
}
