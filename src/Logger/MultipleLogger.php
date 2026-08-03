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

    private bool $muted = false;

    public function addLogger(LoggerInterface $logger): void
    {
        $this->loggerCollection[] = $logger;
    }

    /**
     * Silence every sink.
     *
     * At high speed the simulation runs thousands of ticks per frame, each of
     * them logging several lines. Writing them all would cost more than the
     * simulation itself and bury the journal, so the loop mutes everything but
     * the last tick of a batch: the log keeps the readable pace of the
     * display rather than that of the clock.
     */
    public function mute(bool $muted = true): void
    {
        $this->muted = $muted;
    }

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if ($this->muted) {
            return;
        }

        foreach ($this->loggerCollection as $logger) {
            $logger->log($level, $message, $context);
        }
    }
}
