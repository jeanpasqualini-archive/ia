<?php

declare(strict_types=1);

namespace Map\Render;

use Map\Builder\MapBuilder;
use Map\Player\PlayerInterface;

/**
 * The world through the eyes of a cat.
 *
 * **A ray caster, because the world is already exactly what one needs.** The
 * map is a grid of tiles with a cost per tile; walls are the tiles a cat
 * cannot walk through, floor is the rest. Nothing had to be built for this —
 * no geometry, no meshes, no depth buffer. One ray per screen column, walked
 * across the grid until it meets something, and the distance it travelled is
 * the height to draw.
 *
 * It answers the question the whole project is about, and answers it more
 * directly than any panel: *why did it do that*. The cat only knows what it
 * can see, and here so do you — a lake is a lake you have to walk round, a
 * thicket is a wall, a flower is a spot of colour on the ground some way off.
 * The AI panel tells you the goal; this shows you the evidence it had.
 *
 * Composed at a quarter of the area it fills and blown up, like both other
 * views. Chunky, cheap, and the same pixel throughout — the three views look
 * like one game rather than three programs.
 *
 * **What is *not* here**, and is honest about it: no billboards, so the other
 * cats and the plants are floor colour rather than shapes. A sprite in a ray
 * cast scene needs a depth per column kept from the wall pass and sorting by
 * distance, which is a second mechanism, and this one is worth measuring
 * before it grows one.
 */
final class FirstPerson
{
    /**
     * What stops a ray.
     *
     * Read from the same idea as `MapBuilder::COSTS`: a wall is what a cat
     * cannot walk through. Water is deliberately *not* one — a lake stops a
     * cat but not its eyes, and drawing a blue wall across a shore would say
     * the opposite of what the shore is.
     *
     * @var array<string, true>
     */
    private const WALLS = [
        MapBuilder::ARBRE => true,
        MapBuilder::FOURRE => true,
        MapBuilder::ROCHE => true,
    ];

    /**
     * Half the field of view, as the length of the camera plane.
     *
     * A cat's eyes are on the front of its head and its useful field is wide;
     * two thirds is the value every ray caster since Wolfenstein has used, and
     * anything much past it bends the edges of the picture visibly.
     */
    private const PLANE = 0.66;

    /** How far a ray bothers to walk before calling it open ground. */
    private const REACH = 40;

    private const SKY_HIGH = 0xFF16324A;
    private const SKY_LOW = 0xFF39607A;

    /** Beyond this many tiles everything fades into the distance alike. */
    private const FOG = 18.0;

    private const FOG_COLOUR = 0xFF16324A;

    public function __construct(private TilePalette $palette)
    {
    }

    /**
     * @param array<int, array<int, string>> $map
     * @param array{float, float} $heading unit vector the cat is facing
     */
    public function paint(
        array $map,
        PlayerInterface $player,
        array $heading,
        int $width,
        int $height,
    ): Pixels {
        $pixels = new Pixels($width, $height, self::SKY_HIGH);

        $worldHeight = count($map);
        $worldWidth = count($map[0] ?? []);
        $horizon = intdiv($height, 2);

        // Standing in the middle of its tile rather than on its corner: on a
        // corner the two walls beside a cat are both a hair away and the view
        // is a wall, whichever way it looks.
        $posX = $player->getPosition()->getX() + 0.5;
        $posY = $player->getPosition()->getY() + 0.5;

        [$dirX, $dirY] = $heading;
        $planeX = -$dirY * self::PLANE;
        $planeY = $dirX * self::PLANE;

        $this->sky($pixels, $width, $horizon);
        $this->floor($pixels, $map, $worldWidth, $worldHeight, $width, $height, $horizon, $posX, $posY, $dirX, $dirY, $planeX, $planeY);

        for ($column = 0; $column < $width; $column++) {
            $camera = 2 * $column / $width - 1;
            $rayX = $dirX + $planeX * $camera;
            $rayY = $dirY + $planeY * $camera;

            [$distance, $side, $tile] = $this->cast(
                $map,
                $worldWidth,
                $worldHeight,
                $posX,
                $posY,
                $rayX,
                $rayY
            );

            if (null === $tile) {
                continue;
            }

            // The classic: an apparent height inversely proportional to the
            // distance, which is all perspective is.
            $line = (int) ($height / max(0.05, $distance));
            $top = max(0, $horizon - intdiv($line, 2));
            $bottom = min($height, $horizon + intdiv($line, 2));

            $colour = Pixels::pack($this->palette->pixel($tile, (int) $posX, (int) $posY));

            // One of the two faces darker, which is the only thing telling a
            // corner from a flat wall when both are the same colour.
            $colour = self::shade($colour, 1 === $side ? 0.72 : 1.0);
            $colour = self::fade($colour, $distance);

            $pixels->rect($column, $top, 1, max(0, $bottom - $top), $colour);
        }

        return $pixels;
    }

    /**
     * A ray walked across the grid, one tile boundary at a time.
     *
     * Digital differential analysis: rather than stepping by a small amount
     * and hoping, it jumps from one grid line to the next, so a ray costs a
     * step per tile it crosses and never misses a wall by landing either side
     * of it.
     *
     * @param array<int, array<int, string>> $map
     *
     * @return array{float, int, string|null}
     */
    private function cast(
        array $map,
        int $worldWidth,
        int $worldHeight,
        float $posX,
        float $posY,
        float $rayX,
        float $rayY,
    ): array {
        $mapX = (int) $posX;
        $mapY = (int) $posY;

        $deltaX = 0.0 === $rayX ? PHP_FLOAT_MAX : abs(1 / $rayX);
        $deltaY = 0.0 === $rayY ? PHP_FLOAT_MAX : abs(1 / $rayY);

        $stepX = $rayX < 0 ? -1 : 1;
        $stepY = $rayY < 0 ? -1 : 1;

        $sideX = $rayX < 0 ? ($posX - $mapX) * $deltaX : ($mapX + 1.0 - $posX) * $deltaX;
        $sideY = $rayY < 0 ? ($posY - $mapY) * $deltaY : ($mapY + 1.0 - $posY) * $deltaY;

        for ($step = 0; $step < self::REACH; $step++) {
            if ($sideX < $sideY) {
                $sideX += $deltaX;
                $mapX += $stepX;
                $side = 0;
            } else {
                $sideY += $deltaY;
                $mapY += $stepY;
                $side = 1;
            }

            if ($mapX < 0 || $mapY < 0 || $mapX >= $worldWidth || $mapY >= $worldHeight) {
                return [self::REACH, $side, null];
            }

            $tile = $map[$mapY][$mapX] ?? MapBuilder::HERBE;

            if (isset(self::WALLS[$tile])) {
                // The *perpendicular* distance and not the ray's own, or the
                // edges of the picture bow outwards — the wall would look
                // curved because the corner rays are longer.
                $distance = 0 === $side
                    ? ($sideX - $deltaX)
                    : ($sideY - $deltaY);

                return [max(0.05, $distance), $side, $tile];
            }
        }

        return [self::REACH, 0, null];
    }

    /**
     * The ground, cast the other way round: for each row below the horizon,
     * how far away the ground it shows is, then which tile lies there.
     *
     * It is what makes the meadow a meadow rather than a green band — a
     * flower on the floor some way off is a spot of its own colour, which is
     * exactly what a cat has to tell apart from the poison beside it.
     *
     * @param array<int, array<int, string>> $map
     */
    private function floor(
        Pixels $pixels,
        array $map,
        int $worldWidth,
        int $worldHeight,
        int $width,
        int $height,
        int $horizon,
        float $posX,
        float $posY,
        float $dirX,
        float $dirY,
        float $planeX,
        float $planeY,
    ): void {
        $leftX = $dirX - $planeX;
        $leftY = $dirY - $planeY;
        $rightX = $dirX + $planeX;
        $rightY = $dirY + $planeY;

        for ($row = $horizon + 1; $row < $height; $row++) {
            // Eye height is half a wall, so the row's distance is this simple.
            $distance = (0.5 * $height) / ($row - $horizon);

            $stepX = $distance * ($rightX - $leftX) / $width;
            $stepY = $distance * ($rightY - $leftY) / $width;

            $x = $posX + $distance * $leftX;
            $y = $posY + $distance * $leftY;

            for ($column = 0; $column < $width; $column++) {
                $tileX = (int) $x;
                $tileY = (int) $y;
                $x += $stepX;
                $y += $stepY;

                if ($tileX < 0 || $tileY < 0 || $tileX >= $worldWidth || $tileY >= $worldHeight) {
                    continue;
                }

                $tile = $map[$tileY][$tileX] ?? MapBuilder::HERBE;
                $pixels->set(
                    $column,
                    $row,
                    self::fade(Pixels::pack($this->palette->pixel($tile, $tileX, $tileY)), $distance)
                );
            }
        }
    }

    private function sky(Pixels $pixels, int $width, int $horizon): void
    {
        for ($row = 0; $row <= $horizon; $row++) {
            $pixels->rect($row < 0 ? 0 : 0, $row, $width, 1, self::blend(
                self::SKY_HIGH,
                self::SKY_LOW,
                $horizon > 0 ? $row / $horizon : 0.0
            ));
        }
    }

    /**
     * Everything far away tends towards the colour of the sky, which is what
     * gives a flat picture its depth — and, here, what stops the far side of
     * the world being as loud as the tile a cat is standing on.
     */
    private static function fade(int $colour, float $distance): int
    {
        return self::blend($colour, self::FOG_COLOUR, min(1.0, $distance / self::FOG) * 0.8);
    }

    private static function blend(int $from, int $to, float $ratio): int
    {
        $mix = static fn (int $shift): int => (int) round(
            (($from >> $shift) & 0xFF) + ((($to >> $shift) & 0xFF) - (($from >> $shift) & 0xFF)) * $ratio
        );

        return 0xFF000000 | ($mix(16) << 16) | ($mix(8) << 8) | $mix(0);
    }

    private static function shade(int $colour, float $factor): int
    {
        $r = min(255, (int) round((($colour >> 16) & 0xFF) * $factor));
        $g = min(255, (int) round((($colour >> 8) & 0xFF) * $factor));
        $b = min(255, (int) round(($colour & 0xFF) * $factor));

        return 0xFF000000 | ($r << 16) | ($g << 8) | $b;
    }
}
