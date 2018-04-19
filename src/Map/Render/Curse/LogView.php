<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 19/04/18
 * Time: 07:32
 */

namespace Map\Render\Curse;


use CurseFramework\Window;

class LogView extends Window
{
    public function draw()
    {
        $lines = 8;

        for ($i = 1; $i <= $lines; $i++) {
            ncurses_wmove($this->getWindow(), $i, 1);
            ncurses_waddstr($this->getWindow(), date("H:i:s"));
        }
    }
}