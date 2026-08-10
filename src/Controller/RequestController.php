<?php

namespace App\Controller;

use App\Entity\Request;
use App\Entity\User;
use App\Form\RequestFormType;
use App\Repository\RequestRepository;
use App\Service\FundsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/requests')]
#[IsGranted('ROLE_USER')]
final class RequestController extends AbstractController
{
    /**
     * Petit widget autonome : affiché dans le menu de toutes les pages admin
     * via {{ render(controller(...)) }}, indépendamment du contrôleur principal.
     */
    #[IsGranted('ROLE_ADMIN')]
    public function pendingBadge(RequestRepository $requestRepository): Response
    {
        return $this->render('request/_pending_badge.html.twig', [
            'pendingCount' => $requestRepository->countPending(),
        ]);
    }

    #[Route('', name: 'app_request_index', methods: ['GET'])]
    public function index(RequestRepository $requestRepository): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        // Demandes validées
        $validatedRequests = $requestRepository->findValidatedByUser($user);
        
        // Demandes en attente de validation
        $pendingRequests = $requestRepository->findPendingByUser($user);

        return $this->render('request/index.html.twig', [
            'validatedRequests' => $validatedRequests,
            'pendingRequests' => $pendingRequests,
        ]);
    }

    #[Route('/new', name: 'app_request_new', methods: ['GET', 'POST'])]
    public function new(HttpRequest $httpRequest, EntityManagerInterface $entityManager, FundsCalculator $fundsCalculator): Response
    {
        $request = new Request();

        $form = $this->createForm(RequestFormType::class, $request);
        $form->handleRequest($httpRequest);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var User $user */
            $user = $this->getUser();

            // Validation : publicAddress requis pour les retraits
            if ($request->getType() === \App\Enum\RequestType::WITHDRAWAL && empty($request->getPublicAddress())) {
                $this->addFlash('error', 'L\'adresse publique est requise pour un retrait.');
                return $this->render('request/new.html.twig', [
                    'request' => $request,
                    'form' => $form,
                ]);
            }

            // Validation : impossible de retirer plus que les fonds disponibles
            if ($request->getType() === \App\Enum\RequestType::WITHDRAWAL) {
                $availableFunds = $fundsCalculator->getAvailableFunds($user);
                $requestedAmount = (float) $request->getAmount();

                if ($requestedAmount > $availableFunds) {
                    $form->get('amount')->addError(new FormError(sprintf(
                        'Fonds insuffisants : vous disposez de $%s disponibles, vous demandez un retrait de $%s.',
                        number_format($availableFunds, 2),
                        number_format($requestedAmount, 2)
                    )));

                    return $this->render('request/new.html.twig', [
                        'request' => $request,
                        'form' => $form,
                    ]);
                }
            }

            $request->setUser($user);
            // La demande n'est PAS validée par défaut
            $request->setIsValidated(false);
            
            // Si c'est un dépôt, on s'assure que publicAddress est null
            if ($request->getType() === \App\Enum\RequestType::DEPOSIT) {
                $request->setPublicAddress(null);
            }

            $entityManager->persist($request);
            $entityManager->flush();

            $this->addFlash('success', 'Demande créée ! Elle sera traitée après validation par un administrateur.');

            return $this->redirectToRoute('app_request_index');
        }

        return $this->render('request/new.html.twig', [
            'request' => $request,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_request_show', methods: ['GET'])]
    public function show(Request $request): Response
    {
        // Vérifier que la demande appartient à l'utilisateur connecté
        $this->denyAccessUnlessGranted('view', $request);

        return $this->render('request/show.html.twig', [
            'request' => $request,
        ]);
    }
}

