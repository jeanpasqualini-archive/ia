<?php

declare(strict_types=1);

namespace Map\Render;

use PhpTui\Tui\Color\Color;

/**
 * The little cat floating over each player, sitting on its cloud.
 *
 * **A cat on this map is one tile.** On a world of forty thousand of them that
 * is a dot, and finding one's cat is most of what the eye does here — which is
 * why the panel already carries a button to centre on it. The sprite answers
 * the same question from the other end: something big enough to be seen at a
 * glance, drawn above the cat rather than on it, so the tile the cat actually
 * occupies stays readable underneath.
 *
 * **It is drawn in tiles, not in cells.** A tile is half a cell and half a
 * cell is square, so the art below is undistorted: eleven by eleven tiles come
 * out as a square on screen, where eleven by eleven cells would come out twice
 * as tall as it is wide. The same reason the map went to half blocks.
 *
 * **Its size is in tiles of the view and not of the world**, so zooming out
 * does not shrink it away. It is a marker, and losing the marker is precisely
 * what one zooms out to avoid — the same rule that already keeps a cat from
 * being sampled away.
 *
 * The shape lives here and the colours in {@see TilePalette}: this class knows
 * what a cat looks like, the palette knows what the terminal can say.
 */
final class CatSprite
{
    /**
     * The art, one character per tile.
     *
     * `B` and `P` are the two colours of the coat — the cat is split down the
     * middle rather than spotted, because a patch of two or three tiles is
     * lost at this size and reads as a mistake in the drawing. `I` inner ear,
     * `E` eye, `M` muzzle, `C` cloud, `s` the shade under it, `.` nothing.
     *
     * **`o` is the outline, and it is what makes the drawing a drawing.** The
     * first version had none: on a meadow carrying some hundred and ninety
     * flowers, brambles and holes per screen, a shape with no dark line round
     * it has no silhouette at all and dissolves into the speckle. Every pixel
     * cat ever drawn on a busy background is outlined, and this is why. It
     * costs the two columns the sprite grew by.
     *
     * @var list<string>
     */
    private const ART = [
        '.oooo...oooo.',
        '.oBBo...oPPo.',
        '.oBIBoooPIPo.',
        '.oBBBBBPPPPo.',
        '.oBEBBBPPEPo.',
        '.oBBBMMMPPPo.',
        '..oBBBBBPPoo.',
        '..oBBBBPPPoPo',
        '...oBBPPPPPPo',
        // The notch between the two puffs shows the cat's underside through
        // it, so it is outline and not sky: a gap there leaves the belly bare
        // against the meadow.
        '...CCCoCCCoo.',
        '..CCCCCCCCC..',
        '...sssssss...',
    ];

    public const WIDTH = 13;

    /** Tiles left between the bottom of the cloud and the cat under it. */
    private const GAP = 1;

    /**
     * Height of the bob, in tiles. Two either way is one whole cell of travel
     * — enough to be seen as movement, little enough that the cat does not
     * wander away from the animal it belongs to.
     */
    private const FLOAT_HEIGHT = 2.0;

    /** Radians a second. About three seconds up and down again. */
    private const FLOAT_SPEED = 2.2;

    /** @var array<string, string> art character to the colour it asks for */
    private const ROLES = [
        'o' => 'outline',
        'B' => 'coat',
        'P' => 'patch',
        'I' => 'snout',
        'E' => 'eye',
        'M' => 'snout',
        'C' => 'cloud',
        's' => 'shade',
    ];

    /**
     * Where the sprite stands relative to the tile the cat is on, at a given
     * instant.
     *
     * Offsets rather than screen positions: the caller owns the view and is
     * the only one that can say what falls off the edge of it.
     *
     * @return array<int, array<int, Color>> keyed [row offset][column offset]
     */
    public function over(TilePalette $palette, int $player, float $phase): array
    {
        $colours = $palette->catColours($player);

        // Each cat is given its own place in the cycle. Bobbing in step they
        // would read as one animation with several copies of itself, which is
        // the same thing a single travelling wave does to the water.
        $bob = (int) round(self::FLOAT_HEIGHT * sin($phase * self::FLOAT_SPEED + $player * 1.7));

        // The gap is measured at the bottom of the bob, not at rest: counted
        // from rest, the cloud comes down onto the cat every cycle and hides
        // the one tile the whole marker exists to point at.
        $top = -(self::GAP + count(self::ART) + (int) self::FLOAT_HEIGHT) + $bob;
        $left = -intdiv(self::WIDTH, 2);

        $cells = [];

        foreach (self::ART as $row => $line) {
            foreach (str_split($line) as $column => $char) {
                $role = self::ROLES[$char] ?? null;

                if (null === $role) {
                    continue;
                }

                $cells[$top + $row][$left + $column] = $colours[$role];
            }
        }

        return $cells;
    }

    /**
     * The art as it is written, so a test can check every row is the same
     * width. A line one character short shifts everything below it and the cat
     * comes out with a crooked face.
     *
     * @return list<string>
     */
    public static function art(): array
    {
        return self::ART;
    }
}
