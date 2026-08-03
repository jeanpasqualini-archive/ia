<?php

declare(strict_types=1);

namespace Memory;

use RuntimeException;

class MemoryManager
{
    private FlashMemory $flashMemory;

    public function __construct(private string $id = 'game', private ?string $cacheDir = null)
    {
        $this->flashMemory = new FlashMemory();
        $this->cacheDir ??= __DIR__ . '/../../app/cache';
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getFlashMemory(): FlashMemory
    {
        return $this->flashMemory;
    }

    /**
     * Dump every retained snapshot to disk and return the file written.
     */
    public function persist(): string
    {
        // The original built this path with an undefined constant, so the
        // whole feature raised an Error instead of writing anything.
        $directory = $this->cacheDir . '/' . $this->id;

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('impossible de creer %s', $directory));
        }

        $path = $directory . '/memory.dump';

        file_put_contents($path, serialize($this->getFlashMemory()->all()));

        return $path;
    }

    /**
     * @return list<string>
     */
    public function __sleep(): array
    {
        return ['id', 'cacheDir'];
    }

    public function __wakeup(): void
    {
        $this->flashMemory = new FlashMemory();
    }
}
