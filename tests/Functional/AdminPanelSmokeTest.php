<?php

namespace App\Tests\Functional;

use App\Controller\Admin\RequestCrudController;
use App\Controller\Admin\TransactionCrudController;
use App\Controller\Admin\UserCrudController;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recettage : le panneau admin EasyAdmin doit rester accessible après une
 * mise à jour de dépendances (dashboard + CRUD users/transactions/requests).
 */
class AdminPanelSmokeTest extends WebTestCase
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

    private function createAdmin(): User
    {
        $user = (new User())
            ->setEmail('admin-smoke@example.com')
            ->setFirstName('Admin')
            ->setLastName('Smoke')
            ->setRoles(['ROLE_ADMIN']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, 'password123'));

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    public function testAdminDashboardIsReachable(): void
    {
        $this->client->loginUser($this->createAdmin());
        $this->client->request('GET', '/admin');

        $this->assertResponseIsSuccessful();
    }

    public function testUserCrudIndexIsReachable(): void
    {
        $this->client->loginUser($this->createAdmin());

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(UserCrudController::class)
            ->setAction('index')
            ->generateUrl();

        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    public function testTransactionCrudIndexIsReachable(): void
    {
        $this->client->loginUser($this->createAdmin());

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(TransactionCrudController::class)
            ->setAction('index')
            ->generateUrl();

        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }

    public function testRequestCrudIndexIsReachable(): void
    {
        $this->client->loginUser($this->createAdmin());

        $url = static::getContainer()->get(AdminUrlGenerator::class)
            ->setController(RequestCrudController::class)
            ->setAction('index')
            ->generateUrl();

        $this->client->request('GET', $url);

        $this->assertResponseIsSuccessful();
    }
}
