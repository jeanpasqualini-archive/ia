<?php

declare(strict_types=1);

namespace Map\Render;

use Map\Builder\MapBuilder;

/**
 * What stands on the ground, seen from the side, in pixels.
 *
 * **The ground itself is not here.** A diamond is generated rather than drawn,
 * because it is the same shape every time and its *colour* already exists:
 * `TilePalette::pixel()` answers what a tile is, which means the isometric
 * view inherits the hashed grain of the meadow and the animated swell of the
 * lake for nothing. Painting a second set of terrain colours would be a second
 * table to keep in step with the first.
 *
 * What is drawn here is what the map view can only say with a coloured square:
 * a tree that is a trunk and a canopy, a flower on a stem, a bramble low to
 * the ground. The blooms keep their own colour, so a foxglove is still the
 * cold one among the warm — the cat has to be able to be wrong about it, and
 * the player has to be able to see that it was.
 *
 * Every sprite is outlined for the reason the floating cat had to be: on a
 * meadow carrying some hundred and ninety plants and pits per screen, a shape
 * with no dark line round it has no silhouette and reads as a fault in the
 * drawing rather than as a thing.
 *
 * `anchor` is where the tile's centre falls inside the art, so a sprite stands
 * *on* its diamond instead of floating over the one behind it — an off by one
 * there puts a whole forest half a tile up the hill.
 */
final class IsoSprites
{
    /** Width and height of a ground diamond, in composed pixels. */
    public const TILE_WIDTH = 16;
    public const TILE_HEIGHT = 8;

    /**
     * The art. `.` nothing, `o` outline, and the rest are roles resolved
     * against the palette so a bloom keeps the colour it has on the map.
     *
     * @var array<string, array{rows: list<string>, anchor: array{int, int}}>
     */
    private const ART = [
        MapBuilder::ARBRE => [
            'rows' => [
                '...oooo...',
                '..oFFFFo..',
                '.oFFffFFo.',
                'oFFffffFFo',
                'oFFFffFFFo',
                '.oFFFFFFo.',
                '..oFFFFo..',
                '...oTTo...',
                '...oTTo...',
                '..oooooo..',
            ],
            'anchor' => [5, 10],
        ],
        MapBuilder::FOURRE => [
            'rows' => [
                '..oooo..',
                '.oFFFFo.',
                'oFFffFFo',
                'oFFFFFFo',
                'oFFFFFFo',
                '.oFFFFo.',
                '..oooo..',
            ],
            'anchor' => [4, 7],
        ],
        MapBuilder::FLEUR => [
            'rows' => [
                '.oBo.',
                'oBBBo',
                '.oBo.',
                '..S..',
                '..S..',
            ],
            'anchor' => [2, 5],
        ],
        MapBuilder::DIGITALE => [
            // Taller and on a spike, the way a foxglove actually grows, so it
            // is not only the colour that tells them apart.
            'rows' => [
                '.oBo',
                'oBBo',
                '.oBo',
                'oBBo',
                '.oSo',
                '..S.',
                '..S.',
            ],
            'anchor' => [2, 7],
        ],
        MapBuilder::RONCE => [
            'rows' => [
                '.o.o.o.',
                'oRoRoRo',
                'oRRRRRo',
                '.ooooo.',
            ],
            'anchor' => [3, 4],
        ],
        MapBuilder::CHAMPIGNON => [
            'rows' => [
                '.ooo.',
                'oMMMo',
                'oMMMo',
                '.omo.',
                '.omo.',
            ],
            'anchor' => [2, 5],
        ],
        MapBuilder::CAVERNE => [
            // A mouth in the ground, lit from the other side.
            'rows' => [
                '..oooo..',
                '.oKKKKo.',
                'oKddddKo',
                'oKddddKo',
                '.oddddo.',
                '..oooo..',
            ],
            'anchor' => [4, 6],
        ],
    ];

    /**
     * Tufts, for the meadow.
     *
     * Grass had no asset at all and the ground alone said it, which made the
     * meadow the one terrain in the isometric view drawn the way the *map*
     * view draws everything — a flat colour. Three shapes rather than one, and
     * only some tiles get any: a tuft on every tile is a lawn, and the same
     * tuft on every tile is wallpaper. Which one, and whether at all, comes
     * from the coordinate hash, so it is stable frame after frame for the
     * reason the terrain grain is.
     *
     * @var list<list<string>>
     */
    private const TUFTS = [
        [
            '.o.o.',
            'ogogo',
            '.ggg.',
        ],
        [
            'o...o',
            'og.go',
            '.ogo.',
        ],
        [
            '..o..',
            'o.g.o',
            '.ggg.',
        ],
    ];

    private const TUFT_ANCHOR = [2, 3];

    /**
     * Foam, put on a crest and nowhere else. Its width is the crest of the
     * diamond so it reads as the top of a swell rather than as a thing
     * floating on the lake.
     *
     * @var list<string>
     */
    private const FOAM = [
        '.wwww.',
        'w.ww.w',
    ];

    private const FOAM_ANCHOR = [3, 4];

    /**
     * One of the three tufts, or null where this tile has none.
     *
     * @return array{rows: list<string>, anchor: array{int, int}}|null
     */
    public static function tuft(int $hash): ?array
    {
        // Two tiles in five, which is enough to read as a meadow and few
        // enough that the eye does not find the lattice underneath.
        if ($hash % 5 >= 2) {
            return null;
        }

        return ['rows' => self::TUFTS[$hash % 3], 'anchor' => self::TUFT_ANCHOR];
    }

    /**
     * @return array{rows: list<string>, anchor: array{int, int}}
     */
    public static function foam(): array
    {
        return ['rows' => self::FOAM, 'anchor' => self::FOAM_ANCHOR];
    }

    /**
     * A cat from the side, which is the one thing the map view cannot say at
     * all: there it is a single coloured tile.
     *
     * @var list<string>
     */
    private const CAT = [
        'o..o...',
        'oBoBo..',
        'oBBBBo.',
        'oBEBBoo',
        'oBBBPPo',
        '.oBBPPo',
        '..o..o.',
    ];

    private const CAT_ANCHOR = [3, 7];

    /**
     * The ground, as a run of pixels per row.
     *
     * Generated rather than stored: a diamond is the same shape every time,
     * and the four numbers that describe it are easier to read than eight rows
     * of hashes. Row r of the top half is 4(r+1) wide, and the bottom half
     * mirrors it.
     *
     * @return list<array{int, int}> the x and the width of each row
     */
    public static function diamond(): array
    {
        $rows = [];

        for ($row = 0; $row < self::TILE_HEIGHT; $row++) {
            $step = $row < self::TILE_HEIGHT / 2 ? $row : self::TILE_HEIGHT - 1 - $row;
            $width = 4 * ($step + 1);
            $rows[] = [intdiv(self::TILE_WIDTH - $width, 2), $width];
        }

        return $rows;
    }

    /**
     * What stands on a tile, or null where the ground is the whole story —
     * grass, water, a pit, the rock and the galleries below.
     *
     * @return array{rows: list<string>, anchor: array{int, int}}|null
     */
    public static function standing(string $tile): ?array
    {
        return self::ART[$tile] ?? null;
    }

    /**
     * @return array{rows: list<string>, anchor: array{int, int}}
     */
    public static function cat(): array
    {
        return ['rows' => self::CAT, 'anchor' => self::CAT_ANCHOR];
    }

    /**
     * Every piece of art, so a test can check the shapes are rectangular and
     * that nothing stands outside its own anchor. Pixel art has no compiler,
     * and a row one character short shifts everything below it.
     *
     * @return array<string, array{rows: list<string>, anchor: array{int, int}}>
     */
    public static function all(): array
    {
        return self::ART + ['chat' => self::cat()];
    }
}
