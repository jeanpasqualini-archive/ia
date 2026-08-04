<?php

declare(strict_types=1);

namespace Tests\Runtime;

use PHPUnit\Framework\TestCase;
use Runtime\Camera;

final class CameraTest extends TestCase
{
    public function testItStartsOnTheCornerAtFullDetail(): void
    {
        $camera = new Camera();

        self::assertSame(0, $camera->x());
        self::assertSame(0, $camera->y());
        self::assertSame(1, $camera->scale());
        self::assertSame('1:1', $camera->label());
        self::assertTrue($camera->isClosest());
    }

    /**
     * A keypress moves the view the same distance on screen whatever the
     * zoom, which means a different distance over the ground — the point of
     * being zoomed out.
     */
    public function testPanningCoversMoreGroundWhenZoomedOut(): void
    {
        $camera = new Camera();
        $camera->clamp(1000, 1000, 32, 17);

        $camera->pan(1, 0);
        $close = $camera->x();

        $camera->zoomOut();
        $camera->clamp(1000, 1000, 32, 17);
        $before = $camera->x();
        $camera->pan(1, 0);

        self::assertSame(2 * $close, $camera->x() - $before);
    }

    public function testTheViewStaysOverTheMap(): void
    {
        $camera = new Camera();

        $camera->pan(-5, -5);
        $camera->clamp(100, 100, 32, 17);

        self::assertSame(0, $camera->x(), 'pas de coin negatif');
        self::assertSame(0, $camera->y());

        $camera->pan(100, 100);
        $camera->clamp(100, 100, 32, 17);

        self::assertSame(100 - 32, $camera->x(), 'la derniere colonne reste visible');
        self::assertSame(100 - 17, $camera->y());
    }

    public function testAWorldSmallerThanTheViewIsPinnedToTheCorner(): void
    {
        $camera = new Camera();

        $camera->pan(10, 10);
        $camera->clamp(10, 8, 32, 17);

        self::assertSame(0, $camera->x());
        self::assertSame(0, $camera->y());
    }

    /**
     * Anchored on the corner instead, whatever is being looked at slides off
     * exactly when the user asks to see it closer.
     */
    public function testZoomingHoldsTheMiddleOfTheViewStill(): void
    {
        $camera = new Camera();
        $camera->clamp(1000, 1000, 32, 16);
        $camera->pan(10, 10);
        $camera->clamp(1000, 1000, 32, 16);

        $middleX = $camera->x() + intdiv(32 * $camera->scale(), 2);
        $middleY = $camera->y() + intdiv(16 * $camera->scale(), 2);

        $camera->zoomOut();
        $camera->clamp(1000, 1000, 32, 16);

        self::assertSame($middleX, $camera->x() + intdiv(32 * $camera->scale(), 2));
        self::assertSame($middleY, $camera->y() + intdiv(16 * $camera->scale(), 2));
    }

    /**
     * The sign is the whole point of dragging and the easiest thing to get
     * backwards: the ground follows the hand, so pulling to the right brings
     * in what was on the left.
     */
    public function testDraggingPullsTheGroundWithTheCursor(): void
    {
        $camera = new Camera();
        $camera->clamp(1000, 1000, 32, 17);
        $camera->pan(5, 5);
        $camera->clamp(1000, 1000, 32, 17);

        $x = $camera->x();
        $camera->dragBy(3, 0);

        self::assertSame($x - 3, $camera->x(), 'tirer a droite montre ce qui etait a gauche');

        $camera->zoomOut();
        $camera->clamp(1000, 1000, 32, 17);
        $y = $camera->y();
        $camera->dragBy(0, 3);

        self::assertSame($y - 6, $camera->y(), 'une cellule vaut deux tuiles a 1:2');
    }

    public function testTheZoomStopsAtBothEnds(): void
    {
        $camera = new Camera();

        for ($i = 0; $i < 10; $i++) {
            $camera->zoomIn();
        }

        self::assertSame(1, $camera->scale());
        self::assertTrue($camera->isClosest());

        for ($i = 0; $i < 10; $i++) {
            $camera->zoomOut();
        }

        self::assertSame(8, $camera->scale());
        self::assertTrue($camera->isWidest());
    }
}
