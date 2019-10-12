<?php
/**
 * Created by PhpStorm.
 * User: Freelance
 * Date: 24/12/2015
 * Time: 10:24
 */

namespace Command;


use GameRunner\GameRunner;
use Map\Render\NCurseRender;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ApplicationCommand extends Command
{
    const UPDATE_NORMAL = 1;
    const UPDATE_NONE = 2;
    const UPDATE_SLOW = 3;

    private $timeMachineMode = false;
    private $updateMode = self::UPDATE_NONE;

    public function configure()
    {
        $this->setName("application");
        $this->addOption("curse", null, InputOption::VALUE_NONE, "active curse");
        $this->addOption("step-by-step", null, InputOption::VALUE_NONE, "step by step");
        $this->addOption("size", null, InputOption::VALUE_REQUIRED, "taille", exec('tput lines')."x".exec('tput cols'));
        $this->addOption("load-dump", null, InputOption::VALUE_REQUIRED, "memory load dump");
        $this->addOption("map", null, InputOption::VALUE_REQUIRED, "file");
        $this->addOption('flash-name', null, InputOption::VALUE_REQUIRED, 'flash-name', 'game');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        mb_internal_encoding('UTF-8');


        try {
            $runner = new GameRunner();
            $runner->configure($input->getOptions());
            $runner->execute();
        }
        catch(\Throwable $e)
        {
            if(extension_loaded("ncurses")) {
                ncurses_end();
            }
            $symfonyStyle = new SymfonyStyle($input, $output);
            $symfonyStyle->error($e->getMessage());
            $symfonyStyle->comment($e->getTraceAsString());

            return;
        }
    }
}
