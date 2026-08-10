<?php

namespace App\Command;

use App\Entity\Request;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CryptoType;
use App\Enum\RequestType;
use App\Repository\RequestRepository;
use App\Repository\TransactionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:seed-demo',
    description: 'Seed a demo user with sample transactions and requests for presentation',
)]
class SeedDemoDataCommand extends Command
{
    public function __construct(
        private UserRepository $userRepository,
        private TransactionRepository $transactionRepository,
        private RequestRepository $requestRepository,
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $email = 'marie.dupont@centralink.com';
        $plainPassword = 'Demo1234!';

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (!$user) {
            $user = new User();
            $user->setEmail($email);
            $user->setFirstName('Marie');
            $user->setLastName('Dupont');
            $user->setRoles(['ROLE_USER']);
            $this->entityManager->persist($user);
        } else {
            // Rejouable : on repart d'un historique propre pour ne pas dupliquer
            // les transactions/demandes à chaque exécution de la commande.
            foreach ($this->transactionRepository->findByUser($user) as $transaction) {
                $this->entityManager->remove($transaction);
            }
            foreach ($this->requestRepository->findByUser($user) as $request) {
                $this->entityManager->remove($request);
            }
            $this->entityManager->flush();
        }
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->entityManager->flush();

        $now = new \DateTimeImmutable();

        // Transactions déjà validées et clôturées (historique + graphique P&L côté user)
        // Montants choisis pour rester cohérents avec les dépôts validés plus bas :
        // total investi cumulé (5 660 $) < total déposé (7 000 $).
        $closedTransactions = [
            ['btc', '60000', '63000', '0.04', -10],
            ['eth', '3200', '3000', '0.5', -7],
            ['usdc', '1', '1.02', '500', -5],
            ['btc', '58000', '61500', '0.02', -2],
        ];
        foreach ($closedTransactions as [$crypto, $entry, $exit, $amount, $daysAgo]) {
            $t = new Transaction();
            $t->setCryptoType(CryptoType::from($crypto));
            $t->setEntryPrice($entry);
            $t->setExitPrice($exit);
            $t->setAmount($amount);
            $t->setTransactionDate($now->modify("{$daysAgo} days"));
            $t->setUser($user);
            $t->setIsValidated(true);
            $this->entityManager->persist($t);
        }

        // Transactions en attente de validation (à valider en direct côté admin)
        $pendingTransactions = [
            ['eth', '3100', '0.5'],
            ['usdt', '1', '1000'],
        ];
        foreach ($pendingTransactions as [$crypto, $entry, $amount]) {
            $t = new Transaction();
            $t->setCryptoType(CryptoType::from($crypto));
            $t->setEntryPrice($entry);
            $t->setAmount($amount);
            $t->setTransactionDate($now);
            $t->setUser($user);
            $t->setIsValidated(false);
            $this->entityManager->persist($t);
        }

        // Demandes de dépôt/retrait déjà validées (historique côté user)
        $validatedRequests = [
            [RequestType::DEPOSIT, '5000', 'btc'],
            [RequestType::WITHDRAWAL, '1000', 'btc'],
            [RequestType::DEPOSIT, '2000', 'eth'],
        ];
        foreach ($validatedRequests as [$type, $amount, $crypto]) {
            $r = new Request();
            $r->setType($type);
            $r->setAmount($amount);
            $r->setCryptoType(CryptoType::from($crypto));
            $r->setUser($user);
            $r->setIsValidated(true);
            $this->entityManager->persist($r);
        }

        // Demande en attente de validation (à valider en direct côté admin)
        $pendingRequest = new Request();
        $pendingRequest->setType(RequestType::DEPOSIT);
        $pendingRequest->setAmount('1500');
        $pendingRequest->setCryptoType(CryptoType::USDT);
        $pendingRequest->setUser($user);
        $pendingRequest->setIsValidated(false);
        $this->entityManager->persist($pendingRequest);

        $this->entityManager->flush();

        $io->success(sprintf(
            "Utilisateur de démo créé : %s / %s\n4 transactions clôturées + 2 en attente, 3 demandes validées + 1 en attente.",
            $email,
            $plainPassword
        ));

        return Command::SUCCESS;
    }
}
