<?php

declare(strict_types=1);

namespace Map\Provider;

use Map\Builder\MapBuilder;
use SplFileObject;

/**
 * Reads a hand-drawn unicode map (see app/map/terre.txt) and converts each
 * glyph back to its tile constant.
 */
class FileMapProvider implements MapProviderInterface
{
    private const GLYPHS = [
        '░' => MapBuilder::HERBE,
        '✿' => MapBuilder::FLEUR,
        '↟' => MapBuilder::ARBRE,
        '∼' => MapBuilder::EAU,
    ];

    private SplFileObject $file;

    public function __construct(string $file)
    {
        $this->file = new SplFileObject($file, 'r');
    }

    /**
     * @return list<string>
     */
    public function getMap(): array
    {
        $lines = [];

        while (!$this->file->eof()) {
            $line = rtrim((string) $this->file->fgets(), "\r\n");

            if ('' === $line) {
                continue;
            }

            $lines[] = implode('', array_map(
                $this->format(...),
                mb_str_split($line)
            ));
        }

        return $lines;
    }

    public function format(string $glyph): string
    {
        return self::GLYPHS[$glyph] ?? MapBuilder::HERBE;
    }
}
