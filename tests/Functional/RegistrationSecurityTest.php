<?php

namespace App\Tests\Functional;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Recettage : /register ne doit ni confirmer qu'un email est déjà utilisé
 * (énumération de comptes), ni pouvoir être spammé sans limite.
 */
class RegistrationSecurityTest extends WebTestCase
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

    private function submitRegistration(string $email): void
    {
        $crawler = $this->client->request('GET', '/register');
        $form = $crawler->filter('form')->form();
        $form['registration_form[firstName]'] = 'Test';
        $form['registration_form[lastName]'] = 'User';
        $form['registration_form[email]'] = $email;
        $form['registration_form[plainPassword][first]'] = 'Password123';
        $form['registration_form[plainPassword][second]'] = 'Password123';

        $this->client->submit($form);
    }

    public function testDuplicateEmailDoesNotRevealAccountExists(): void
    {
        $existing = (new User())
            ->setEmail('already-taken@example.com')
            ->setFirstName('Existing')
            ->setLastName('User')
            ->setRoles(['ROLE_USER']);

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        $existing->setPassword($hasher->hashPassword($existing, 'password123'));
        $this->em->persist($existing);
        $this->em->flush();

        $this->submitRegistration('already-taken@example.com');

        $content = $this->client->getResponse()->getContent();
        $this->assertStringNotContainsString('existe déjà', $content);
    }

    public function testRegistrationSucceedsUnderTheLimit(): void
    {
        $this->submitRegistration('single-user@example.com');

        $this->assertResponseRedirects('/login');
    }

    public function testRegistrationIsBlockedOnceLimitIsExhausted(): void
    {
        /** @var RateLimiterFactory $limiter */
        $limiter = static::getContainer()->get('limiter.registration');

        // On épuise directement le quota (5, voir rate_limiter.yaml) via le
        // service : le passer par 5 vraies requêtes HTTP ne marche pas ici,
        // le kernel de test réinitialise le cache en mémoire après chaque
        // requête (ResetListener), donc l'état ne s'accumulerait jamais.
        for ($i = 0; $i < 5; $i++) {
            $limiter->create('127.0.0.1')->consume(1);
        }

        // On poste directement (sans GET préalable pour récupérer un token
        // CSRF) : un GET déclencherait lui aussi le reset ci-dessus avant
        // même d'arriver à cette 6e tentative. Le rate limiter se déclenche
        // dès que la requête est soumise, avant toute validation CSRF.
        $this->client->request('POST', '/register', [
            'registration_form' => [
                'firstName' => 'Test',
                'lastName' => 'User',
                'email' => 'should-be-blocked@example.com',
                'plainPassword' => ['first' => 'Password123', 'second' => 'Password123'],
            ],
        ]);

        // Pas de redirection vers /login : l'inscription n'a pas abouti.
        $this->assertNotSame(302, $this->client->getResponse()->getStatusCode());
        $this->assertStringNotContainsString('Compte créé avec succès', $this->client->getResponse()->getContent());

        $conn = $this->em->getConnection();
        $count = (int) $conn->executeQuery('SELECT COUNT(*) FROM "users"')->fetchOne();
        $this->assertSame(0, $count);
    }
}
