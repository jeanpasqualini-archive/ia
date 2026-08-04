<?php

declare(strict_types=1);

namespace Audio;

/**
 * The one shot sounds, each rendered once at start up and replayed from
 * memory afterwards.
 *
 * None of them goes above 45: the music already uses 82 of the 127 a byte
 * allows, so anything louder would clip the moment it lands on a loud bar.
 */
enum SoundEffect: string
{
    /** A cat reached a flower. */
    case Eat = 'eat';

    /** Key feedback: speed, tabs, pause. */
    case Blip = 'blip';

    /** Entering or leaving the time machine. */
    case Warp = 'warp';

    /** A new map was generated. */
    case Reload = 'reload';

    public function render(Synth $synth): string
    {
        return match ($this) {
            // Rising, then a short bite of noise: a pitch going up reads as
            // something being gained, which is the whole message here.
            self::Eat => $synth->sweep(520.0, 980.0, 0.07, 42, duty: 0.25)
                . $synth->square(1174.66, 0.05, 38, duty: 0.125, decay: 1.0)
                . $synth->noise(0.03, 20),

            // Deliberately tiny. This one fires on every key press, and
            // anything with a tail turns fast repeats into a smear.
            self::Blip => $synth->square(880.0, 0.025, 30, duty: 0.5, decay: 1.0),

            // Falling and slow: the opposite gesture of Eat, for time running
            // the other way.
            self::Warp => $synth->sweep(1200.0, 180.0, 0.35, 38, duty: 0.5, decay: 0.5),

            // A plain major arpeggio — the sound a game makes when something
            // starts rather than when something is won.
            self::Reload => $synth->square(Tune::frequency('C5'), 0.05, 40, decay: 0.4)
                . $synth->square(Tune::frequency('E5'), 0.05, 40, decay: 0.4)
                . $synth->square(Tune::frequency('G5'), 0.05, 40, decay: 0.4)
                . $synth->square(Tune::frequency('C6'), 0.12, 40, decay: 0.9),
        };
    }
}
