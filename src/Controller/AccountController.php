<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RequestRepository;
use App\Repository\TransactionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/account')]
#[IsGranted('ROLE_USER')]
final class AccountController extends AbstractController
{
    #[Route('/delete', name: 'app_account_delete', methods: ['POST'])]
    public function delete(
        Request $request,
        EntityManagerInterface $entityManager,
        TransactionRepository $transactionRepository,
        RequestRepository $requestRepository,
        UserPasswordHasherInterface $passwordHasher,
        Security $security,
    ): Response {
        if (!$this->isCsrfTokenValid('delete-account', $request->request->get('_token'))) {
            $this->addFlash('error', 'Jeton de sécurité invalide.');

            return $this->redirectToRoute('app_home');
        }

        /** @var User $user */
        $user = $this->getUser();

        if (!$passwordHasher->isPasswordValid($user, (string) $request->request->get('password'))) {
            $this->addFlash('error', 'Mot de passe incorrect, le compte n\'a pas été supprimé.');

            return $this->redirectToRoute('app_home');
        }

        foreach ($transactionRepository->findByUser($user) as $transaction) {
            $entityManager->remove($transaction);
        }
        foreach ($requestRepository->findByUser($user) as $userRequest) {
            $entityManager->remove($userRequest);
        }
        $entityManager->remove($user);
        $entityManager->flush();

        $security->logout(false);

        $this->addFlash('success', 'Votre compte et l\'ensemble de vos données ont été supprimés définitivement.');

        return $this->redirectToRoute('app_login');
    }
}
