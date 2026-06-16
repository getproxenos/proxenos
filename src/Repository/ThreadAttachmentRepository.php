<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ThreadAttachment;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<ThreadAttachment>
 */
final class ThreadAttachmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ThreadAttachment::class);
    }

    /**
     * Look up a single attachment by the thread plus the reference identity
     * triple (`provider` + `type` + opaque `id`). Used by the fold to upsert
     * and by detach to find the row to delete.
     */
    public function findOneByIdentity(Uuid $threadId, string $provider, string $type, string $id): ?ThreadAttachment
    {
        return $this->find([
            'threadId' => $threadId,
            'provider' => $provider,
            'type' => $type,
            'entityId' => $id,
        ]);
    }

    /**
     * Attachments for a thread, in attach order (`attached_at`, then
     * `last_sequence` as a stable tiebreaker for same-instant attaches).
     *
     * @return list<ThreadAttachment>
     */
    public function findForThreadInAttachOrder(Uuid $threadId): array
    {
        /** @var list<ThreadAttachment> $rows */
        $rows = $this->createQueryBuilder('a')
            ->where('a.threadId = :threadId')
            ->setParameter('threadId', $threadId, 'uuid')
            ->orderBy('a.attachedAt', 'ASC')
            ->addOrderBy('a.lastSequence', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
