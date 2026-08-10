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
 * Recettage : un utilisateur ne peut pas demander un retrait supérieur
 * à ses fonds disponibles (dépôts validés - retraits validés + P&L clôturé).
 */
class RequestWithdrawalLimitTest extends WebTestCase
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
        $conn->executeStatement('DELETE FROM requests');
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
            ->setRoles(['ROLE_USER']);

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

    public function testUserCannotRequestWithdrawalExceedingAvailableFunds(): void
    {
        $user = $this->createUser('withdraw-limit@example.com');
        $this->createValidatedDeposit($user, '500');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/requests/new');

        $form = $crawler->selectButton('Créer la demande')->form();
        $form['request_form[type]'] = '1'; // Retrait (2e case de l'enum RequestType)
        $form['request_form[cryptoType]'] = '0';
        $form['request_form[amount]'] = '1000'; // dépasse les 500$ déposés
        $form['request_form[publicAddress]'] = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';

        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);

        $conn = $this->em->getConnection();
        $count = $conn->executeQuery('SELECT COUNT(*) FROM requests')->fetchOne();
        $this->assertSame(1, (int) $count); // seul le dépôt initial existe, le retrait a été refusé
    }

    public function testUserCanRequestWithdrawalWithinAvailableFunds(): void
    {
        $user = $this->createUser('withdraw-ok@example.com');
        $this->createValidatedDeposit($user, '1000');

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/requests/new');

        $form = $crawler->selectButton('Créer la demande')->form();
        $form['request_form[type]'] = '1';
        $form['request_form[cryptoType]'] = '0';
        $form['request_form[amount]'] = '500';
        $form['request_form[publicAddress]'] = '1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa';

        $this->client->submit($form);

        $this->assertResponseRedirects('/requests');

        $conn = $this->em->getConnection();
        $count = $conn->executeQuery('SELECT COUNT(*) FROM requests')->fetchOne();
        $this->assertSame(2, (int) $count);
    }
}
