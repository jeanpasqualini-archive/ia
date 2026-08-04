<?php

declare(strict_types=1);

namespace Audio;

/**
 * Sums voices into a single stream.
 *
 * Everything here hangs on one detail: samples are added around the midpoint
 * and **saturated**, never allowed to wrap. A byte that overflows comes back
 * as the opposite extreme — a sample at the top of the range reappearing at
 * the bottom — and the speaker reproduces that as a loud crack, not as
 * distortion. Clipping is merely ugly; wrapping is the bug that makes a chip
 * tune sound broken.
 */
final class Mixer
{
    /**
     * Add $overlay into $base starting at $offset samples, keeping the length
     * of $base. Whatever hangs past the end is dropped: the caller owns the
     * cursor and will pass the remainder on the next chunk.
     */
    public static function add(string $base, string $overlay, int $offset = 0): string
    {
        $end = min(strlen($base), $offset + strlen($overlay));
        $start = max(0, $offset);

        if ($start >= $end) {
            return $base;
        }

        $mixed = $base;

        for ($i = $start; $i < $end; $i++) {
            $sum = ord($mixed[$i]) + ord($overlay[$i - $offset]) - Synth::MIDPOINT;

            $mixed[$i] = chr(max(0, min(255, $sum)));
        }

        return $mixed;
    }
}
