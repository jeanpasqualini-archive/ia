<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use Logger\MultipleLogger;
use Map\Render\Pixels;
use Map\Render\SdlRender;
use Map\Render\TilePalette;
use Map\World\WorldContainer;
use Memory\MemoryManager;
use PHPUnit\Framework\TestCase;
use Runtime\Camera;
use Runtime\TimeControl;
use Tests\WorldFactory;

/**
 * The window renderer, composed with no window anywhere.
 *
 * `compose()` is the seam: it builds the three surfaces of a frame and touches
 * nothing of SDL, exactly as the terminal renderer can be drawn into php-tui's
 * recording backend. A renderer that could only be checked by looking at it
 * would be a renderer nothing guards.
 */
final class SdlRenderTest extends TestCase
{
    public function testAWholeFrameIsComposedWithNoScreenAtAll(): void
    {
        [$map, $sidebar, $bottom] = $this->render()->compose($this->ground(60, 40));

        // The map is one pixel per cell and blown up by the GPU; the panels
        // are at true pixel size, or text would come out in letters as tall
        // as a lake.
        self::assertSame(128, $map->width());
        self::assertSame(80, $map->height());
        self::assertSame(400, $sidebar->width());
        self::assertSame(1424, $bottom->width());
    }

    /**
     * The camera is shared with the terminal rather than reimplemented, so
     * panning has to move the ground by exactly what it says. A second copy of
     * this arithmetic is how two views of one world disagree about where a cat
     * is.
     */
    public function testPanningMovesTheGroundByTheTilesAsked(): void
    {
        $camera = new Camera();
        $render = $this->render($camera);
        $ground = $this->ground(200, 120);

        // A single mark on the map, twelve tiles in.
        $ground[0][12] = 'E';

        [$before] = $render->compose($ground);
        self::assertSame($this->water(12, 0), $before->at(12, 0), 'le repere n est pas la ou il devrait');

        // slide() and not pan(): panning moves by a whole step of several
        // cells, which is what a key press is worth, and this is measuring the
        // arithmetic rather than the binding.
        $camera->slide(4, 0);
        [$after] = $render->compose($ground);

        self::assertSame($this->water(12, 0), $after->at(8, 0), 'le sol n a pas glisse de quatre tuiles');
    }

    /**
     * Zooming out samples rather than averages, exactly as in the terminal:
     * one tile speaks for its block. At 1:4 the cell that was showing tile 4
     * shows tile 16.
     */
    public function testZoomingOutShowsFourTimesTheGround(): void
    {
        $camera = new Camera();
        $render = $this->render($camera);
        $ground = $this->ground(200, 120);
        $ground[0][16] = 'E';

        $camera->zoomOut();
        $camera->zoomOut();

        [$pixels] = $render->compose($ground);

        self::assertSame(4, $camera->scale());
        self::assertSame($this->water(16, 0), $pixels->at(4, 0), 'le dezoom n echantillonne pas');
    }

    /**
     * The panels are not blank, and they are not the map: a layout that put
     * the sidebar at the wrong size would still compose, and would show
     * nothing at all.
     */
    public function testThePanelsAreWrittenOnAndNotLeftEmpty(): void
    {
        $container = new WorldContainer();
        $container->setWorld(WorldFactory::fromRows(['XXF', 'XXX', 'XXX']));

        [, $sidebar, $bottom] = $this->render(container: $container)->compose($this->ground(60, 40));

        self::assertGreaterThan(200, $this->inked($sidebar), 'le panneau lateral est vide');
        self::assertGreaterThan(100, $this->inked($bottom), 'la barre du bas est vide');
    }

    /**
     * A cell is square here and holds one row of tiles, where a terminal cell
     * is two half blocks. The loop asks the renderer rather than knowing, so
     * this is the answer that keeps a drag worth the same in both.
     */
    public function testACellIsOneTileEachWay(): void
    {
        self::assertSame([3, 5], $this->render()->toCells(3, 5));
        self::assertTrue($this->render()->isOverMap(0, 0));
        self::assertFalse($this->render()->isOverMap(128, 0));
    }

    private function water(int $x, int $y): int
    {
        return Pixels::pack((new TilePalette(trueColor: true))->pixel('E', $x, $y));
    }

    /**
     * Pixels that are neither the panel's background nor its border, which is
     * everything actually written.
     */
    private function inked(Pixels $pixels): int
    {
        $count = 0;

        for ($y = 0; $y < $pixels->height(); $y += 2) {
            for ($x = 0; $x < $pixels->width(); $x += 2) {
                if (0xFF16130F !== $pixels->at($x, $y) && 0xFF3A342C !== $pixels->at($x, $y)) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function ground(int $width, int $height): array
    {
        return array_fill(0, $height, array_fill(0, $width, 'X'));
    }

    private function render(?Camera $camera = null, ?WorldContainer $container = null): SdlRender
    {
        return new SdlRender(
            new MultipleLogger(),
            $container ?? new WorldContainer(),
            new MemoryManager('test'),
            new TimeControl(),
            palette: new TilePalette(trueColor: true),
            camera: $camera ?? new Camera(),
        );
    }
}
