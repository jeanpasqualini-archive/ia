<?php

declare(strict_types=1);

namespace Audio;

/**
 * The theme that loops under the game.
 *
 * Four voices, which is what the hardware being imitated had: two pulses (a
 * melody and an arpeggio), a bass and a noise channel. The arpeggio is doing
 * the work a chord would do — a chip could not hold three notes at once, so
 * it played them in turn fast enough to be heard as one.
 *
 * Durations are counted in **samples, never in seconds**. Rounding each note
 * from its own duration lets the voices drift apart by a sample here and
 * there, and a loop whose tracks are not exactly the same length clicks at
 * every repeat.
 */
final class Tune
{
    /** Sixteenth notes at 120 bpm. */
    private const STEP_SECONDS = 0.125;

    private const STEPS_PER_BAR = 16;

    /**
     * Amplitudes, summed by the mixer. They deliberately total 82 out of the
     * 127 a byte allows: the rest is headroom for a sound effect to land on
     * top of the music without the sum clipping.
     */
    private const MELODY_VOLUME = 34;
    private const ARPEGGIO_VOLUME = 14;
    private const BASS_VOLUME = 26;
    private const PERCUSSION_VOLUME = 8;

    /** Semitones away from A, which is the note the frequencies hang off. */
    private const SEMITONES = [
        'C' => -9, 'C#' => -8, 'D' => -7, 'D#' => -6, 'E' => -5, 'F' => -4,
        'F#' => -3, 'G' => -2, 'G#' => -1, 'A' => 0, 'A#' => 1, 'B' => 2,
    ];

    /**
     * I – vi – IV – V, one bar each: the progression every game of the era
     * leant on, because it resolves without needing a fifth bar.
     *
     * @var list<array{string, list<string>}> root note, then the chord tones
     *                                        the arpeggio cycles through
     */
    private const CHORDS = [
        ['C3', ['C4', 'E4', 'G4']],
        ['A2', ['A3', 'C4', 'E4']],
        ['F2', ['F3', 'A3', 'C4']],
        ['G2', ['G3', 'B3', 'D4']],
    ];

    /**
     * The melody, as note and length in steps. Null is a rest — the bar of
     * silence at the end is what stops the loop from sounding like a phrase
     * cut in half.
     *
     * @var list<array{string|null, int}>
     */
    private const MELODY = [
        ['E5', 2], ['D5', 2], ['C5', 4], ['E5', 2], ['G5', 2], ['E5', 4],
        ['A4', 2], ['C5', 2], ['E5', 4], ['D5', 2], ['C5', 2], ['A4', 4],
        ['F4', 2], ['A4', 2], ['C5', 4], ['A4', 2], ['F4', 2], ['G4', 4],
        ['G4', 2], ['B4', 2], ['D5', 4], ['G5', 4], [null, 4],
    ];

    public function __construct(private readonly Synth $synth)
    {
    }

    /**
     * Equal temperament around A4 = 440 Hz. 'C#5' and 'A2' both parse.
     */
    public static function frequency(string $note): float
    {
        if (1 !== preg_match('/^([A-G]#?)(-?\d+)$/', $note, $matches)) {
            throw new \InvalidArgumentException(sprintf('note illisible : %s', $note));
        }

        $semitones = self::SEMITONES[$matches[1]] + 12 * ((int) $matches[2] - 4);

        return 440.0 * 2 ** ($semitones / 12);
    }

    public function lengthInSamples(): int
    {
        return count(self::CHORDS) * self::STEPS_PER_BAR * $this->stepSamples();
    }

    /**
     * The voices, unmixed. Kept reachable so a test can check they all come
     * out exactly the same length, which is the property the seamless loop
     * depends on.
     *
     * @return array<string, string>
     */
    public function tracks(): array
    {
        return [
            'melody' => $this->melody(),
            'arpeggio' => $this->arpeggio(),
            'bass' => $this->bass(),
            'percussion' => $this->percussion(),
        ];
    }

    public function render(): string
    {
        $tracks = $this->tracks();
        $mixed = array_shift($tracks);

        foreach ($tracks as $track) {
            $mixed = Mixer::add($mixed, $track);
        }

        return $mixed;
    }

    private function melody(): string
    {
        $pcm = '';

        foreach (self::MELODY as [$note, $steps]) {
            $samples = $steps * $this->stepSamples();

            $pcm .= null === $note
                ? $this->synth->silence($this->seconds($samples))
                : $this->synth->square(
                    self::frequency($note),
                    $this->seconds($samples),
                    self::MELODY_VOLUME,
                    // The thin duty cycle is what lifts a melody clear of the
                    // voices under it without simply being louder.
                    duty: 0.25,
                    decay: 0.55,
                );
        }

        return $pcm;
    }

    private function arpeggio(): string
    {
        $step = $this->stepSamples();
        $pcm = '';

        foreach (self::CHORDS as [, $tones]) {
            for ($i = 0; $i < self::STEPS_PER_BAR; $i++) {
                $pcm .= $this->synth->square(
                    self::frequency($tones[$i % count($tones)]),
                    $this->seconds($step),
                    self::ARPEGGIO_VOLUME,
                    duty: 0.5,
                    // Almost fully decayed within its own step, so the notes
                    // read as plucked rather than as one held chord.
                    decay: 0.9,
                );
            }
        }

        return $pcm;
    }

    private function bass(): string
    {
        $step = $this->stepSamples();
        $pcm = '';

        // Two notes a bar with a gap after each: a root held for the whole bar
        // drones, and the gap is what makes it read as a pulse.
        foreach (self::CHORDS as [$root]) {
            foreach ([6, 6] as $length) {
                $pcm .= $this->synth->square(
                    self::frequency($root),
                    $this->seconds($length * $step),
                    self::BASS_VOLUME,
                    duty: 0.5,
                    decay: 0.7,
                );
                $pcm .= $this->synth->silence($this->seconds(2 * $step));
            }
        }

        return $pcm;
    }

    private function percussion(): string
    {
        $step = $this->stepSamples();
        $hit = min($step, $this->synth->samples(0.035));
        $pcm = '';

        foreach (self::CHORDS as $ignored) {
            for ($i = 0; $i < self::STEPS_PER_BAR / 2; $i++) {
                $pcm .= $this->synth->noise($this->seconds($hit), self::PERCUSSION_VOLUME);
                $pcm .= $this->synth->silence($this->seconds(2 * $step - $hit));
            }
        }

        return $pcm;
    }

    private function stepSamples(): int
    {
        return (int) round(self::STEP_SECONDS * $this->synth->rate());
    }

    private function seconds(int $samples): float
    {
        return $samples / $this->synth->rate();
    }
}
