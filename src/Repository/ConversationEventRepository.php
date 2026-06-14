<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ConversationEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ConversationEvent>
 */
final class ConversationEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ConversationEvent::class);
    }

    /**
     * Highest `sequence` seen for a thread, or 0 if the thread has no events.
     * Used by `EventAppender` to assign the next sequence inside the append
     * transaction.
     */
    public function maxSequenceForThread(Uuid $threadId): int
    {
        $result = $this->createQueryBuilder('e')
            ->select('MAX(e.sequence)')
            ->where('e.threadId = :threadId')
            ->setParameter('threadId', $threadId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $result ? 0 : (int) $result;
    }

    /**
     * Events for a thread in (sequence ASC) order. Used by the rebuild path
     * to replay history through `ProjectionFolder`.
     *
     * @return list<ConversationEvent>
     */
    public function findByThreadOrdered(Uuid $threadId): array
    {
        /* @var list<ConversationEvent> */
        return $this->createQueryBuilder('e')
            ->where('e.threadId = :threadId')
            ->setParameter('threadId', $threadId, 'uuid')
            ->orderBy('e.sequence', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
