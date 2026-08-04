<?php

declare(strict_types=1);

namespace InputController;

use FFI;
use Runtime\Sdl;

/**
 * Keys and mouse read from the window, in the same vocabulary as the terminal.
 *
 * **It answers in exactly what the loop already understands** — a character, or
 * one of the arrow sentinels, or a `MouseInput` in cells — so `GameRunner`
 * needed no branch at all: the same `match` in `pumpInput()` drives both, and
 * a key that works in the terminal works in the window by construction. A
 * second key table is how two front ends end up with two sets of shortcuts.
 *
 * Coordinates come out of SDL in pixels and leave here in cells, because
 * `MouseInput` is documented in cells and the renderer is what decides how big
 * one is. The loop stays spared of both.
 *
 * There is no raw mode and no tty here: SDL owns the window and the keyboard.
 * Which quietly removes the two hazards the terminal front end has to live
 * with — Ctrl+C arriving as a key rather than a signal, and a killed process
 * leaving the terminal unusable.
 */
final class SdlInput implements InputControllerInterface
{
    /**
     * Scancode based key codes carry the mask; these are the four arrows.
     *
     * Named `SCAN_` and not after the direction: the interface already
     * declares public `UP` and `LEFT`, and a private constant of the same name
     * is a fatal error at class load — which no test caught, because nothing
     * loaded the class. {@see keyFor()} is public for that reason.
     */
    private const SCAN_RIGHT = Sdl::SCANCODE_MASK | 79;
    private const SCAN_LEFT = Sdl::SCANCODE_MASK | 80;
    private const SCAN_DOWN = Sdl::SCANCODE_MASK | 81;
    private const SCAN_UP = Sdl::SCANCODE_MASK | 82;

    /** Either shift, which is the whole difference between `z` and `Z`. */
    private const SHIFT = 0x0001 | 0x0002;

    private ?FFI $sdl = null;

    private mixed $event = null;

    private ?string $key = null;

    private ?MouseInput $mouse = null;

    private bool $held = false;

    private bool $quit = false;

    public function __construct(
        private int $cell = 8,
        private Sdl $binding = new Sdl(),
    ) {
    }

    public function update(): void
    {
        $this->key = null;
        $this->mouse = null;

        $this->sdl ??= $this->binding->open();

        if (null === $this->sdl) {
            return;
        }

        $this->event ??= $this->sdl->new('SdlEvent');

        while (0 !== $this->sdl->SDL_PollEvent(FFI::addr($this->event))) {
            match ($this->event->type) {
                Sdl::QUIT => $this->quit = true,
                Sdl::KEYDOWN => $this->key = self::keyFor(
                    $this->event->key->sym,
                    $this->event->key->mod
                ) ?? $this->key,
                // Only the last gesture of a frame is kept, for the same
                // reason as in the terminal: a drag emits one event per pixel
                // crossed, and acting on each in turn moves the view by the
                // same total anyway.
                Sdl::MOUSEBUTTONDOWN => $this->press(),
                Sdl::MOUSEBUTTONUP => $this->release(),
                Sdl::MOUSEMOTION => $this->motion(),
                Sdl::MOUSEWHEEL => $this->wheel(),
                default => null,
            };
        }

        // Closing the window is the same intention as pressing q, and the loop
        // already knows what to do with that.
        if ($this->quit) {
            $this->key = 'q';
            $this->quit = false;
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
     * A key code as the loop spells it.
     *
     * SDL reports the *unshifted* code, so the shift modifier has to be
     * applied by hand — `z` zooms in and `Z` zooms out, and without this the
     * view would only ever go one way.
     *
     * Public because it is the whole of the translation and the only part
     * worth guarding: everything else in this class is SDL's own plumbing,
     * and a test that cannot reach the mapping is a test of nothing.
     */
    public static function keyFor(int $sym, int $mod): ?string
    {
        return match ($sym) {
            self::SCAN_UP => InputControllerInterface::UP,
            self::SCAN_DOWN => InputControllerInterface::DOWN,
            self::SCAN_LEFT => InputControllerInterface::LEFT,
            self::SCAN_RIGHT => InputControllerInterface::RIGHT,
            9 => "\t",
            27 => 'q',
            default => $sym > 0 && $sym < 128
                ? ($mod & self::SHIFT ? strtoupper(chr($sym)) : chr($sym))
                : null,
        };
    }

    private function press(): void
    {
        $this->held = true;
        $this->mouse = new MouseInput(
            MouseAction::Press,
            intdiv($this->event->button->x, $this->cell),
            intdiv($this->event->button->y, $this->cell)
        );
    }

    private function release(): void
    {
        $this->held = false;
        $this->mouse = new MouseInput(
            MouseAction::Release,
            intdiv($this->event->button->x, $this->cell),
            intdiv($this->event->button->y, $this->cell)
        );
    }

    private function motion(): void
    {
        if (!$this->held) {
            return;
        }

        $this->mouse = new MouseInput(
            MouseAction::Drag,
            intdiv($this->event->motion->x, $this->cell),
            intdiv($this->event->motion->y, $this->cell)
        );
    }

    private function wheel(): void
    {
        if (0 === $this->event->wheel->y) {
            return;
        }

        // Where the pointer is is not carried by a wheel event, so the gesture
        // is reported at the middle of the map: the loop only uses the
        // position to decide whether the wheel was over the map at all.
        $this->mouse = new MouseInput(
            $this->event->wheel->y > 0 ? MouseAction::ScrollUp : MouseAction::ScrollDown,
            1,
            1
        );
    }
}
