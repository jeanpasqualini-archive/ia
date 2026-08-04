<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Map\Builder\MapBuilder;
use Map\Render\TilePalette;
use PhpTui\Tui\Color\AnsiColor;
use PhpTui\Tui\Color\RgbColor;
use PHPUnit\Framework\TestCase;

final class TilePaletteTest extends TestCase
{
    public function testTerrainIsPaintedAsABackgroundAndKeepsNoGlyph(): void
    {
        $palette = new TilePalette(trueColor: true);

        self::assertSame(' ', $palette->glyph(MapBuilder::HERBE));
        self::assertSame(' ', $palette->glyph(MapBuilder::EAU));
        self::assertNotNull($palette->style(MapBuilder::HERBE, 0, 0)->bg);
        self::assertNotNull($palette->style(MapBuilder::EAU, 0, 0)->bg);
    }

    public function testWhatStandsOnTheGroundKeepsAGlyph(): void
    {
        $palette = new TilePalette(trueColor: true);

        self::assertSame('✿', $palette->glyph(MapBuilder::FLEUR));
        self::assertSame(' ', $palette->glyph(MapBuilder::ARBRE));
    }

    /**
     * Players are stamped as 1..9 on their own layer. Every cat used to be the
     * same letter in the same colour, so two of them were indistinguishable.
     */
    public function testEachPlayerGetsItsOwnGlyphAndColour(): void
    {
        $palette = new TilePalette(trueColor: true);

        $glyphs = array_map($palette->glyph(...), ['1', '2', '3', '4']);
        $colours = array_map(
            static fn (string $tile): ?string => $palette->style($tile, 0, 0)->fg?->toHex(),
            ['1', '2', '3', '4']
        );

        self::assertSame($glyphs, array_unique($glyphs));
        self::assertSame($colours, array_unique($colours));
    }

    /**
     * On the map a glyph must fit exactly one column. An emoji is two columns
     * wide and would eat its neighbour, shifting the whole row.
     */
    public function testMapGlyphsAreOneColumnWide(): void
    {
        $palette = new TilePalette(trueColor: true);

        foreach ([MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE, MapBuilder::FLEUR, '1', '2', '3', '4'] as $tile) {
            self::assertSame(
                1,
                mb_strwidth($palette->glyph($tile), 'UTF-8'),
                sprintf('la tuile %s deborde sur sa voisine', $tile)
            );
        }
    }

    /**
     * Measuring the width is not enough, and the forest is what proved it.
     *
     * A codepoint that has an emoji presentation gets substituted from the
     * colour emoji font, which draws it two columns wide whatever Unicode
     * says about it. The club suit that used to mark the wood measured one
     * column in PHP and took two on screen, so the frame width test stayed
     * green while the border sat one column off. \p{Emoji} catches the whole
     * family rather than that one character.
     */
    public function testNoGlyphCanBeSubstitutedByTheEmojiFont(): void
    {
        $palette = new TilePalette(trueColor: true);
        $glyphs = [];

        foreach ([MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE, MapBuilder::FLEUR] as $tile) {
            $glyphs[] = $palette->glyph($tile);
        }

        for ($player = 0; $player < 9; $player++) {
            $glyphs[] = TilePalette::playerMarker($player);
        }

        foreach ($glyphs as $glyph) {
            self::assertSame(
                0,
                preg_match('/\p{Emoji}/u', $glyph),
                sprintf('%s (U+%04X) sera dessine par la police emoji', $glyph, mb_ord($glyph, 'UTF-8'))
            );
        }
    }

    /**
     * The panel marker is the map glyph, so a cat reads as the same cat in
     * both places — and, like everything else here, it is one column wide.
     */
    public function testThePanelMarkerIsTheMapGlyph(): void
    {
        self::assertSame((new TilePalette(true))->glyph('1'), TilePalette::playerMarker(0));
        self::assertSame(1, mb_strwidth(TilePalette::playerMarker(0), 'UTF-8'));
    }

    public function testEveryTileIsExactlyTwoColumnsWide(): void
    {
        $palette = new TilePalette(trueColor: true);

        foreach ([MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE, MapBuilder::FLEUR, '1', '2'] as $tile) {
            foreach ([[0, 0], [1, 0], [3, 5]] as [$x, $y]) {
                self::assertSame(
                    TilePalette::TILE_WIDTH,
                    mb_strwidth($palette->cell($tile, $x, $y)->content, 'UTF-8'),
                    sprintf('la tuile %s ne fait pas deux colonnes', $tile)
                );
            }
        }
    }

    /**
     * The whole point of hashing the coordinates: a tile keeps its shade
     * frame after frame. Drawing it at random would make the map shimmer.
     */
    public function testATileAlwaysGetsTheSameShade(): void
    {
        $palette = new TilePalette(trueColor: true);

        self::assertEquals(
            $palette->style(MapBuilder::HERBE, 4, 7),
            $palette->style(MapBuilder::HERBE, 4, 7)
        );
    }

    public function testGrassIsNotOneFlatColour(): void
    {
        $palette = new TilePalette(trueColor: true);
        $shades = [];

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bg = $palette->style(MapBuilder::HERBE, $x, $y)->bg;
                self::assertInstanceOf(RgbColor::class, $bg);
                $shades[$bg->toHex()] = true;
            }
        }

        self::assertGreaterThan(1, count($shades), 'plusieurs verts, pas un aplat unique');
    }

    /**
     * Regression guard on the hash itself. The first version used crc32,
     * which is linear: its shades repeated every four tiles and the meadow
     * came out woven with diagonal stripes. A shifted copy of the map that
     * matches the original is exactly that failure.
     */
    public function testTheGrainRepeatsNoVisiblePattern(): void
    {
        $palette = new TilePalette(trueColor: true);

        for ($shift = 1; $shift <= 8; $shift++) {
            foreach ([[$shift, 0], [0, $shift], [$shift, $shift]] as [$dx, $dy]) {
                $changed = 0;
                $total = 0;

                for ($y = 0; $y < 16; $y++) {
                    for ($x = 0; $x < 16; $x++) {
                        $total++;
                        $here = $palette->style(MapBuilder::HERBE, $x, $y)->bg?->toHex();
                        $there = $palette->style(MapBuilder::HERBE, $x + $dx, $y + $dy)->bg?->toHex();

                        if ($here !== $there) {
                            $changed++;
                        }
                    }
                }

                // Four shades drawn independently differ three times in four;
                // anything close to zero means the pattern simply repeats.
                self::assertGreaterThan(
                    0.4,
                    $changed / $total,
                    sprintf('le grain se repete tous les %d;%d', $dx, $dy)
                );
            }
        }
    }

    public function testTheGrainIsEvenlySpread(): void
    {
        $palette = new TilePalette(trueColor: true);
        $counts = [];

        for ($y = 0; $y < 25; $y++) {
            for ($x = 0; $x < 40; $x++) {
                $hex = (string) $palette->style(MapBuilder::HERBE, $x, $y)->bg?->toHex();
                $counts[$hex] = ($counts[$hex] ?? 0) + 1;
            }
        }

        self::assertCount(4, $counts);

        foreach ($counts as $hex => $count) {
            self::assertEqualsWithDelta(250, $count, 60, sprintf('%s domine ou disparait', $hex));
        }
    }

    public function testFlowersBloomInSeveralColoursOverTheGrass(): void
    {
        $palette = new TilePalette(trueColor: true);
        $petals = [];

        for ($x = 0; $x < 8; $x++) {
            $style = $palette->style(MapBuilder::FLEUR, $x, 0);
            self::assertInstanceOf(RgbColor::class, $style->fg);
            self::assertInstanceOf(RgbColor::class, $style->bg);
            $petals[$style->fg->toHex()] = true;

            // The meadow shows through: a flower keeps a grass background.
            self::assertSame(
                $palette->style(MapBuilder::HERBE, $x, 0)->bg?->toHex(),
                $style->bg->toHex()
            );
        }

        self::assertGreaterThan(1, count($petals));
    }

    public function testTerrainsDoNotShareTheirColours(): void
    {
        $palette = new TilePalette(trueColor: true);

        $backgrounds = array_map(
            static fn (string $tile): ?string => $palette->style($tile, 0, 0)->bg?->toHex(),
            [MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE, '1']
        );

        self::assertSame($backgrounds, array_unique($backgrounds));
    }

    /**
     * Terminal.app and friends cannot do 24 bit colour, so the palette must
     * still say something sensible with sixteen.
     */
    public function testTheFallbackUsesAnsiColoursOnly(): void
    {
        $palette = new TilePalette(trueColor: false);

        foreach ([MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE, MapBuilder::FLEUR, '1'] as $tile) {
            self::assertInstanceOf(AnsiColor::class, $palette->style($tile, 3, 5)->bg);
        }
    }

    public function testTheFallbackDoesNotPretendToHaveShades(): void
    {
        $palette = new TilePalette(trueColor: false);

        self::assertEquals(
            $palette->style(MapBuilder::HERBE, 0, 0),
            $palette->style(MapBuilder::HERBE, 5, 9)
        );
    }

    public function testDetectionFollowsColorterm(): void
    {
        putenv('COLORTERM=truecolor');
        self::assertInstanceOf(RgbColor::class, TilePalette::detect()->style(MapBuilder::HERBE, 0, 0)->bg);

        putenv('COLORTERM');
        self::assertInstanceOf(AnsiColor::class, TilePalette::detect()->style(MapBuilder::HERBE, 0, 0)->bg);
    }
}
