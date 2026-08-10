<?php

namespace App\Tests\Functional;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CryptoType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recettage : un utilisateur ne doit voir sur son tableau de bord que SES propres
 * transactions clôturées, et jamais celles attribuées par l'admin à un autre utilisateur.
 */
class TransactionVisibilityTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('DELETE FROM transactions');
        $conn->executeStatement('DELETE FROM "users"');

        parent::tearDown();
    }

    private function createUser(string $email, string $firstName, string $lastName): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles(['ROLE_USER']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->em->persist($user);

        return $user;
    }

    private function createClosedTransaction(User $user, string $amount, string $entryPrice, string $exitPrice): Transaction
    {
        $transaction = (new Transaction())
            ->setUser($user)
            ->setCryptoType(CryptoType::BTC)
            ->setAmount($amount)
            ->setEntryPrice($entryPrice)
            ->setExitPrice($exitPrice)
            ->setTransactionDate(new \DateTimeImmutable('-1 day'))
            ->setIsValidated(true);

        $this->em->persist($transaction);

        return $transaction;
    }

    public function testUserDoesNotSeeOtherUsersTransactionsOnDashboard(): void
    {
        $client = $this->client;

        $maxime = $this->createUser('maxime@example.com', 'Maxime', 'Dupont');
        $john = $this->createUser('john@example.com', 'John', 'Smith');

        // Transaction de Maxime (doit être visible par Maxime)
        $this->createClosedTransaction($maxime, '0.5', '20000', '25000');

        // Transaction de John ajoutée par l'admin (ne doit PAS être visible par Maxime)
        $this->createClosedTransaction($john, '1.2', '1500', '1800');

        $this->em->flush();

        $client->loginUser($maxime);
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        $content = $crawler->filter('.history-column')->eq(1)->html();

        $this->assertStringContainsString('Maxime Dupont', $content);
        $this->assertStringNotContainsString('John Smith', $content);
    }

    public function testUserSeesOnlyHisOwnTransactionCount(): void
    {
        $client = $this->client;

        $maxime = $this->createUser('maxime2@example.com', 'Maxime', 'Dupont');
        $john = $this->createUser('john2@example.com', 'John', 'Smith');

        $this->createClosedTransaction($maxime, '0.5', '20000', '25000');
        $this->createClosedTransaction($john, '1.2', '1500', '1800');
        $this->createClosedTransaction($john, '2.0', '1500', '1700');

        $this->em->flush();

        $client->loginUser($maxime);
        $crawler = $client->request('GET', '/');

        $this->assertResponseIsSuccessful();

        // Maxime n'a qu'UNE transaction, peu importe le nombre total en base
        $this->assertSame('1', trim($crawler->filter('.column-count')->eq(1)->text()));
    }

    public function testAdminSeesTransactionsOfAllClientsOnTransactionsPage(): void
    {
        $client = $this->client;

        $admin = $this->createUser('admin3@example.com', 'Admin', 'User');
        $admin->setRoles(['ROLE_ADMIN']);
        $maxime = $this->createUser('maxime3@example.com', 'Maxime', 'Dupont');
        $john = $this->createUser('john3@example.com', 'John', 'Smith');

        // Transactions appartenant à deux clients différents, pas à l'admin
        $this->createClosedTransaction($maxime, '0.5', '20000', '25000');
        $this->createClosedTransaction($john, '1.2', '1500', '1800');

        $this->em->flush();

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/transactions');

        $this->assertResponseIsSuccessful();

        $content = $crawler->filter('body')->html();

        $this->assertStringContainsString('Maxime Dupont', $content);
        $this->assertStringContainsString('John Smith', $content);
    }
}
