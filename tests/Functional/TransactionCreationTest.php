<?php

namespace App\Tests\Functional;

use App\Entity\Request;
use App\Entity\User;
use App\Enum\CryptoType;
use App\Enum\RequestType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recettage : l'admin saisit un montant en dollars investi, l'application
 * doit calculer la quantité de crypto correspondante (montant / prix d'entrée),
 * et refuser la transaction si le client n'a pas assez de fonds disponibles.
 */
class TransactionCreationTest extends WebTestCase
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

    private function createUser(string $email, array $roles): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName('Test')
            ->setLastName('User')
            ->setRoles($roles);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function createValidatedDeposit(User $user, string $amount): void
    {
        $deposit = (new Request())
            ->setType(RequestType::DEPOSIT)
            ->setAmount($amount)
            ->setCryptoType(CryptoType::USDT)
            ->setUser($user)
            ->setIsValidated(true);

        $this->em->persist($deposit);
        $this->em->flush();
    }

    public function testAdminCreatesTransactionFromUsdAmountWhenFundsAreSufficient(): void
    {
        $admin = $this->createUser('admin4@example.com', ['ROLE_ADMIN']);
        $client_ = $this->createUser('client4@example.com', ['ROLE_USER']);
        $this->createValidatedDeposit($client_, '1500');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/transactions/new');

        $form = $crawler->selectButton('Créer la transaction')->form();
        $form['transaction[user]'] = (string) $client_->getId();
        $form['transaction[cryptoType]'] = '0'; // BTC (premier choix du ChoiceType)
        $form['transaction[entryPrice]'] = '60000';
        $form['transaction[usdAmount]'] = '1000';

        $this->client->submit($form);

        $this->assertResponseRedirects('/transactions');

        $conn = $this->em->getConnection();
        $amount = $conn->executeQuery('SELECT amount FROM transactions ORDER BY id DESC LIMIT 1')->fetchOne();

        // 1000$ investis à 60000$ l'unité => 0.01666666... BTC
        $this->assertEqualsWithDelta(1000 / 60000, (float) $amount, 0.0000001);
    }

    public function testAdminCannotCreateTransactionExceedingClientFunds(): void
    {
        $admin = $this->createUser('admin5@example.com', ['ROLE_ADMIN']);
        $client_ = $this->createUser('client5@example.com', ['ROLE_USER']);
        $this->createValidatedDeposit($client_, '500');

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/transactions/new');

        $form = $crawler->selectButton('Créer la transaction')->form();
        $form['transaction[user]'] = (string) $client_->getId();
        $form['transaction[cryptoType]'] = '0';
        $form['transaction[entryPrice]'] = '60000';
        $form['transaction[usdAmount]'] = '1000'; // dépasse les 500$ déposés

        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);

        $conn = $this->em->getConnection();
        $count = $conn->executeQuery('SELECT COUNT(*) FROM transactions')->fetchOne();
        $this->assertSame(0, (int) $count);
    }
}
