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

    /**
     * **The isometric view is always 1:1, and it puts the zoom back.**
     *
     * Its coverage is a diamond of some seventy cells a side, so at 1:4 it
     * asks the world for nearly three hundred rows where there are a hundred
     * and sixty. Measured, that leaves 41% of the screen as sky, and 92% at
     * 1:8 — the picture closes into a wedge. Swapping views must not cost the
     * zoom the map view was set to either.
     */
    /**
     * A frame through a cat's eyes is composed like any other, with no window
     * anywhere near it.
     */
    public function testTheWorldIsSeenFromWhereTheCatStands(): void
    {
        $container = new WorldContainer();
        $container->setWorld(WorldFactory::fromRows(array_fill(0, 40, str_repeat('X', 40)), chatX: 20, chatY: 20));

        $render = $this->render(container: $container);
        $render->toggleView();
        $render->toggleView();

        [$eyes] = $render->compose(array_fill(0, 40, array_fill(0, 40, 'X')));

        self::assertSame(256, $eyes->width());
        self::assertSame(160, $eyes->height());

        // Sky above the horizon and ground below it: a picture that were one
        // flat colour would mean the rays never met anything at all.
        $above = $eyes->at(128, 20);
        $below = $eyes->at(128, 140);

        self::assertNotSame($above, $below, 'le ciel et le sol se confondent');
    }

    public function testTheIsometricViewIsAlwaysCloseAndGivesTheZoomBack(): void
    {
        $camera = new Camera();
        $camera->zoomOut();
        $camera->zoomOut();
        self::assertSame(4, $camera->scale());

        $render = $this->render($camera);

        self::assertTrue($render->toggleView());
        self::assertSame(1, $camera->scale(), 'la 2.5d echantillonne le monde');

        // Zooming there means a bigger tile, never a bigger bite of the world:
        // the camera must not move at all.
        self::assertTrue($render->zoom(true));
        self::assertSame(1, $camera->scale(), 'la 2.5d a echantillonne en zoomant');
        self::assertFalse($render->zoom(true), 'il n y a pas de cran au dela');

        // Three views in a ring: map, isometric, the cat's eyes, and back.
        // The map's zoom comes back only on the way round to it.
        self::assertTrue($render->toggleView(), 'les yeux du chat');
        self::assertSame(1, $camera->scale());
        self::assertFalse($render->zoom(true), 'rien a zoomer derriere des yeux');

        self::assertFalse($render->toggleView(), 'retour a la carte');
        self::assertSame(4, $camera->scale(), 'le zoom de la carte est perdu');
    }

    /**
     * The overview answers *where am I* when the picture itself no longer can.
     *
     * The isometric view is always 1:1 and shows a few thousand tiles out of
     * forty thousand, so it is the one view with no way to say where in the
     * world it is looking.
     */
    public function testTheOverviewShowsTheWholeWorldAndTakesAClick(): void
    {
        $camera = new Camera();
        $render = $this->render($camera);
        $world = array_fill(0, 160, array_fill(0, 256, 'X'));

        // Nothing has been drawn yet, so there is nothing to click on.
        self::assertFalse($render->jumpTo(140, 60));

        $render->compose($world);

        // Swept rather than aimed: the box is decided by the panel's layout,
        // and a test that hard-codes it is a test of the layout rather than of
        // the overview.
        $hits = [];

        for ($row = 0; $row < 80; $row++) {
            for ($column = 128; $column < 178; $column++) {
                if ($render->jumpTo($column, $row)) {
                    $hits[] = [$column, $row];
                }
            }
        }

        self::assertNotEmpty($hits, 'aucun clic ne tombe sur la mini carte');

        // A 256x160 world sampled every other tile is 128x80 pixels, which is
        // seventeen cells by eleven — measured, 187. The threshold is loose
        // because the layout may move it; what it refuses is a box of four
        // cells, which would be a rounding error rather than an overview.
        self::assertGreaterThan(100, count($hits), 'la mini carte est minuscule');

        $camera->centreOn(0, 0);
        $before = [$camera->x(), $camera->y()];
        [$column, $row] = $hits[count($hits) - 1];

        self::assertTrue($render->jumpTo($column, $row));
        self::assertNotSame($before, [$camera->x(), $camera->y()], 'la vue n a pas bouge');
    }

    /**
     * A click on the map itself must not also be a jump: the overview sits
     * inside the panel, so the two are asked in order and only one may answer.
     */
    public function testAClickOnTheWorldIsNotAJump(): void
    {
        $render = $this->render();
        $render->compose(array_fill(0, 160, array_fill(0, 256, 'X')));

        self::assertFalse($render->jumpTo(10, 10), 'un clic sur la carte a saute');
        self::assertTrue($render->isOverMap(10, 10));
    }

    /**
     * **Nothing in the panel may sit on anything else.**
     *
     * The memory panel, the follow button and the overview were each placed by
     * their own sum from the foot of the panel, so none knew the others were
     * there and the overview came down across the button. A cell that answers
     * two things is the signature, and it is what this sweeps for — the parts
     * are stacked in one chain now, each starting where the one below it
     * ended.
     */
    public function testNothingInThePanelSitsOnAnythingElse(): void
    {
        $render = $this->render();
        $render->compose(array_fill(0, 160, array_fill(0, 256, 'X')));

        $clashes = [];

        for ($row = 0; $row < 80; $row++) {
            for ($column = 128; $column < 178; $column++) {
                if ($render->isOverFocusButton($column, $row) && $render->jumpTo($column, $row)) {
                    $clashes[] = $column . ';' . $row;
                }
            }
        }

        self::assertSame([], $clashes, 'le bouton et la mini carte se chevauchent');
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
