<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 19/04/18
 * Time: 07:32
 */

namespace Map\Render\Curse;


use CurseFramework\Window;
use Logger\BufferLogger;

class LogView extends Window
{
    /** @var BufferLogger */
    private $bufferLogger;

    public function setBufferLog(BufferLogger $bufferLogger)
    {
        $this->bufferLogger = $bufferLogger;
    }

    public function draw()
    {
        $logs = $this->bufferLogger->getLogs();

        foreach ($logs as $i => $message) {
            ncurses_wmove($this->getWindow(), $i + 1, 1);
            ncurses_waddstr($this->getWindow(), $message);
        }
    }
}