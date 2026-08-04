<?php

declare(strict_types=1);

namespace Tests\InputController;

use InputController\InputControllerInterface;
use InputController\SdlInput;
use PHPUnit\Framework\TestCase;
use Runtime\Sdl;

/**
 * The window's keys, in the vocabulary the loop already understands.
 *
 * **Nothing loaded this class before, and that was the bug.** Its private
 * constants were called `UP` and `LEFT`, which the interface already declares
 * as public — a fatal error the moment PHP reads the file. The suite stayed
 * green all the way to the first `make window`, because a class nothing
 * touches is a class nothing checks. Instantiating it is half of what these
 * tests are for.
 */
final class SdlInputTest extends TestCase
{
    public function testTheClassCanActuallyBeLoaded(): void
    {
        $input = new SdlInput();

        // No window, so no events: the point is that we got this far.
        $input->update();

        self::assertNull($input->getKey());
        self::assertNull($input->getMouse());
    }

    /**
     * The arrows move the view, and they arrive as sentinels rather than as
     * characters so they cannot collide with something typed.
     */
    public function testTheArrowsSpeakTheSameSentinelsAsTheTerminal(): void
    {
        self::assertSame(InputControllerInterface::UP, SdlInput::keyFor(Sdl::SCANCODE_MASK | 82, 0));
        self::assertSame(InputControllerInterface::DOWN, SdlInput::keyFor(Sdl::SCANCODE_MASK | 81, 0));
        self::assertSame(InputControllerInterface::LEFT, SdlInput::keyFor(Sdl::SCANCODE_MASK | 80, 0));
        self::assertSame(InputControllerInterface::RIGHT, SdlInput::keyFor(Sdl::SCANCODE_MASK | 79, 0));
    }

    /**
     * SDL reports the *unshifted* code, so shift has to be applied by hand.
     * Without it `Z` never arrives and the view can only ever zoom one way.
     */
    public function testShiftIsAppliedByHandOrTheViewOnlyZoomsIn(): void
    {
        self::assertSame('z', SdlInput::keyFor(122, 0));
        self::assertSame('Z', SdlInput::keyFor(122, 0x0001));
        self::assertSame('Z', SdlInput::keyFor(122, 0x0002));
    }

    /**
     * The bindings the loop matches on, arriving as the same characters the
     * terminal sends. A second key table is how two front ends end up with two
     * sets of shortcuts.
     */
    public function testTheOrdinaryKeysArriveAsThemselves(): void
    {
        foreach ([' ' => 32, 'n' => 110, 'r' => 114, 'c' => 99, 'q' => 113, 't' => 116, 'f' => 102] as $key => $sym) {
            self::assertSame($key, SdlInput::keyFor($sym, 0), sprintf('la touche %s', $key));
        }

        self::assertSame("\t", SdlInput::keyFor(9, 0), 'tab change de panneau');
        self::assertSame('q', SdlInput::keyFor(27, 0), 'echap vaut quitter');
    }

    public function testAKeyWithNoMeaningIsIgnoredRatherThanInvented(): void
    {
        self::assertNull(SdlInput::keyFor(Sdl::SCANCODE_MASK | 58, 0), 'une touche de fonction');
        self::assertNull(SdlInput::keyFor(0, 0));
    }
}
