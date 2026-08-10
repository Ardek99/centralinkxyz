<?php

namespace App\Tests\Functional;

use App\Controller\Admin\RequestCrudController;
use App\Entity\Request;
use App\Entity\User;
use App\Enum\CryptoType;
use App\Enum\RequestType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recettage : l'admin ne peut pas valider une demande de retrait devenue
 * intenable entre-temps (d'autres retraits déjà validés ont consommé les
 * fonds disponibles depuis la création de la demande).
 */
class RequestValidationFundsTest extends WebTestCase
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

    public function testAdminCannotValidateWithdrawalExceedingCurrentFunds(): void
    {
        $admin = $this->createUser('admin-funds@example.com', ['ROLE_ADMIN']);
        $client_ = $this->createUser('client-funds@example.com', ['ROLE_USER']);

        // Dépôt validé de 1000$
        $deposit = (new Request())
            ->setType(RequestType::DEPOSIT)
            ->setAmount('1000')
            ->setCryptoType(CryptoType::USDT)
            ->setUser($client_)
            ->setIsValidated(true);
        $this->em->persist($deposit);

        // Retrait déjà validé de 800$ (a consommé une partie des fonds)
        $withdrawalA = (new Request())
            ->setType(RequestType::WITHDRAWAL)
            ->setAmount('800')
            ->setCryptoType(CryptoType::USDT)
            ->setUser($client_)
            ->setPublicAddress('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa')
            ->setIsValidated(true);
        $this->em->persist($withdrawalA);

        // Retrait en attente de 700$ : ne reste que 200$ réellement disponibles
        $withdrawalB = (new Request())
            ->setType(RequestType::WITHDRAWAL)
            ->setAmount('700')
            ->setCryptoType(CryptoType::USDT)
            ->setUser($client_)
            ->setPublicAddress('1BvBMSEYstWetqTFn5Au4m4GFg7xJaNVN2')
            ->setIsValidated(false);
        $this->em->persist($withdrawalB);
        $this->em->flush();

        $withdrawalBId = $withdrawalB->getId();

        $this->client->loginUser($admin);

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $url = $urlGenerator
            ->setController(RequestCrudController::class)
            ->setAction('validateRequest')
            ->setEntityId($withdrawalBId)
            ->generateUrl();

        $this->client->request('GET', $url);
        $this->client->followRedirect();

        $this->assertStringContainsString('Impossible de valider', $this->client->getResponse()->getContent());

        $conn = $this->em->getConnection();
        $isValidated = $conn->executeQuery('SELECT is_validated FROM requests WHERE id = ' . $withdrawalBId)->fetchOne();
        $this->assertFalse((bool) $isValidated);
    }

    public function testAdminCanValidateWithdrawalWithinCurrentFunds(): void
    {
        $admin = $this->createUser('admin-funds2@example.com', ['ROLE_ADMIN']);
        $client_ = $this->createUser('client-funds2@example.com', ['ROLE_USER']);

        $deposit = (new Request())
            ->setType(RequestType::DEPOSIT)
            ->setAmount('1000')
            ->setCryptoType(CryptoType::USDT)
            ->setUser($client_)
            ->setIsValidated(true);
        $this->em->persist($deposit);

        $withdrawal = (new Request())
            ->setType(RequestType::WITHDRAWAL)
            ->setAmount('500')
            ->setCryptoType(CryptoType::USDT)
            ->setUser($client_)
            ->setPublicAddress('1A1zP1eP5QGefi2DMPTfTL5SLmv7DivfNa')
            ->setIsValidated(false);
        $this->em->persist($withdrawal);
        $this->em->flush();

        $withdrawalId = $withdrawal->getId();

        $this->client->loginUser($admin);

        $urlGenerator = static::getContainer()->get(AdminUrlGenerator::class);
        $url = $urlGenerator
            ->setController(RequestCrudController::class)
            ->setAction('validateRequest')
            ->setEntityId($withdrawalId)
            ->generateUrl();

        $this->client->request('GET', $url);

        $conn = $this->em->getConnection();
        $isValidated = $conn->executeQuery('SELECT is_validated FROM requests WHERE id = ' . $withdrawalId)->fetchOne();
        $this->assertTrue((bool) $isValidated);
    }
}
