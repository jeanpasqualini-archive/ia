<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 29/12/15
 * Time: 13:01
 */

namespace Logger;


use Psr\Log\AbstractLogger;

class FileLogger extends AbstractLogger {

    protected $file;

    protected $path;

    public function __construct($path)
    {
        $this->path = $path;
        $this->file = new \SplFileObject($this->path, "w+");
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     * @param array $context
     * @return null
     */
    public function log($level, $message, array $context = array())
    {
        $this->file->fwrite("[".date("H:i:s")."] [$level] : ".$message.PHP_EOL);
    }

    public function __sleep()
    {
        return ['path'];
    }

    public function __wakeup()
    {
        $this->file = new \SplFileObject($this->path, "w+");
    }
}