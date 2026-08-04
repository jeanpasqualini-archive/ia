<?php

declare(strict_types=1);

namespace Audio;

/**
 * The oscillators, and nothing else. Everything here returns unsigned 8 bit
 * mono PCM as a plain string: one byte per sample, 128 being silence.
 *
 * That format is not a shortcut, it is the one the sound chips of the era
 * actually produced, and it keeps a whole eight second loop under two hundred
 * kilobytes — small enough to synthesize at start up and hold in memory.
 *
 * Volumes are amplitudes around the midpoint, so 127 is the loudest a single
 * voice can be. Voices are summed afterwards, which is why none of them may
 * use the whole range: see Tune for how the budget is shared.
 */
final class Synth
{
    /** Silence, and the middle of the unsigned 8 bit range. */
    public const MIDPOINT = 128;

    /** The loudest a sample can be without leaving the byte. */
    public const MAX_AMPLITUDE = 127;

    public function __construct(private readonly int $rate = 22050)
    {
    }

    public function rate(): int
    {
        return $this->rate;
    }

    public function samples(float $seconds): int
    {
        return (int) round($seconds * $this->rate);
    }

    public function silence(float $seconds): string
    {
        return str_repeat(chr(self::MIDPOINT), max(0, $this->samples($seconds)));
    }

    /**
     * A square wave, the voice of the pulse channels.
     *
     * The duty cycle is what makes one square sound unlike another: at 0.5 it
     * is the hollow tone of a bass line, at 0.125 the thin nasal one that
     * carries a melody over everything else. Sweeping it was how a chip got
     * two instruments out of one oscillator.
     *
     * The decay matters more than it looks: a note held at constant volume
     * until it stops reads as a test tone, not as an instrument.
     */
    public function square(
        float $hz,
        float $seconds,
        int $volume,
        float $duty = 0.5,
        float $decay = 0.0,
    ): string {
        $count = $this->samples($seconds);

        if ($count <= 0 || $hz <= 0.0 || $volume <= 0) {
            return $this->silence($seconds);
        }

        $period = $this->rate / $hz;
        $pcm = '';

        for ($i = 0; $i < $count; $i++) {
            $level = 1.0 - $decay * ($i / $count);
            $amplitude = (int) round($volume * max(0.0, $level));
            $high = (fmod($i, $period) / $period) < $duty;

            $pcm .= chr(self::MIDPOINT + ($high ? $amplitude : -$amplitude));
        }

        return $pcm;
    }

    /**
     * A pitch bend, for the sounds that move: something being swallowed, or
     * time running backwards.
     */
    public function sweep(
        float $fromHz,
        float $toHz,
        float $seconds,
        int $volume,
        float $duty = 0.5,
        float $decay = 0.3,
    ): string {
        $count = $this->samples($seconds);

        if ($count <= 0 || $volume <= 0) {
            return $this->silence($seconds);
        }

        $pcm = '';
        $phase = 0.0;

        for ($i = 0; $i < $count; $i++) {
            $progress = $i / $count;

            // Interpolated on a log scale: swept linearly, a rise from 200 to
            // 800 Hz spends most of its time in the top octave and is heard as
            // a jump rather than as a glide.
            $hz = $fromHz * ($toHz / $fromHz) ** $progress;

            // The phase has to be accumulated. Deriving it from the sample
            // index and the current frequency instead makes the waveform jump
            // whenever the pitch changes, which clicks.
            $phase = fmod($phase + $hz / $this->rate, 1.0);

            $amplitude = (int) round($volume * max(0.0, 1.0 - $decay * $progress));

            $pcm .= chr(self::MIDPOINT + ($phase < $duty ? $amplitude : -$amplitude));
        }

        return $pcm;
    }

    /**
     * The noise channel: percussion, and anything that has to sound like an
     * accident rather than a note.
     *
     * Driven by a fifteen bit shift register, the way the NES did it, rather
     * than by a random number generator — partly because that is where the
     * particular colour of chip noise comes from, and partly because it makes
     * the output reproducible, which a test can assert on.
     *
     * $step holds each value for several samples: clocked once per sample the
     * register produces hiss with no pitch at all.
     */
    public function noise(float $seconds, int $volume, float $decay = 1.0, int $step = 8): string
    {
        $count = $this->samples($seconds);

        if ($count <= 0 || $volume <= 0) {
            return $this->silence($seconds);
        }

        $register = 0x7FFF;
        $step = max(1, $step);
        $pcm = '';
        $high = true;

        for ($i = 0; $i < $count; $i++) {
            if (0 === $i % $step) {
                // Tap the two lowest bits, xor them, feed the result back in
                // at the top.
                $bit = ($register ^ ($register >> 1)) & 1;
                $register = (($register >> 1) | ($bit << 14)) & 0x7FFF;
                $high = 1 === ($register & 1);
            }

            $amplitude = (int) round($volume * max(0.0, 1.0 - $decay * ($i / $count)));

            $pcm .= chr(self::MIDPOINT + ($high ? $amplitude : -$amplitude));
        }

        return $pcm;
    }
}
