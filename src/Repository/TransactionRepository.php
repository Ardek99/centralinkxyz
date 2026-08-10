<?php

namespace App\Repository;

use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\CryptoType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Transaction>
 */
class TransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Transaction::class);
    }

    /**
     * Trouve toutes les transactions VALIDÉES d'un utilisateur
     * 
     * @return Transaction[]
     */
    public function findValidatedByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.isValidated = true')
            ->setParameter('user', $user)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve toutes les transactions d'un utilisateur (validées et en attente)
     * 
     * @return Transaction[]
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve toutes les transactions VALIDÉES, tous clients confondus (pour l'admin)
     *
     * @return Transaction[]
     */
    public function findAllValidated(): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.isValidated = true')
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les transactions par type de crypto (validées uniquement)
     * 
     * @return Transaction[]
     */
    public function findByUserAndCryptoType(User $user, CryptoType $cryptoType): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.cryptoType = :cryptoType')
            ->andWhere('t.isValidated = true')
            ->setParameter('user', $user)
            ->setParameter('cryptoType', $cryptoType)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Trouve les transactions VALIDÉES et CLÔTURÉES d'un utilisateur
     * 
     * @return Transaction[]
     */
    public function findValidatedAndClosedByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.user = :user')
            ->andWhere('t.isValidated = true')
            ->andWhere('t.exitPrice IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('t.transactionDate', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Calcule le total investi par un utilisateur (transactions validées)
     */
    public function getTotalInvestedByUser(User $user): float
    {
        $result = $this->createQueryBuilder('t')
            ->select('SUM(t.entryPrice * t.amount) as total')
            ->andWhere('t.user = :user')
            ->andWhere('t.isValidated = true')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }
}
