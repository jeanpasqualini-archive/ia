<?php

declare(strict_types=1);

use Command\ApplicationCommand;
use Symfony\Component\Console\Application as BaseApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Single command application: running ./console starts the game directly.
 */
class ApplicationConsole extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('cat-ia');

        $this->add(new ApplicationCommand());
        $this->setDefaultCommand('application', true);
    }

    protected function getCommandName(InputInterface $input): ?string
    {
        return 'application';
    }

    /**
     * @return list<Command>
     */
    protected function getDefaultCommands(): array
    {
        return array_values(parent::getDefaultCommands());
    }
}
