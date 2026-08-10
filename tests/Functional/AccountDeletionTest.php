<?php

namespace App\Tests\Functional;

use App\Entity\Request;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CryptoType;
use App\Enum\RequestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recettage : droit à l'effacement (RGPD) — un utilisateur peut supprimer
 * définitivement son compte et l'ensemble de ses données associées.
 */
class AccountDeletionTest extends WebTestCase
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
        $conn->executeStatement('DELETE FROM requests');
        $conn->executeStatement('DELETE FROM "users"');

        parent::tearDown();
    }

    private function createUser(string $email, string $plainPassword): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles(['ROLE_USER']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testUserCanDeleteOwnAccountAndAssociatedData(): void
    {
        $user = $this->createUser('erase-me@example.com', 'password123');

        $transaction = (new Transaction())
            ->setCryptoType(CryptoType::BTC)
            ->setEntryPrice('60000')
            ->setAmount('0.01')
            ->setTransactionDate(new \DateTimeImmutable())
            ->setUser($user)
            ->setIsValidated(true);
        $this->em->persist($transaction);

        $request = (new Request())
            ->setType(RequestType::DEPOSIT)
            ->setAmount('500')
            ->setCryptoType(CryptoType::USDT)
            ->setUser($user)
            ->setIsValidated(true);
        $this->em->persist($request);
        $this->em->flush();

        $userId = $user->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        $form = $crawler->filter('#deleteAccountForm')->form();
        $form['password'] = 'password123';
        $this->client->submit($form);

        $this->assertResponseRedirects('/login');

        $conn = $this->em->getConnection();
        $this->assertSame(0, (int) $conn->executeQuery('SELECT COUNT(*) FROM "users" WHERE id = ' . $userId)->fetchOne());
        $this->assertSame(0, (int) $conn->executeQuery('SELECT COUNT(*) FROM transactions WHERE user_id = ' . $userId)->fetchOne());
        $this->assertSame(0, (int) $conn->executeQuery('SELECT COUNT(*) FROM requests WHERE user_id = ' . $userId)->fetchOne());
    }

    public function testAccountDeletionFailsWithWrongPassword(): void
    {
        $user = $this->createUser('keep-me@example.com', 'password123');
        $userId = $user->getId();

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/');

        $form = $crawler->filter('#deleteAccountForm')->form();
        $form['password'] = 'wrong-password';
        $this->client->submit($form);

        $this->assertResponseRedirects('/');

        $conn = $this->em->getConnection();
        $this->assertSame(1, (int) $conn->executeQuery('SELECT COUNT(*) FROM "users" WHERE id = ' . $userId)->fetchOne());
    }
}
