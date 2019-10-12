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
use Map\World\WorldContainer;
use Memory\MemoryManager;

class TimeMachineView extends Window
{
    /** @var MemoryManager */
    private $memoryManager;

    public function setMemoryManager(MemoryManager $memoryManager)
    {
        $this->memoryManager = $memoryManager;
    }

    public function draw()
    {
        $flashMemory = $this->memoryManager->getFlashMemory();

        $out = '';

        for ($i = 0; $i <= $flashMemory->getPlaces(); $i++) {
            $out .= $flashMemory->getPositionRead() == $i ? 'o' : 'O';
        }

        ncurses_wmove($this->getWindow(), 1, 1);
        ncurses_waddstr($this->getWindow(), $out);
    }
}