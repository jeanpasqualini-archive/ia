<?php

declare(strict_types=1);

namespace Tests\Map\Render;

use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Backend;
use PhpTui\Tui\Display\BufferUpdates;
use PhpTui\Tui\Display\Cell;
use PhpTui\Tui\Display\ClearType;
use PhpTui\Tui\Color\RgbColor;
use PhpTui\Tui\Position\Position;

/**
 * A test backend that keeps whole cells rather than just their characters.
 *
 * php-tui's own DummyBackend throws the styles away, which was fine while the
 * map spoke through glyphs. It no longer does: a tile is a colour now, and a
 * frame rendered to a string says nothing at all about what is on it. This
 * keeps the colours so a test can ask where the cat is.
 */
final class RecordingBackend implements Backend
{
    /** @var array<int, array<int, Cell>> indexed [row][column] */
    private array $cells = [];

    public function __construct(private int $width, private int $height)
    {
    }

    public function size(): Area
    {
        return Area::fromScalars(0, 0, $this->width, $this->height);
    }

    /** Cells touched by the last draw, which is what reaches the terminal. */
    private int $lastUpdates = 0;

    public function draw(BufferUpdates $updates): void
    {
        $this->lastUpdates = 0;

        foreach ($updates as $update) {
            $this->cells[$update->position->y][$update->position->x] = $update->cell;
            ++$this->lastUpdates;
        }
    }

    /**
     * How many cells the last frame actually sent. A frame is a diff, so this
     * is the number the terminal feels — and the number a forced repaint has
     * to blow back up.
     */
    public function lastUpdates(): int
    {
        return $this->lastUpdates;
    }

    public function cellAt(int $column, int $row): ?Cell
    {
        return $this->cells[$row][$column] ?? null;
    }

    /**
     * Colours found anywhere in the map area, as hex, with how many cells
     * carry each. Both halves of a cell count: the foreground is the tile
     * above and the background the tile below.
     *
     * @return array<string, int>
     */
    public function coloursOverMap(int $width, int $height): array
    {
        $found = [];

        for ($row = 1; $row <= $height; $row++) {
            for ($column = 1; $column <= $width; $column++) {
                $cell = $this->cellAt($column, $row);

                if (null === $cell) {
                    continue;
                }

                foreach ([$cell->fg, $cell->bg] as $colour) {
                    // Both palettes turn up here: true colour cells carry an
                    // RgbColor, the sixteen colour fallback an AnsiColor, and
                    // only the first knows how to spell itself in hex.
                    $key = $colour instanceof RgbColor ? $colour->toHex() : $colour->name;
                    $found[$key] = ($found[$key] ?? 0) + 1;
                }
            }
        }

        return $found;
    }

    public function toString(): string
    {
        $rows = [];

        foreach ($this->cells as $row) {
            ksort($row);
            $rows[] = implode('', array_map(static fn (Cell $cell): string => $cell->char, $row));
        }

        return implode("\n", $rows);
    }

    public function flush(): void
    {
    }

    public function clearRegion(ClearType $type): void
    {
    }

    public function cursorPosition(): Position
    {
        return new Position(0, 0);
    }

    public function appendLines(int $linesAfterCursor): void
    {
    }

    public function moveCursor(Position $position): void
    {
    }
}
