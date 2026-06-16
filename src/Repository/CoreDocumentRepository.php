<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CoreDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<CoreDocument>
 */
final class CoreDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CoreDocument::class);
    }

    /**
     * Look up by primary key, scoped to the tenant. Used by the resolver
     * to honor `Reference{provider: core, type: core.document, id}`.
     * Cross-tenant ids are treated as dangling — there's no leak of "this
     * id exists somewhere".
     */
    public function findOneByIdForTenant(Uuid $id, Uuid $tenantId): ?CoreDocument
    {
        return $this->createQueryBuilder('d')
            ->where('d.id = :id')
            ->andWhere('d.tenantId = :tenantId')
            ->setParameter('id', $id, 'uuid')
            ->setParameter('tenantId', $tenantId, 'uuid')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
