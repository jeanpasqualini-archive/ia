<?php
/**
 * Created by PhpStorm.
 * User: jpasqualini
 * Date: 19/04/18
 * Time: 10:10
 */

namespace Logger;


use Psr\Log\AbstractLogger;

class BufferLogger extends AbstractLogger
{
    private $logs = [];

    public function log($level, $message, array $context = array())
    {
        if (count($this->logs) > 7) {
            $this->logs = array_slice($this->logs, 1);
        }

        $this->logs[] = "[".date("H:i:s")."] [$level] : ".$message;
    }

    public function getLogs() {
        return $this->logs;
    }
}