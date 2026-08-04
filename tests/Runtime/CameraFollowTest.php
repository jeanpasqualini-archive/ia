<?php

declare(strict_types=1);

namespace Tests\Runtime;

use Runtime\Camera;
use PHPUnit\Framework\TestCase;

/**
 * Riding along with a cat, which is a way of looking and therefore lives on
 * the camera rather than in either renderer.
 */
final class CameraFollowTest extends TestCase
{
    public function testNothingIsFollowedUntilItIsAskedFor(): void
    {
        self::assertFalse((new Camera())->isFollowing());
    }

    public function testTheToggleAnswersWhatItTurnedInto(): void
    {
        $camera = new Camera();

        self::assertTrue($camera->toggleFollow());
        self::assertTrue($camera->isFollowing());
        self::assertFalse($camera->toggleFollow());
        self::assertFalse($camera->isFollowing());
    }

    /**
     * **Moving the view by hand lets the cat go.**
     *
     * Without it the drag fights the follow and loses: the view snaps back on
     * the very next frame, which reads as the map being broken rather than as
     * a mode being on. Asking to look somewhere else *is* the decision to stop
     * following.
     */
    public function testLookingSomewhereElseEndsTheFollow(): void
    {
        foreach ([
            'les fleches' => static fn (Camera $camera) => $camera->pan(1, 0),
            'un glisser' => static fn (Camera $camera) => $camera->dragBy(3, 2),
            'un pas de cote' => static fn (Camera $camera) => $camera->slide(1, 1),
        ] as $name => $move) {
            $camera = new Camera();
            $camera->toggleFollow();
            $move($camera);

            self::assertFalse($camera->isFollowing(), sprintf('%s ne lache pas le chat', $name));
        }
    }

    /**
     * Centring does not, and must not: it is what the follow itself does every
     * frame, so ending the follow there would turn it off the instant it was
     * turned on.
     */
    public function testCentringOnACatIsNotLookingSomewhereElse(): void
    {
        $camera = new Camera();
        $camera->toggleFollow();
        $camera->centreOn(40, 30);

        self::assertTrue($camera->isFollowing());
    }

    /**
     * Zooming keeps the cat, because a zoom is a question about the same
     * place. Only a move is a question about a different one.
     */
    public function testZoomingKeepsTheCat(): void
    {
        $camera = new Camera();
        $camera->toggleFollow();
        $camera->zoomOut();
        $camera->zoomIn();

        self::assertTrue($camera->isFollowing());
    }
}
