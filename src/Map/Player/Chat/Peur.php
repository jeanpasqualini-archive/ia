<?php

declare(strict_types=1);

namespace Map\Player\Chat;

use Map\Builder\MapBuilder;
use Map\Path\CostBiasInterface;

/**
 * What a cat expects to hurt, learnt from what did.
 *
 * Fear here is not a behaviour. It is the gap between what the world costs
 * and what the cat believes it costs: the map says brambles cost one step
 * like any other, and a cat that has been stung routes as though they cost
 * twelve. Nothing in the pathfinder changes — it is handed a different price
 * list — so the avoidance appears *before* the next sting, which is what
 * makes it anticipation rather than reaction.
 *
 * **A cat does not choose what to blame.** Asked whether it remembers the
 * bramble, the place, or the individual, the honest answer is that it
 * remembers whatever was present, and each of those cues takes on part of the
 * blame. What later looks like "it learnt that brambles sting" is not a
 * concept: it is the cue that was there every time keeping its weight while
 * the ones that only happened to be there lose theirs.
 *
 * The update rule is Rescorla and Wagner's, from 1972, and it is one line:
 * every cue present moves by the share of the pain that was *not* predicted.
 * The competition between cues falls out of it —
 *
 *   - a cue that already predicts the sting leaves little error for the
 *     others, so a familiar danger in a new place teaches almost nothing
 *     about the place (this is *blocking*);
 *   - stung on brambles in many places, the terrain cue is reinforced every
 *     time while each place fades on its own, so the memory generalises;
 *   - stung repeatedly in one place over varied ground, the place wins.
 *
 * The level of abstraction is therefore never chosen. It is selected by the
 * statistics of what happened.
 */
final class Peur implements CostBiasInterface
{
    /**
     * How much of the unpredicted pain a cue takes on at once.
     *
     * High enough that a single sting changes the route — a cat that needed
     * ten identical wounds to learn would read as stupid rather than as
     * careful.
     */
    private const LEARNING_RATE = 0.4;

    /** Ticks between two rounds of forgetting. */
    private const FADING_RATE = 25;

    /** Share of every weight lost at each round. */
    private const FADE = 0.06;

    /** Under this a memory is gone rather than merely faint. */
    private const FORGOTTEN = 0.04;

    /**
     * Extra cost per unit of expected pain.
     *
     * This is the exchange rate between fear and distance: at a hundred and
     * twenty, a fully learnt sting is worth a twelve tile detour. Lower and
     * the cat walks into brambles it claims to fear; much higher and it
     * strands itself rather than cross one.
     */
    private const DETOUR = 120;

    /**
     * Size of the region a place cue covers, in tiles.
     *
     * Deliberately coarse. An exact tile on a map of forty thousand is a
     * memory the cat will never be in a position to use again — and animals
     * learn a context rather than a point.
     */
    private const REGION = 8;

    /** @var array<string, float> */
    private array $weights = [];

    /**
     * What is perceptible at a tile, as cues that can carry blame.
     *
     * @return list<string>
     */
    public static function cues(MapBuilder $map, int $x, int $y): array
    {
        return [
            'sol:' . ($map->tileAt($x, $y) ?? '?'),
            'lieu:' . intdiv($x, self::REGION) . ',' . intdiv($y, self::REGION),
        ];
    }

    /**
     * @param list<string> $cues
     */
    public function remember(array $cues, float $pain): void
    {
        // Moved towards the error, not towards the pain. That single
        // difference is what makes the cues compete instead of all of them
        // learning the same thing.
        $error = $pain - $this->expect($cues);

        foreach ($cues as $cue) {
            $this->weights[$cue] = max(0.0, ($this->weights[$cue] ?? 0.0) + self::LEARNING_RATE * $error);
        }
    }

    /**
     * @param list<string> $cues
     */
    public function expect(array $cues): float
    {
        $total = 0.0;

        foreach ($cues as $cue) {
            $total += $this->weights[$cue] ?? 0.0;
        }

        return $total;
    }

    /**
     * Forgetting is not housekeeping, it is half the behaviour: a fear that
     * never faded would leave a cat refusing a whole terrain for the rest of
     * its life over one scratch, with no way back. What fades can be learnt
     * again, and that is what habituation looks like from outside.
     */
    public function fade(): void
    {
        foreach ($this->weights as $cue => $weight) {
            $faded = $weight * (1 - self::FADE);

            if ($faded < self::FORGOTTEN) {
                unset($this->weights[$cue]);

                continue;
            }

            $this->weights[$cue] = $faded;
        }
    }

    public function shouldFade(int $tick): bool
    {
        return 0 === $tick % self::FADING_RATE;
    }

    public function bias(string $tile, int $x, int $y): int
    {
        // A cat that fears nothing pays nothing to find out. Worth the guard:
        // this runs on every tile a search expands.
        if ([] === $this->weights) {
            return 0;
        }

        return (int) round($this->expect([
            'sol:' . $tile,
            'lieu:' . intdiv($x, self::REGION) . ',' . intdiv($y, self::REGION),
        ]) * self::DETOUR);
    }

    /**
     * The strongest memories, worst first, for the panel. Fear that cannot be
     * read is fear that cannot be told from a bug.
     *
     * @return list<array{string, float}>
     */
    public function strongest(int $limit = 3): array
    {
        $weights = $this->weights;
        arsort($weights);

        $out = [];

        foreach (array_slice($weights, 0, $limit, true) as $cue => $weight) {
            $out[] = [$cue, $weight];
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return [] === $this->weights;
    }
}
