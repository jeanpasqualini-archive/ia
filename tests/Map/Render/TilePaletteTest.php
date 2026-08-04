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
    /**
     * Everything on the map is a colour now — the ground it stands on and the
     * thing standing on it alike. A tile is half a cell, and half a character
     * does not exist.
     */
    public function testEveryTileHasAColourOfItsOwn(): void
    {
        $palette = new TilePalette(trueColor: true);

        foreach (MapBuilder::getAllowedItems() as $tile) {
            self::assertNotSame('', $palette->pixel($tile, 0, 0)->toHex(), sprintf('la tuile %s', $tile));
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
        // The map has no glyphs left at all — it is drawn in colour. What
        // remains is the panel, where a cat is still a shape.
        $glyphs = ['▀'];

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
     * The panel is the one place a cat still has a shape, and like everything
     * that flows as text it has to be one column wide.
     */
    public function testThePanelMarkerIsOneColumnWide(): void
    {
        self::assertSame(1, mb_strwidth(TilePalette::playerMarker(0), 'UTF-8'));
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

        $colours = array_map(
            static fn (string $tile): string => $palette->pixel($tile, 0, 0)->toHex(),
            [
                MapBuilder::HERBE, MapBuilder::EAU, MapBuilder::ARBRE, MapBuilder::FOURRE,
                MapBuilder::TROU, MapBuilder::RONCE, MapBuilder::FLEUR, MapBuilder::DIGITALE,
                MapBuilder::ROCHE, MapBuilder::GALERIE, MapBuilder::CHAMPIGNON, '1',
            ]
        );

        self::assertSame($colours, array_unique($colours), 'rien ne se confond avec rien');
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

    /**
     * The bug that emptied the sixteen colour map: `pixel()` used to read the
     * *background* of the cell, and a flower is a magenta character standing
     * on a green background. So every flower, foxglove and bramble came back
     * the exact green of the meadow — four thousand of them a map, gone into
     * the grass.
     *
     * A tile is half a cell now and has one colour. What is there has to win
     * over what it stands on.
     */
    public function testNothingGrowingOnTheGrassIsPaintedAsGrass(): void
    {
        $palette = new TilePalette(trueColor: false);
        $grass = $palette->pixel(MapBuilder::HERBE, 0, 0);

        foreach ([MapBuilder::FLEUR, MapBuilder::DIGITALE, MapBuilder::RONCE, MapBuilder::TROU] as $tile) {
            self::assertNotSame(
                $grass,
                $palette->pixel($tile, 0, 0),
                sprintf('la tuile %s disparait dans la prairie', $tile)
            );
        }
    }

    /**
     * Sixteen colours are few, so what matters is that the things a cat has to
     * tell apart stay apart: the meal, the poison, the sting and the pit.
     */
    public function testTheSixteenColoursStillTellTheDangersApart(): void
    {
        $palette = new TilePalette(trueColor: false);

        $seen = array_map(
            static fn (string $tile): string => $palette->pixel($tile, 0, 0)->name,
            [
                MapBuilder::HERBE, MapBuilder::ARBRE, MapBuilder::EAU,
                MapBuilder::FLEUR, MapBuilder::DIGITALE, MapBuilder::RONCE, MapBuilder::TROU,
            ]
        );

        self::assertSame($seen, array_unique($seen), 'deux choses distinctes ont la meme couleur');
    }

    public function testTheFallbackDoesNotPretendToHaveShades(): void
    {
        $palette = new TilePalette(trueColor: false);

        self::assertEquals(
            $palette->style(MapBuilder::HERBE, 0, 0),
            $palette->style(MapBuilder::HERBE, 5, 9)
        );
    }

    /**
     * The whole point of the swell: the lake is different a moment later, and
     * the meadow beside it is not. Ground that moved would make the map
     * shimmer, which is the failure the hashed grain was written to avoid.
     */
    public function testTheWaterMovesAndTheGroundDoesNot(): void
    {
        $palette = new TilePalette(trueColor: true);
        $before = $this->strip($palette, MapBuilder::EAU);
        $grass = $this->strip($palette, MapBuilder::HERBE);

        $palette->animate(0.5);

        $moved = count(array_diff_assoc($this->strip($palette, MapBuilder::EAU), $before));

        self::assertGreaterThan(20, $moved, 'le lac est fige');
        self::assertSame($grass, $this->strip($palette, MapBuilder::HERBE), 'la prairie scintille');
    }

    /**
     * A swell, not a shimmer. Neighbouring tiles belong to the same wave, so
     * they are all but the same colour; drawn per tile at random the lake
     * would be static noise, which is what this measures the distance from.
     */
    public function testTheSwellIsAWaveAndNotNoise(): void
    {
        $palette = new TilePalette(trueColor: true);
        $palette->animate(1.3);

        for ($y = 0; $y < 12; $y++) {
            for ($x = 0; $x < 24; $x++) {
                $here = $palette->pixel(MapBuilder::EAU, $x, $y);
                $right = $palette->pixel(MapBuilder::EAU, $x + 1, $y);

                self::assertInstanceOf(RgbColor::class, $here);
                self::assertInstanceOf(RgbColor::class, $right);

                self::assertLessThan(
                    12,
                    abs($here->b - $right->b),
                    sprintf('saut brutal en %d;%d', $x, $y)
                );
            }
        }
    }

    /**
     * Two waves rather than one, which is what keeps the lake from reading as
     * a ruler sliding across it: a single band repeats over its own
     * wavelength, and a shifted copy of the water would match the original
     * almost everywhere.
     *
     * The threshold is loose on purpose, and it has to be. The swell only has
     * six levels, so two unrelated tiles already agree one time in six, and a
     * plateau makes neighbours agree far more often than that — measured, the
     * worst shift matches 30% of the map. What a repeating pattern looks like
     * is not "more than chance", it is "nearly all of them".
     */
    public function testTheSwellNeverQuiteRepeats(): void
    {
        $palette = new TilePalette(trueColor: true);
        $palette->animate(2.0);

        for ($shift = 1; $shift <= 16; $shift++) {
            $same = 0;

            for ($y = 0; $y < 16; $y++) {
                for ($x = 0; $x < 16; $x++) {
                    $here = $palette->pixel(MapBuilder::EAU, $x, $y)->toHex();
                    $there = $palette->pixel(MapBuilder::EAU, $x + $shift, $y + $shift)->toHex();

                    if ($here === $there) {
                        $same++;
                    }
                }
            }

            self::assertLessThan(128, $same, sprintf('la houle se repete tous les %d', $shift));
        }
    }

    /**
     * Whatever the wave does, the lake stays a lake: the interpolation must
     * not wander outside the two blues it runs between.
     */
    public function testTheWaterStaysBlue(): void
    {
        $palette = new TilePalette(trueColor: true);

        foreach ([0.0, 0.7, 1.9, 3.3, 12.5] as $phase) {
            $palette->animate($phase);

            for ($x = 0; $x < 30; $x++) {
                $colour = $palette->pixel(MapBuilder::EAU, $x, $x % 7);

                self::assertInstanceOf(RgbColor::class, $colour);
                self::assertGreaterThan($colour->r, $colour->b, sprintf('en %d, ce n est plus de l eau', $x));
                self::assertGreaterThan($colour->g, $colour->b);
            }
        }
    }

    /**
     * Sixteen colours have one blue, and a swell has nothing to move through.
     * Still water is the honest answer there, not a lake that blinks between
     * two of the terminal's colours.
     */
    public function testSixteenColoursKeepTheirWaterStill(): void
    {
        $palette = new TilePalette(trueColor: false);
        $before = $palette->pixel(MapBuilder::EAU, 3, 4);

        $palette->animate(1.7);

        self::assertEquals($before, $palette->pixel(MapBuilder::EAU, 3, 4));
    }

    /**
     * The swell is quantised, and this is a bandwidth budget rather than a
     * matter of taste.
     *
     * php-tui sends the terminal only the cells that changed since the last
     * frame. A continuous colour guarantees every water cell changed on every
     * frame — measured at 35 070 bytes a frame, half a megabyte a second — and
     * a write to the tty blocks, so a terminal that cannot swallow that stalls
     * the simulation behind it. Six levels bring it to 4 980 bytes.
     *
     * The count is what the escape volume follows from, so the count is what
     * is asserted.
     */
    public function testTheSwellTakesStepsAndNotAGradient(): void
    {
        $palette = new TilePalette(trueColor: true);
        $palette->animate(1.7);

        $levels = [];

        for ($y = 0; $y < 40; $y++) {
            for ($x = 0; $x < 40; $x++) {
                $levels[$palette->pixel(MapBuilder::EAU, $x, $y)->toHex()] = true;
            }
        }

        self::assertLessThanOrEqual(6, count($levels), 'le lac a repris un degrade continu');
        self::assertGreaterThan(3, count($levels), 'il ne reste plus de houle du tout');
    }

    /**
     * The same budget, measured the way the terminal feels it: how much of the
     * lake has to be repainted from one frame to the next. Continuous it was
     * 93%; this is what that number must never go back to.
     */
    public function testMostOfTheLakeIsUnchangedFromOneFrameToTheNext(): void
    {
        $palette = new TilePalette(trueColor: true);
        $frame = 1 / 15;
        $changed = 0;
        $total = 0;

        for ($step = 0; $step < 6; $step++) {
            $palette->animate($step * $frame);
            $before = [];

            for ($y = 0; $y < 30; $y++) {
                for ($x = 0; $x < 30; $x++) {
                    $before[$x . ';' . $y] = $palette->pixel(MapBuilder::EAU, $x, $y)->toHex();
                }
            }

            $palette->animate(($step + 1) * $frame);

            foreach ($before as $key => $hex) {
                [$x, $y] = array_map('intval', explode(';', $key));
                ++$total;

                if ($hex !== $palette->pixel(MapBuilder::EAU, $x, $y)->toHex()) {
                    ++$changed;
                }
            }
        }

        self::assertLessThan(0.25, $changed / $total, 'le terminal repeint tout le lac a chaque frame');
    }

    /**
     * A row of tiles read as hex, which is what a change in the water can be
     * measured on.
     *
     * @return array<int, string>
     */
    private function strip(TilePalette $palette, string $tile): array
    {
        $colours = [];

        for ($x = 0; $x < 40; $x++) {
            $colours[$x] = $palette->pixel($tile, $x, $x % 5)->toHex();
        }

        return $colours;
    }

    /**
     * Colour depth is the resolution of a map drawn in colour alone, so it is
     * worth asking properly rather than settling for the sixteen every
     * terminal is certain to have.
     */
    public function testDepthIsReadFromTheTerminalAndNotAssumed(): void
    {
        $this->withEnvironment(['COLORTERM' => 'truecolor', 'TERM_PROGRAM' => false, 'TERM' => 'xterm-256color'], function (): void {
            self::assertSame(16777216, TilePalette::depth());
        });

        $this->withEnvironment(['COLORTERM' => false, 'TERM_PROGRAM' => false, 'TERM' => 'xterm-256color'], function (): void {
            // No 256 rung on purpose: quantised into the xterm cube, three
            // of the four meadow greens land on #5f5f5f — a grey — and the
            // wood collapses onto one entry. Flat sixteen colours keep the
            // meaning; the cube changes it.
            self::assertSame(16, TilePalette::depth());
        });

        $this->withEnvironment(['COLORTERM' => false, 'TERM_PROGRAM' => false, 'TERM' => 'dumb'], function (): void {
            self::assertSame(16, TilePalette::depth());
        });
    }

    /**
     * COLORTERM is inherited, so it speaks for the terminal that started the
     * shell rather than the one drawing the frame — exported from a profile,
     * or carried across an ssh or a tmux, it describes something else.
     *
     * There used to be a table of terminals to disbelieve here, holding
     * Terminal.app on the grounds that it has no 24 bit colour. Measured on a
     * real one, it has. Guessing at the far end of the pipe is exactly what
     * --colours exists to stop, so nothing guesses any more.
     */
    public function testNoTerminalIsSecondGuessedByItsName(): void
    {
        foreach (['Apple_Terminal', 'Tabby', 'iTerm.app', ''] as $program) {
            $this->withEnvironment(['COLORTERM' => 'truecolor', 'TERM_PROGRAM' => '' === $program ? false : $program], function () use ($program): void {
                self::assertSame(16777216, TilePalette::depth(), sprintf('%s a ete juge sur son nom', $program));
            });
        }
    }

    /**
     * There are two answers and not three. Everything above sixteen is drawn
     * in full colour; everything else takes the flat sixteen, which keeps a
     * meadow green where the 256 colour cube turns it grey.
     */
    public function testThereAreOnlyTwoWaysToPaintTheMap(): void
    {
        $this->withEnvironment(['COLORTERM' => 'truecolor', 'TERM_PROGRAM' => false, 'TERM' => 'xterm-256color'], function (): void {
            self::assertInstanceOf(RgbColor::class, TilePalette::detect()->style(MapBuilder::HERBE, 0, 0)->bg);
        });

        $this->withEnvironment(['COLORTERM' => false, 'TERM_PROGRAM' => false, 'TERM' => 'xterm-256color'], function (): void {
            self::assertInstanceOf(AnsiColor::class, TilePalette::detect()->style(MapBuilder::HERBE, 0, 0)->bg);
        });
    }

    /**
     * @param array<string, string|false> $environment
     */
    private function withEnvironment(array $environment, callable $test): void
    {
        $before = [];

        foreach ($environment as $name => $value) {
            $before[$name] = getenv($name);
            putenv(false === $value ? $name : $name . '=' . $value);
        }

        try {
            $test();
        } finally {
            foreach ($before as $name => $value) {
                putenv(false === $value ? $name : $name . '=' . $value);
            }
        }
    }
}
