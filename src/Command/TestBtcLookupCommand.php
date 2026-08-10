<?php

namespace App\Command;

use App\Service\Blockchain\BtcTransactionLookupService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:test-btc-lookup')]
class TestBtcLookupCommand extends Command
{
    public function __construct(
        private BtcTransactionLookupService $lookupService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('hash', InputArgument::REQUIRED, 'Le hash de la transaction BTC');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $hash = $input->getArgument('hash');

        $result = $this->lookupService->lookup($hash);

        // affiche le résultat dans le terminal
        $output->writeln('Date : ' . $result['date']?->format('Y-m-d H:i:s'));
        $output->writeln('Montant : ' . $result['amount']);

        return Command::SUCCESS;
    }
}
