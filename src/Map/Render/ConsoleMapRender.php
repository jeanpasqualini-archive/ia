<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 10:35
 */

namespace Map\Render;

use Command\NCursesOutput;
use Map\Builder\MapBuilder;
use Map\Map;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class ConsoleMapRender implements MapRenderInterface
{
    protected $standardOutput;

    protected $output;

    protected $columns;

    public function __construct(OutputInterface $output, $buffer = false)
    {
        $this->columns = exec('tput cols');

        $this->standardOutput = ($buffer) ? $output : null;

        $this->output = ($buffer) ? new BufferedOutput() : $output;
    }

    public function setLogger(LoggerInterface $logger)
    {
    }

    public function getSize()
    {
        return array(
            "x" => exec('tput lines'),
            "y" => exec('tput cols')
        );
    }

    public function render($map)
    {
        foreach($map as $line)
        {
            foreach($line as $item)
            {
                $this->output->write($this->format($item));
            }

            $this->output->writeln("");
        }

        $this->flushOutput();
    }

    protected function flushOutput()
    {
        if($this->standardOutput !== null) $this->standardOutput->write($this->output->fetch());
    }

    public function clear($map)
    {
        $this->output->write(str_repeat("\x08", count($map) * $this->columns));

        $this->flushOutput();
    }

    public function format($item)
    {
        $mapping = array(
            MapBuilder::HERBE => '<fg=green;bg=green>✿</>',
            MapBuilder::FLEUR => '<fg=red;bg=green>✿</>',
            MapBuilder::ARBRE => '<fg=black;bg=green>↟</>',
            MapBuilder::EAU => '<fg=white;bg=cyan>∼</>',
            "P" => '<fg=white;bg=green>☺</>',
        );

        return (isset($mapping[$item]) ? $mapping[$item] : $item);
    }

}