<?php

namespace App\Service;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\RequestType;
use App\Repository\RequestRepository;
use App\Repository\TransactionRepository;

class FundsCalculator
{
    public function __construct(
        private RequestRepository $requestRepository,
        private TransactionRepository $transactionRepository,
    ) {}

    /**
     * Fonds disponibles = dépôts validés - retraits validés
     *                    + P&L des trades clôturés
     *                    - montant investi dans les trades encore ouverts
     */
    public function getAvailableFunds(User $user, ?Transaction $excluding = null): float
    {
        $totalDeposits = 0.0;
        $totalWithdrawals = 0.0;
        foreach ($this->requestRepository->findValidatedByUser($user) as $request) {
            $amount = (float) $request->getAmount();
            if ($request->getType() === RequestType::DEPOSIT) {
                $totalDeposits += $amount;
            } else {
                $totalWithdrawals += $amount;
            }
        }

        $closedPnl = 0.0;
        $investedInOpenPositions = 0.0;
        foreach ($this->transactionRepository->findByUser($user) as $transaction) {
            if ($excluding !== null && $transaction->getId() === $excluding->getId()) {
                continue;
            }

            if ($transaction->getExitPrice() !== null) {
                $closedPnl += (float) $transaction->getProfitLoss();
            } else {
                $investedInOpenPositions += (float) $transaction->getEntryPrice() * (float) $transaction->getAmount();
            }
        }

        return $totalDeposits - $totalWithdrawals + $closedPnl - $investedInOpenPositions;
    }
}
