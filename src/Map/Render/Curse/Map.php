<?php
/**
 * Created by PhpStorm.
 * User: darkilliant
 * Date: 27/12/15
 * Time: 17:48
 */
namespace Map\Render\Curse;

use CurseFramework\Window;
use Map\Builder\MapBuilder;

class Map extends Window {

    public function __construct($rows, $cols, $y, $x)
    {
        parent::__construct($rows, $cols, $y, $x);

        $mappingColor = $this->getMappingColor();

        if(ncurses_has_colors()){

            ncurses_start_color();

            ncurses_init_pair($mappingColor[MapBuilder::HERBE], NCURSES_COLOR_GREEN, NCURSES_COLOR_GREEN);

            ncurses_init_pair($mappingColor[MapBuilder::ARBRE], NCURSES_COLOR_BLACK, NCURSES_COLOR_GREEN);

            ncurses_init_pair($mappingColor[MapBuilder::EAU], NCURSES_COLOR_WHITE, NCURSES_COLOR_CYAN);

            ncurses_init_pair($mappingColor["P"], NCURSES_COLOR_WHITE, NCURSES_COLOR_BLACK);

            ncurses_init_pair($mappingColor[MapBuilder::FLEUR], NCURSES_COLOR_RED, NCURSES_COLOR_GREEN);
        }
    }

    public function draw($map)
    {
        foreach($map as $i => $line)
        {
            foreach($line as $j => $item)
            {

                //$lineStr .= $this->format($item);
                ncurses_wmove($this->getWindow(), $i + 1, $j + 1);
                ncurses_wcolor_set($this->getWindow(), $this->formatColor($item));
                ncurses_waddstr($this->getWindow(), $this->format($item));
            }

            //ncurses_color_set(62);

           // ncurses_mvwaddstr($this->getWindow(), $i+1, 1, $lineStr);
        }
    }

    public function format($item)
    {
        $mapping = array(
            MapBuilder::HERBE => 'H',
            MapBuilder::FLEUR => 'F',
            MapBuilder::ARBRE => 'A',
            MapBuilder::EAU => 'E',
            "P" => 'P',
        );

        return (isset($mapping[$item]) ? $mapping[$item] : $item);
    }

    public function getMappingColor()
    {
        return array(
            MapBuilder::HERBE => 1,
            MapBuilder::ARBRE => 2,
            MapBuilder::EAU => 3,
            "P" => 4,
            MapBuilder::FLEUR => 6
        );
    }

    public function formatColor($item)
    {
        $mappingColor = $this->getMappingColor();

        $mapping = array(
            MapBuilder::HERBE => $mappingColor[MapBuilder::HERBE],
            MapBuilder::FLEUR => $mappingColor[MapBuilder::FLEUR],
            MapBuilder::ARBRE => $mappingColor[MapBuilder::ARBRE],
            MapBuilder::EAU => $mappingColor[MapBuilder::EAU],
            "P" => $mappingColor["P"],
        );

        return (isset($mapping[$item]) ? $mapping[$item] : $item);
    }
}