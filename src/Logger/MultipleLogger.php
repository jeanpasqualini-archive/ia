<?php

declare(strict_types=1);

namespace Logger;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;

class MultipleLogger extends AbstractLogger
{
    /** @var list<LoggerInterface> */
    private array $loggerCollection = [];

    public function addLogger(LoggerInterface $logger): void
    {
        $this->loggerCollection[] = $logger;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        foreach ($this->loggerCollection as $logger) {
            $logger->log($level, $message, $context);
        }
    }
}
