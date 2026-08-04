<?php

declare(strict_types=1);

namespace Runtime;

/**
 * Which part of the world is on screen, and how closely.
 *
 * The map used to be built at exactly the size of the terminal, so there was
 * nothing to look at that was not already visible. Now the world has its own
 * size and this decides the window into it.
 *
 * It lives here rather than in `World` for the same reason `TimeControl` does:
 * it is a way of *looking* at the simulation, not part of it. A snapshot must
 * restore what the cats were doing, not where the user happened to be looking
 * — and anything reachable from `World` has to survive serialization.
 */
final class Camera
{
    /**
     * World tiles per rendered cell.
     *
     * Only zooming out is offered. At 1 a cell is a tile and the detail is
     * already maximal — a closer view would enlarge the blocks without adding
     * anything to them — while at 8 a terminal of a hundred columns shows the
     * whole map at once.
     *
     * @var list<int>
     */
    private const SCALES = [1, 2, 4, 8];

    /** Cells the view slides by, so panning feels the same at any zoom. */
    private const PAN_CELLS = 4;

    private int $level = 0;

    private int $x = 0;

    private int $y = 0;

    /** Size of the view in cells, remembered from the last frame drawn. */
    private int $cellsX = 0;

    private int $cellsY = 0;

    public function scale(): int
    {
        return self::SCALES[$this->level];
    }

    public function x(): int
    {
        return $this->x;
    }

    public function y(): int
    {
        return $this->y;
    }

    public function isClosest(): bool
    {
        return 0 === $this->level;
    }

    public function isWidest(): bool
    {
        return $this->level === count(self::SCALES) - 1;
    }

    public function label(): string
    {
        return '1:' . $this->scale();
    }

    public function zoomIn(): void
    {
        $this->step(-1);
    }

    public function zoomOut(): void
    {
        $this->step(1);
    }

    /**
     * Slide the view by whole cells. The step is multiplied by the scale, so
     * a keypress moves the same distance on screen whatever the zoom — at 1:8
     * that is eight times as much ground, which is the point of being zoomed
     * out.
     */
    public function pan(int $dx, int $dy): void
    {
        $this->x += $dx * self::PAN_CELLS * $this->scale();
        $this->y += $dy * self::PAN_CELLS * $this->scale();
    }

    /**
     * Keep the view over the map.
     *
     * Called on every frame rather than after every move: zooming out changes
     * how much ground the view covers, so a corner that was legal a moment ago
     * may hang off the edge without anything having been panned.
     */
    public function clamp(int $worldWidth, int $worldHeight, int $cellsX, int $cellsY): void
    {
        // Kept so zooming can hold the middle of the view still: the scale
        // alone does not say how much ground a view covers.
        $this->cellsX = $cellsX;
        $this->cellsY = $cellsY;

        $this->x = $this->fit($this->x, $worldWidth, $cellsX);
        $this->y = $this->fit($this->y, $worldHeight, $cellsY);
    }

    private function fit(int $origin, int $worldSize, int $cells): int
    {
        $visible = $cells * $this->scale();

        // A world smaller than the view is pinned at the origin rather than
        // pushed to a negative offset.
        if ($visible >= $worldSize) {
            return 0;
        }

        return max(0, min($origin, $worldSize - $visible));
    }

    private function step(int $by): void
    {
        $level = $this->level + $by;

        if ($level < 0 || $level >= count(self::SCALES)) {
            return;
        }

        $before = $this->scale();
        $this->level = $level;
        $widened = $before - $this->scale();

        // Zooming holds the middle of the view still. Anchored on the corner
        // instead, whatever is being looked at slides off exactly when the
        // user asks to see it closer.
        $this->x += intdiv($this->cellsX * $widened, 2);
        $this->y += intdiv($this->cellsY * $widened, 2);
    }
}
