<?php

declare(strict_types=1);

namespace Logger;

use Psr\Log\AbstractLogger;
use Stringable;

class BufferLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $logs = [];

    public function __construct(private int $limit = 50)
    {
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (count($this->logs) >= $this->limit) {
            $this->logs = array_slice($this->logs, 1);
        }

        $this->logs[] = '[' . date('H:i:s') . "] [$level] : " . $message;
    }

    /**
     * @return list<string>
     */
    public function getLogs(): array
    {
        return $this->logs;
    }
}
