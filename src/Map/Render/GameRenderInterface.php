<?php

declare(strict_types=1);

namespace Map\Render;

use Map\Player\PlayerInterface;

/**
 * Everything the game loop asks of whatever is drawing it.
 *
 * `MapRenderInterface` was enough while the map was all a renderer did. The
 * loop has grown to ask for the panel's tab, which cat is selected, and where
 * on the screen a click landed — and once there are two renderers those
 * questions have to be part of the contract rather than of one class.
 *
 * **The geometry belongs here rather than in the loop.** The renderer is what
 * decided the layout, so it is the only thing that can say whether a position
 * falls on the map or what a drag is worth in tiles. A terminal answers in
 * half blocks and a window in square cells, and the loop is spared both.
 */
interface GameRenderInterface extends MapRenderInterface
{
    /**
     * Throw away whatever is believed to be on screen, so the next frame is
     * painted whole. Meaningful where a frame is a difference; a window paints
     * everything every time and has nothing to force.
     */
    public function repaint(): void;

    public function nextTab(): void;

    public function selectTab(int $index): void;

    /** The cat the panel is describing, which is also the one the view follows. */
    public function selectedPlayer(): ?PlayerInterface;

    /** Centre the view on that cat. False when there is no cat to centre on. */
    public function focusOnSelectedPlayer(): bool;

    public function isOverMap(int $column, int $row): bool;

    public function isOverFocusButton(int $column, int $row): bool;

    /**
     * Swap between ways of looking at the world, where there is more than one.
     * Answers whether anything changed, so the loop knows to redraw — the
     * terminal has a single view and says no.
     */
    public function toggleView(): bool;

    /**
     * A movement on screen turned into tiles.
     *
     * @return array{int, int}
     */
    public function toCells(int $columns, int $rows): array;
}
