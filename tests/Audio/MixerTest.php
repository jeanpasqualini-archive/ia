<?php

declare(strict_types=1);

namespace Tests\Audio;

use Audio\Mixer;
use Audio\Synth;
use PHPUnit\Framework\TestCase;

final class MixerTest extends TestCase
{
    public function testAddingSilenceChangesNothing(): void
    {
        $base = chr(200) . chr(50) . chr(128);
        $silence = str_repeat(chr(Synth::MIDPOINT), 3);

        self::assertSame($base, Mixer::add($base, $silence));
    }

    /**
     * The one that matters. Two loud samples summed have to stop at the top
     * of the range: allowed to wrap, the loudest possible moment of a tune
     * comes back as the quietest, and that discontinuity is heard as a crack
     * rather than as distortion.
     */
    public function testLoudSamplesSaturateInsteadOfWrapping(): void
    {
        $loud = chr(Synth::MIDPOINT + 100);
        $mixed = Mixer::add($loud, $loud);

        self::assertSame(255, ord($mixed[0]));

        $quiet = chr(Synth::MIDPOINT - 100);
        $mixed = Mixer::add($quiet, $quiet);

        self::assertSame(0, ord($mixed[0]));
    }

    public function testOppositeSamplesCancelBackToSilence(): void
    {
        $mixed = Mixer::add(chr(Synth::MIDPOINT + 40), chr(Synth::MIDPOINT - 40));

        self::assertSame(Synth::MIDPOINT, ord($mixed[0]));
    }

    public function testTheBaseKeepsItsLengthWhateverTheOverlayDoes(): void
    {
        $base = str_repeat(chr(Synth::MIDPOINT), 10);

        self::assertSame(10, strlen(Mixer::add($base, str_repeat(chr(200), 50))));
        self::assertSame(10, strlen(Mixer::add($base, str_repeat(chr(200), 2), 8)));
    }

    public function testAnOverlayLandsWhereItWasOffsetTo(): void
    {
        $base = str_repeat(chr(Synth::MIDPOINT), 5);
        $mixed = Mixer::add($base, chr(Synth::MIDPOINT + 20), 3);

        self::assertSame(Synth::MIDPOINT, ord($mixed[2]));
        self::assertSame(Synth::MIDPOINT + 20, ord($mixed[3]));
        self::assertSame(Synth::MIDPOINT, ord($mixed[4]));
    }

    public function testAnOffsetPastTheEndIsDroppedRatherThanWrapped(): void
    {
        $base = str_repeat(chr(Synth::MIDPOINT), 4);

        self::assertSame($base, Mixer::add($base, chr(255), 10));
    }
}
