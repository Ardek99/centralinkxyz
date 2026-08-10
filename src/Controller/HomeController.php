<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\RequestRepository;
use App\Repository\TransactionRepository;
use App\Service\FundsCalculator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(
        private TransactionRepository $transactionRepository,
        private RequestRepository $requestRepository,
        private FundsCalculator $fundsCalculator,
    ) {}

    #[Route('/', name: 'app_home')]
    public function index(): Response
    {
        // Si l'utilisateur n'est pas connecté, rediriger vers la connexion
        if (!$this->getUser()) {
            return $this->redirectToRoute('app_login');
        }

        /** @var User $user */
        $user = $this->getUser();

        // Si c'est un admin, rediriger vers les transactions
        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('app_transaction_index');
        }

        // Pour les utilisateurs normaux : afficher l'historique
        // Transactions validées ET clôturées de l'utilisateur connecté uniquement
        $closedTransactions = $this->transactionRepository->findValidatedAndClosedByUser($user);
        // Seulement les requests de l'utilisateur connecté
        $validatedRequests = $this->requestRepository->findValidatedByUser($user);
        
        // Calcul des stats utilisateur
        $totalDeposits = 0.0;
        foreach ($validatedRequests as $request) {
            if ($request->getType()->value === 'deposit') {
                $totalDeposits += (float) $request->getAmount();
            }
        }

        // P&L des trades clôturés (pour affichage)
        $adminTotalPnl = 0.0;
        foreach ($closedTransactions as $transaction) {
            $adminTotalPnl += (float) $transaction->getProfitLoss();
        }

        // Fonds disponibles = dépôts - retraits + P&L clôturé - montant investi dans les positions ouvertes
        $availableFunds = $this->fundsCalculator->getAvailableFunds($user);

        // Préparation des données pour le graphique P&L (P&L cumulatif de toutes les transactions)
        $pnlData = [];
        $cumulativePnl = 0;
        
        // Trier les transactions par date
        $sortedTransactions = $closedTransactions;
        usort($sortedTransactions, fn($a, $b) => $a->getTransactionDate() <=> $b->getTransactionDate());

        // Construire les données pour le graphique (P&L cumulatif)
        foreach ($sortedTransactions as $transaction) {
            $profitLoss = (float) $transaction->getProfitLoss();
            $cumulativePnl += $profitLoss;
            
            $pnlData[] = [
                'date' => $transaction->getTransactionDate()->format('Y-m-d'),
                'dateFormatted' => $transaction->getTransactionDate()->format('d/m/Y'),
                'pnl' => $profitLoss,
                'cumulativePnl' => $cumulativePnl,
            ];
        }

        return $this->render('home/index.html.twig', [
            'transactions' => $closedTransactions,
            'requests' => $validatedRequests,
            'pnlChartData' => $pnlData,
            'totalDeposits' => $totalDeposits,
            'availableFunds' => $availableFunds,
            'adminTransactionsCount' => count($closedTransactions),
            'adminTotalPnl' => $adminTotalPnl,
        ]);
    }
}
