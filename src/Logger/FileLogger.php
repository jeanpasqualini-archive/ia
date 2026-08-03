<?php

declare(strict_types=1);

namespace Logger;

use Psr\Log\AbstractLogger;
use SplFileObject;
use Stringable;

class FileLogger extends AbstractLogger
{
    private ?SplFileObject $file = null;

    public function __construct(private string $path)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->file()->fwrite(
            '[' . date('H:i:s') . "] [$level] : " . $message . ' (' . json_encode($context) . ')' . PHP_EOL
        );
    }

    private function file(): SplFileObject
    {
        if (null === $this->file) {
            $directory = dirname($this->path);

            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }

            $this->file = new SplFileObject($this->path, 'a');
        }

        return $this->file;
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['path'];
    }
}
