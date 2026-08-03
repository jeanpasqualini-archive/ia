<?php

declare(strict_types=1);

namespace Map\Render;

interface MapRenderInterface
{
    /**
     * Take over the screen. Must be idempotent.
     */
    public function init(): void;

    /**
     * Give the terminal back to the shell.
     */
    public function close(): void;

    /**
     * Playable area, in tiles.
     *
     * @return array{x: int, y: int}
     */
    public function getSize(): array;

    /**
     * @param array<int, array<int, string>> $map
     */
    public function render($map): void;

    /**
     * @param array<int, array<int, string>> $map
     */
    public function clear($map): void;
}
