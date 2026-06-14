<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MessagePart;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<MessagePart>
 */
final class MessagePartRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessagePart::class);
    }

    public function findOneByMessageAndPosition(Uuid $messageId, int $position): ?MessagePart
    {
        return $this->findOneBy(['messageId' => $messageId, 'position' => $position]);
    }
}
