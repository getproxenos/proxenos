<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Thread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Thread>
 */
final class ThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Thread::class);
    }

    /**
     * Threads for a tenant, most recently active first. Drives the chat UI's
     * thread list.
     *
     * @return list<Thread>
     */
    public function findByTenantOrderedByUpdatedAt(Uuid $tenantId): array
    {
        /** @var list<Thread> $threads */
        $threads = $this->createQueryBuilder('t')
            ->where('t.tenantId = :tenantId')
            ->setParameter('tenantId', $tenantId, 'uuid')
            ->orderBy('t.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $threads;
    }

    /**
     * Active (non-archived) threads for a tenant, most recently active first.
     * Drives the SPA thread list (step-03 chunk D2) — archived threads are
     * soft-hidden here while their full history stays in the event log and in
     * {@see self::findByTenantOrderedByUpdatedAt} (used for replay / Mercure
     * topic enumeration, which must still include archived threads).
     *
     * @return list<Thread>
     */
    public function findActiveByTenantOrderedByUpdatedAt(Uuid $tenantId): array
    {
        /** @var list<Thread> $threads */
        $threads = $this->createQueryBuilder('t')
            ->where('t.tenantId = :tenantId')
            ->andWhere('t.status = :status')
            ->setParameter('tenantId', $tenantId, 'uuid')
            ->setParameter('status', 'active')
            ->orderBy('t.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();

        return $threads;
    }
}
