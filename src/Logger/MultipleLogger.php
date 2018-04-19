<?php
/**
 * Created by PhpStorm.
 * User: jpasqualini
 * Date: 19/04/18
 * Time: 10:05
 */

namespace Logger;


use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

class MultipleLogger extends AbstractLogger
{
    private $loggerCollection = [];

    public function addLogger(LoggerInterface $logger)
    {
        $this->loggerCollection[] = $logger;
    }

    public function log($level, $message, array $context = array())
    {
        foreach ($this->loggerCollection as $logger) {
            $logger->log($level, $message, $context);
        }
    }
}