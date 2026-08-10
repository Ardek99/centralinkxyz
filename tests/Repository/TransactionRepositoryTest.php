<?php

namespace App\Tests\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CryptoType;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Recettage : findValidatedAndClosedByUser() ne doit retourner que les
 * transactions validées et clôturées appartenant à l'utilisateur demandé.
 */
class TransactionRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private TransactionRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->repository = static::getContainer()->get(TransactionRepository::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM transactions');
        $conn->executeStatement('DELETE FROM "users"');

        parent::tearDown();
    }

    private function createUser(string $email): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setPassword('hash')
            ->setRoles(['ROLE_USER']);

        $this->em->persist($user);

        return $user;
    }

    private function createTransaction(User $user, ?string $exitPrice, bool $isValidated): Transaction
    {
        $transaction = (new Transaction())
            ->setUser($user)
            ->setCryptoType(CryptoType::BTC)
            ->setAmount('1.0')
            ->setEntryPrice('100')
            ->setExitPrice($exitPrice)
            ->setTransactionDate(new \DateTimeImmutable('-1 day'))
            ->setIsValidated($isValidated);

        $this->em->persist($transaction);

        return $transaction;
    }

    public function testFindValidatedAndClosedByUserReturnsOnlyMatchingTransactions(): void
    {
        $maxime = $this->createUser('maxime@example.com');
        $john = $this->createUser('john@example.com');

        // Transaction de Maxime, validée et clôturée -> doit être retournée
        $closed = $this->createTransaction($maxime, '150', true);

        // Transaction de Maxime, non clôturée (pas d'exitPrice) -> exclue
        $this->createTransaction($maxime, null, true);

        // Transaction de Maxime, non validée -> exclue
        $this->createTransaction($maxime, '150', false);

        // Transaction de John, validée et clôturée -> exclue (mauvais utilisateur)
        $this->createTransaction($john, '150', true);

        $this->em->flush();

        $result = $this->repository->findValidatedAndClosedByUser($maxime);

        $this->assertCount(1, $result);
        $this->assertSame($closed->getId(), $result[0]->getId());
    }
}
