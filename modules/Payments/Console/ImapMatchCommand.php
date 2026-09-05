<?php

declare(strict_types=1);

namespace Modules\Payments\Console;

use Modules\Payments\Application\ImapBankMatcher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'payments:imap-match', description: 'Spárovat bankovní IMAP příchozí s payment intents (VS)')]
final class ImapMatchCommand extends Command
{
    public function __construct(private readonly ImapBankMatcher $matcher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('limit', null, InputOption::VALUE_OPTIONAL, 'Max. počet zpráv', '50');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = max(1, (int) $input->getOption('limit'));
        $result = $this->matcher->run($limit);
        $output->writeln(sprintf(
            'IMAP: %s — scanned %d, matched %d',
            $result['message'],
            $result['scanned'],
            $result['matched'],
        ));

        return $result['message'] === 'ok' || $result['message'] === 'IMAP not configured'
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
