<?php

declare(strict_types=1);

namespace Command;

use GameRunner\GameRunner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(name: 'application', description: 'Lance la simulation dans le terminal')]
class ApplicationCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('play', null, InputOption::VALUE_NONE, 'demarre en lecture au lieu de demarrer en pause')
            ->addOption('speed', null, InputOption::VALUE_REQUIRED, 'vitesse de depart (0.25 a 1000)')
            ->addOption('map', null, InputOption::VALUE_REQUIRED, 'charge une map depuis un fichier')
            ->addOption('seed', null, InputOption::VALUE_REQUIRED, 'graine du terrain, pour rejouer la meme carte')
            ->addOption('mute', null, InputOption::VALUE_NONE, 'demarre sans son (la touche m le fait aussi)')
            ->addOption('no-mouse', null, InputOption::VALUE_NONE, 'desactive la souris, et rend la selection de texte au terminal')
            ->addOption('flash-name', null, InputOption::VALUE_REQUIRED, 'nom du dump memoire', 'game')
            ->addOption('log', null, InputOption::VALUE_REQUIRED, 'fichier de log', '/tmp/log/dev.log');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runner = new GameRunner(logFile: (string) $input->getOption('log'));

        try {
            $runner->configure($input->getOptions());

            return $runner->execute();
        } catch (Throwable $e) {
            // The renderer restores the terminal in its own finally block, so
            // the error is readable on the normal screen.
            $style = new SymfonyStyle($input, $output);
            $style->error($e->getMessage());
            $style->comment($e->getTraceAsString());

            return Command::FAILURE;
        }
    }
}
