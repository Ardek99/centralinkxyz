<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Recettage : vérifie le routage selon le statut de connexion et le rôle.
 */
class AccessControlTest extends WebTestCase
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

    public function testAnonymousVisitorIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/login');
    }

    public function testRegularUserCanAccessHomeDashboard(): void
    {
        $user = $this->createUser('user@example.com', ['ROLE_USER']);

        $this->client->loginUser($user);
        $this->client->request('GET', '/');

        $this->assertResponseIsSuccessful();
    }

    public function testAdminIsRedirectedToTransactions(): void
    {
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/');

        $this->assertResponseRedirects('/transactions');
    }
}
