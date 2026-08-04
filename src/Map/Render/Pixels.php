<?php

declare(strict_types=1);

namespace Map\Render;

use PhpTui\Tui\Color\Color;
use PhpTui\Tui\Color\RgbColor;

/**
 * A rectangle of pixels, composed in PHP and handed to the screen in one call.
 *
 * **Nothing here knows about SDL, and that is the point.** The terminal
 * renderer can be tested headlessly because php-tui ships a backend that
 * records instead of drawing; this is the same seam for the window. A frame is
 * built into one of these and can be read back and asserted on, so the whole
 * layout — where the map ends, where a panel starts, whether a cat landed on
 * the right pixel — is testable with no display anywhere near it.
 *
 * Colours are packed ARGB in a flat array, addressed row major, exactly the
 * shape `SDL_UpdateTexture` wants. The same reason `PathFinder` works on a
 * flat grid of integers rather than on objects: forty thousand of anything is
 * where the shape of the container starts to cost more than the work.
 */
final class Pixels
{
    /** @var array<int, int> packed ARGB, row major */
    private array $data;

    public function __construct(
        private int $width,
        private int $height,
        int $background = 0xFF000000,
    ) {
        $this->data = array_fill(0, $width * $height, $background);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /**
     * Turn one of php-tui's colours into a packed value.
     *
     * The palette answers in php-tui's vocabulary because the terminal needs
     * it to; the window needs an integer. Sixteen colour answers are given a
     * plausible RGB rather than refused — a window always has true colour, but
     * the palette can still be forced to sixteen with `--colours`.
     */
    public static function pack(Color $colour): int
    {
        if ($colour instanceof RgbColor) {
            return 0xFF000000 | ($colour->r << 16) | ($colour->g << 8) | $colour->b;
        }

        return 0xFF000000 | (self::ANSI[$colour->name] ?? 0xCCCCCC);
    }

    /** @var array<string, int> */
    private const ANSI = [
        'Black' => 0x000000, 'Red' => 0xA02020, 'Green' => 0x208020, 'Yellow' => 0xA07820,
        'Blue' => 0x2050A0, 'Magenta' => 0xA020A0, 'Cyan' => 0x20A0A0, 'Gray' => 0xC0C0C0,
        'DarkGray' => 0x606060, 'LightRed' => 0xFF6060, 'LightGreen' => 0x60D060,
        'LightYellow' => 0xFFD860, 'LightBlue' => 0x60A0FF, 'LightMagenta' => 0xE080FF,
        'LightCyan' => 0x80E0E0, 'White' => 0xFFFFFF,
    ];

    public function set(int $x, int $y, int $colour): void
    {
        if ($x < 0 || $y < 0 || $x >= $this->width || $y >= $this->height) {
            return;
        }

        $this->data[$y * $this->width + $x] = $colour;
    }

    public function at(int $x, int $y): int
    {
        return $this->data[$y * $this->width + $x] ?? 0;
    }

    /**
     * A filled rectangle, clipped to the buffer. Written row by row with
     * array_fill rather than pixel by pixel: the map draws thousands of these
     * a frame and the difference is the whole budget.
     */
    public function rect(int $x, int $y, int $width, int $height, int $colour): void
    {
        $left = max(0, $x);
        $top = max(0, $y);
        $right = min($this->width, $x + $width);
        $bottom = min($this->height, $y + $height);

        if ($right <= $left || $bottom <= $top) {
            return;
        }

        // Written index by index rather than spliced a row at a time: splice
        // reindexes the whole array on every call, so on a panel of a quarter
        // of a million pixels a one pixel border cost more than the entire
        // simulation. It hung the test suite, which is how it was found.
        for ($row = $top; $row < $bottom; $row++) {
            $base = $row * $this->width;

            for ($column = $left; $column < $right; $column++) {
                $this->data[$base + $column] = $colour;
            }
        }
    }

    /** The outline of a rectangle, one pixel thick — a panel's border. */
    public function frame(int $x, int $y, int $width, int $height, int $colour): void
    {
        $this->rect($x, $y, $width, 1, $colour);
        $this->rect($x, $y + $height - 1, $width, 1, $colour);
        $this->rect($x, $y, 1, $height, $colour);
        $this->rect($x + $width - 1, $y, 1, $height, $colour);
    }

    /**
     * The buffer as SDL wants it: packed little endian, row by row.
     *
     * Packed a row at a time because `pack('V*', ...$everything)` spreads the
     * whole buffer onto the stack, and a row of a couple of thousand is where
     * that stops being free.
     */
    public function bytes(): string
    {
        $out = '';

        for ($row = 0; $row < $this->height; $row++) {
            $out .= pack('V' . $this->width, ...array_slice($this->data, $row * $this->width, $this->width));
        }

        return $out;
    }
}
