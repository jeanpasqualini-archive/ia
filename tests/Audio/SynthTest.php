<?php

declare(strict_types=1);

namespace Tests\Audio;

use Audio\Synth;
use PHPUnit\Framework\TestCase;

final class SynthTest extends TestCase
{
    public function testSilenceIsTheMidpointAndNothingElse(): void
    {
        $pcm = (new Synth(1000))->silence(0.5);

        self::assertSame(500, strlen($pcm));
        self::assertSame(str_repeat(chr(Synth::MIDPOINT), 500), $pcm);
    }

    public function testASquareAlternatesAtTheFrequencyItWasAskedFor(): void
    {
        $synth = new Synth(22050);
        $pcm = $synth->square(440.0, 1.0, 40);

        // One period is one rise and one fall, so a second of a 440 Hz tone
        // crosses the midpoint 880 times.
        self::assertEqualsWithDelta(880, $this->transitions($pcm), 4);
    }

    public function testNoSampleEverLeavesTheByte(): void
    {
        $synth = new Synth(22050);

        // The loudest a single voice may be. Anything that overflows here
        // would come back as the opposite extreme and be heard as a crack.
        $pcm = $synth->square(220.0, 0.2, Synth::MAX_AMPLITUDE)
            . $synth->sweep(200.0, 2000.0, 0.2, Synth::MAX_AMPLITUDE)
            . $synth->noise(0.2, Synth::MAX_AMPLITUDE);

        for ($i = 0, $n = strlen($pcm); $i < $n; $i++) {
            $value = ord($pcm[$i]);

            self::assertGreaterThanOrEqual(0, $value);
            self::assertLessThanOrEqual(255, $value);
        }
    }

    public function testTheDecayActuallyFadesTheNoteOut(): void
    {
        $synth = new Synth(22050);
        $pcm = $synth->square(440.0, 0.5, 100, decay: 1.0);

        self::assertGreaterThan(90, $this->amplitudeAt($pcm, 0));
        self::assertLessThan(10, $this->amplitudeAt($pcm, strlen($pcm) - 1));
    }

    /**
     * The noise channel is a shift register rather than a random number
     * generator, which is what makes a run reproducible — and lets a test say
     * anything at all about it.
     */
    public function testNoiseIsReproducible(): void
    {
        $synth = new Synth(22050);

        self::assertSame($synth->noise(0.1, 30), $synth->noise(0.1, 30));
    }

    public function testNoiseIsNotAToneInDisguise(): void
    {
        $synth = new Synth(22050);
        $pcm = $synth->noise(0.5, 30, decay: 0.0, step: 8);

        // A square at this step rate would transition on every step; noise
        // holds its level across some of them, so it must transition
        // noticeably less often than that ceiling.
        $ceiling = strlen($pcm) / 8;

        self::assertGreaterThan(100, $this->transitions($pcm));
        self::assertLessThan($ceiling * 0.75, $this->transitions($pcm));
    }

    /**
     * A sweep derived from the sample index and the current frequency jumps
     * the waveform every time the pitch moves, which clicks. Accumulating the
     * phase is what keeps it continuous — and a continuous wave never moves
     * from one extreme to the other without the amplitude staying put.
     */
    public function testASweepStaysBetweenItsTwoFrequencies(): void
    {
        $synth = new Synth(22050);
        $pcm = $synth->sweep(200.0, 800.0, 1.0, 40, decay: 0.0);
        $transitions = $this->transitions($pcm);

        self::assertGreaterThan(2 * 200, $transitions);
        self::assertLessThan(2 * 800, $transitions);
    }

    private function transitions(string $pcm): int
    {
        $count = 0;

        for ($i = 1, $n = strlen($pcm); $i < $n; $i++) {
            $before = ord($pcm[$i - 1]) >= Synth::MIDPOINT;
            $after = ord($pcm[$i]) >= Synth::MIDPOINT;

            if ($before !== $after) {
                $count++;
            }
        }

        return $count;
    }

    private function amplitudeAt(string $pcm, int $index): int
    {
        return abs(ord($pcm[$index]) - Synth::MIDPOINT);
    }
}
