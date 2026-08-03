<?php

declare(strict_types=1);

namespace Logger;

use Psr\Log\AbstractLogger;
use SplFileObject;
use Stringable;
use Throwable;

class FileLogger extends AbstractLogger
{
    private ?SplFileObject $file = null;

    private bool $unavailable = false;

    public function __construct(private string $path)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->file()?->fwrite(
            '[' . date('H:i:s') . "] [$level] : " . $message . ' (' . json_encode($context) . ')' . PHP_EOL
        );
    }

    /**
     * Null once opening has failed. Losing the journal is a nuisance; killing
     * the game over it is not acceptable — and a bind mount going stale under
     * Docker is enough to make the path unopenable mid-run.
     */
    private function file(): ?SplFileObject
    {
        if ($this->unavailable) {
            return null;
        }

        if (null === $this->file) {
            try {
                $directory = dirname($this->path);

                if (!is_dir($directory)) {
                    mkdir($directory, 0o777, true);
                }

                $this->file = new SplFileObject($this->path, 'a');
            } catch (Throwable) {
                $this->unavailable = true;

                return null;
            }
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
