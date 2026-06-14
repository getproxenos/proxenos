<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Message>
 */
final class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Highest `position` used on the messages projection for a thread; used by
     * `ProjectionFolder` to assign the next position when folding a new
     * message into the thread.
     */
    public function maxPositionForThread(Uuid $threadId): int
    {
        $result = $this->createQueryBuilder('m')
            ->select('MAX(m.position)')
            ->where('m.threadId = :threadId')
            ->setParameter('threadId', $threadId, 'uuid')
            ->getQuery()
            ->getSingleScalarResult();

        return null === $result ? -1 : (int) $result;
    }

    public function findOneByTurnId(Uuid $turnId): ?Message
    {
        return $this->findOneBy(['turnId' => $turnId]);
    }

    /**
     * Messages on a thread in position order — the input for ADR-023's dumb
     * prompt assembly (concat prior turns; ADR-016 budget planning is later).
     *
     * @return list<Message>
     */
    public function findByThreadOrdered(Uuid $threadId): array
    {
        /** @var list<Message> $messages */
        $messages = $this->createQueryBuilder('m')
            ->where('m.threadId = :threadId')
            ->setParameter('threadId', $threadId, 'uuid')
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $messages;
    }
}
