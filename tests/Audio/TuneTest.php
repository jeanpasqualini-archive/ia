<?php

declare(strict_types=1);

namespace Tests\Audio;

use Audio\SoundEffect;
use Audio\Synth;
use Audio\Tune;
use PHPUnit\Framework\TestCase;

final class TuneTest extends TestCase
{
    public function testTheNoteTableIsTunedToA440(): void
    {
        self::assertSame(440.0, Tune::frequency('A4'));
        self::assertEqualsWithDelta(880.0, Tune::frequency('A5'), 0.001);
        self::assertEqualsWithDelta(220.0, Tune::frequency('A3'), 0.001);
        self::assertEqualsWithDelta(261.626, Tune::frequency('C4'), 0.001);
        self::assertEqualsWithDelta(277.183, Tune::frequency('C#4'), 0.001);
    }

    public function testAnUnreadableNoteIsRefusedRatherThanPlayedAsSilence(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Tune::frequency('H4');
    }

    /**
     * What the seamless loop rests on. Voices are concatenated note by note,
     * and rounding each note from its own duration in seconds lets them drift
     * apart by a sample here and there — a loop whose tracks end at different
     * points clicks on every repeat.
     */
    public function testEveryVoiceIsExactlyTheSameLength(): void
    {
        $tune = new Tune(new Synth(22050));
        $expected = $tune->lengthInSamples();

        foreach ($tune->tracks() as $name => $pcm) {
            self::assertSame($expected, strlen($pcm), sprintf('la voix %s a derive', $name));
        }

        self::assertSame($expected, strlen($tune->render()));
    }

    public function testTheLoopIsEightSecondsAtAnySampleRate(): void
    {
        foreach ([11025, 22050, 44100] as $rate) {
            $tune = new Tune(new Synth($rate));

            self::assertEqualsWithDelta(8.0, $tune->lengthInSamples() / $rate, 0.001);
        }
    }

    /**
     * The music deliberately leaves part of the range unused so an effect
     * can land on top of it without the sum clipping. If a voice gets louder,
     * this is the test that says the budget no longer adds up.
     */
    public function testTheMusicLeavesRoomForASoundEffect(): void
    {
        $synth = new Synth(22050);
        $music = (new Tune($synth))->render();
        $peak = 0;

        for ($i = 0, $n = strlen($music); $i < $n; $i++) {
            $peak = max($peak, abs(ord($music[$i]) - Synth::MIDPOINT));
        }

        $loudestEffect = 0;

        foreach (SoundEffect::cases() as $effect) {
            $pcm = $effect->render($synth);

            for ($i = 0, $n = strlen($pcm); $i < $n; $i++) {
                $loudestEffect = max($loudestEffect, abs(ord($pcm[$i]) - Synth::MIDPOINT));
            }
        }

        self::assertLessThanOrEqual(Synth::MAX_AMPLITUDE, $peak + $loudestEffect);
    }

    public function testEveryEffectMakesASoundAndNoneOutstaysItsWelcome(): void
    {
        $synth = new Synth(22050);

        foreach (SoundEffect::cases() as $effect) {
            $pcm = $effect->render($synth);
            $seconds = strlen($pcm) / 22050;

            self::assertGreaterThan(0.0, $seconds, $effect->value);

            // Longer than the audio buffer and an effect would still be
            // sounding when the next one is asked for.
            self::assertLessThan(0.5, $seconds, $effect->value);
        }
    }
}
