<?php

namespace App\Controller;

use App\Entity\Transaction;
use App\Form\TransactionExitType;
use App\Form\TransactionType;
use App\Repository\TransactionRepository;
use App\Service\Blockchain\BtcTransactionLookupService;
use App\Service\FundsCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;

#[Route('/transactions')]
#[IsGranted('ROLE_ADMIN')]
final class TransactionController extends AbstractController
{
    public function __construct(
        private FundsCalculator $fundsCalculator,
        private BtcTransactionLookupService $lookupService
    ) {}

    #[Route('', name: 'app_transaction_index', methods: ['GET'])]
    public function index(TransactionRepository $transactionRepository): Response
    {
        // Transactions de tous les clients (toutes actives dès leur création)
        $validatedTransactions = $transactionRepository->findAllValidated();

        // Calcul des stats
        $totalInvested = 0;
        $totalProfitLoss = 0;
        $closedTransactions = 0;
        
        // Préparation des données pour le graphique P&L
        $pnlData = [];
        $cumulativePnl = 0;
        
        // Récupérer les transactions clôturées triées par date
        $closedTransactionsList = array_filter($validatedTransactions, fn($t) => $t->getExitPrice() !== null);
        usort($closedTransactionsList, fn($a, $b) => $a->getTransactionDate() <=> $b->getTransactionDate());

        foreach ($validatedTransactions as $transaction) {
            $entryValue = (float) $transaction->getEntryPrice() * (float) $transaction->getAmount();
            $totalInvested += $entryValue;

            if ($transaction->getExitPrice() !== null) {
                $closedTransactions++;
                $profitLoss = (float) $transaction->getProfitLoss();
                $totalProfitLoss += $profitLoss;
            }
        }
        
        // Construire les données pour le graphique (P&L cumulatif)
        foreach ($closedTransactionsList as $transaction) {
            $profitLoss = (float) $transaction->getProfitLoss();
            $cumulativePnl += $profitLoss;
            
            $pnlData[] = [
                'date' => $transaction->getTransactionDate()->format('Y-m-d'),
                'dateFormatted' => $transaction->getTransactionDate()->format('d/m/Y'),
                'pnl' => $profitLoss,
                'cumulativePnl' => $cumulativePnl,
            ];
        }

        return $this->render('transaction/index.html.twig', [
            'transactions' => $validatedTransactions,
            'totalInvested' => $totalInvested,
            'totalProfitLoss' => $totalProfitLoss,
            'closedTransactions' => $closedTransactions,
            'pnlChartData' => $pnlData,
        ]);
    }

    #[Route('/new', name: 'app_transaction_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $transaction = new Transaction();
        $transaction->setTransactionDate(new \DateTimeImmutable());

        $form = $this->createForm(TransactionType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $usdAmount = (float) $form->get('usdAmount')->getData();
            $availableFunds = $this->fundsCalculator->getAvailableFunds($transaction->getUser());

            if ($usdAmount > $availableFunds) {
                $form->get('usdAmount')->addError(new FormError(sprintf(
                    'Fonds insuffisants : %s dispose de $%s disponibles, vous tentez d\'investir $%s.',
                    $transaction->getUser()->getFullName(),
                    number_format($availableFunds, 2),
                    number_format($usdAmount, 2)
                )));
            } else {
                // L'admin est seul auteur d'une transaction : elle est active dès sa création
                $transaction->setIsValidated(true);
                $transaction->setAmount((string) round($usdAmount / (float) $transaction->getEntryPrice(), 8));

                $entityManager->persist($transaction);
                $entityManager->flush();

                $this->addFlash('success', sprintf(
                    'Transaction créée avec succès pour %s !',
                    $transaction->getUser()->getFullName()
                ));

                return $this->redirectToRoute('app_transaction_index');
            }
        }

        return $this->render('transaction/new.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_transaction_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(Transaction $transaction): Response
    {
        // Vérifier que la transaction appartient à l'utilisateur connecté
        $this->denyAccessUnlessGranted('view', $transaction);

        return $this->render('transaction/show.html.twig', [
            'transaction' => $transaction,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_transaction_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Transaction $transaction, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que la transaction appartient à l'utilisateur connecté
        $this->denyAccessUnlessGranted('edit', $transaction);

        $form = $this->createForm(TransactionType::class, $transaction);
        $form->get('usdAmount')->setData(
            round((float) $transaction->getEntryPrice() * (float) $transaction->getAmount(), 2)
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $usdAmount = (float) $form->get('usdAmount')->getData();

            // On exclut cette transaction de son propre calcul : son montant actuel
            // est déjà compté comme "engagé", on ne veut pas le déduire deux fois.
            $availableFunds = $this->fundsCalculator->getAvailableFunds($transaction->getUser(), excluding: $transaction);

            if ($transaction->getExitPrice() === null && $usdAmount > $availableFunds) {
                $form->get('usdAmount')->addError(new FormError(sprintf(
                    'Fonds insuffisants : %s dispose de $%s disponibles (hors cette position), vous tentez d\'investir $%s.',
                    $transaction->getUser()->getFullName(),
                    number_format($availableFunds, 2),
                    number_format($usdAmount, 2)
                )));
            } else {
                $transaction->setAmount((string) round($usdAmount / (float) $transaction->getEntryPrice(), 8));

                $entityManager->flush();

                $this->addFlash('success', 'Transaction modifiée avec succès !');

                return $this->redirectToRoute('app_transaction_index');
            }
        }

        return $this->render('transaction/edit.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/close', name: 'app_transaction_close', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function close(Request $request, Transaction $transaction, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que la transaction appartient à l'utilisateur connecté
        $this->denyAccessUnlessGranted('edit', $transaction);

        $form = $this->createForm(TransactionExitType::class, $transaction);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $profitLoss = $transaction->getProfitLoss();
            $isProfit = (float) $profitLoss >= 0;

            $this->addFlash(
                $isProfit ? 'success' : 'warning',
                sprintf(
                    'Transaction clôturée ! %s : $%s',
                    $isProfit ? 'Bénéfice' : 'Perte',
                    number_format(abs((float) $profitLoss), 2)
                )
            );

            return $this->redirectToRoute('app_transaction_show', ['id' => $transaction->getId()]);
        }

        return $this->render('transaction/close.html.twig', [
            'transaction' => $transaction,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_transaction_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, Transaction $transaction, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que la transaction appartient à l'utilisateur connecté
        $this->denyAccessUnlessGranted('delete', $transaction);

        if ($this->isCsrfTokenValid('delete' . $transaction->getId(), $request->request->get('_token'))) {
            $entityManager->remove($transaction);
            $entityManager->flush();

            $this->addFlash('success', 'Transaction supprimée.');
        }

        return $this->redirectToRoute('app_transaction_index');
    }

    #[Route('/lookup-blockchain', name: 'app_transaction_lookup_blockchain', methods: ['GET'])]
    public function lookupBlockchain(Request $request): JsonResponse
    {
        $hash = $request->query->get('hash');

        if (!$hash) {
            return new JsonResponse(['error' => 'Pas de hash fourni'], 400);
        }
        try {
            $result = $this->lookupService->lookup($hash);
        } catch (ClientExceptionInterface $e) {
            return new JsonResponse(['error' => 'Transaction introuvable'], 404);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'Erreur lors de la récupération de la transaction'], 503);
        }

        return new JsonResponse([
            'date' => $result['date'] ? $result['date']->format('Y-m-d H:i:s') : null,
            'price' => $result['price'],
            'amount' => $result['amount'],
        ]);
    }
}
