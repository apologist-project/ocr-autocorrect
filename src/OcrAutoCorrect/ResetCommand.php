<?php

namespace OcrAutoCorrect;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command as BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'reset',
    description: 'Resets the model.',
)]
class ResetCommand extends Command
{

    /**
     * @param InputInterface $in
     * @param OutputInterface $out
     * @return int
     */
    protected function execute(InputInterface $in, OutputInterface $out): int
    {
        $this->setIO($in, $out);
        if ($this->out->confirm('Are you sure you want to reset all training? This cannot be undone.', false)) {
            unlink($this->in->getOption('db-file'));
            $this->initDb();
            $this->out->success('Model successfully reset.');
        }
        return BaseCommand::SUCCESS;
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->addOption('db-file', 'd', InputOption::VALUE_OPTIONAL, 'SQLite database file', './corrections.db');
    }

}