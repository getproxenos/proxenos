<?php

declare(strict_types=1);

namespace App\Tests\Functional\Entity;

use App\Entity\CoreDocument;
use App\Entity\Tenant;
use App\Repository\CoreDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

/**
 * Roundtrip the core_documents host-storage table — persist, fetch, update,
 * and confirm tenant scoping in the repository.
 */
final class CoreDocumentRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CoreDocumentRepository $repo;
    private Tenant $tenantA;
    private Tenant $tenantB;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE core_documents, message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $clock = Clock::get();
        $this->tenantA = new Tenant('alpha', 'Alpha', $clock);
        $this->tenantB = new Tenant('beta', 'Beta', $clock);
        $this->em->persist($this->tenantA);
        $this->em->persist($this->tenantB);
        $this->em->flush();

        $this->repo = $container->get(CoreDocumentRepository::class);
    }

    public function testPersistAndFetchRoundTripsAllFields(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $doc = new CoreDocument(
            $id,
            $this->tenantA->getId(),
            null,
            'Spec',
            "# Heading\n\nbody",
            ['adr', 'draft'],
            'inbox',
            $now,
        );
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->repo->findOneByIdForTenant($id, $this->tenantA->getId());

        self::assertNotNull($loaded);
        self::assertSame('Spec', $loaded->getTitle());
        self::assertSame("# Heading\n\nbody", $loaded->getBody());
        self::assertSame(['adr', 'draft'], $loaded->getTags());
        self::assertSame('inbox', $loaded->getCollection());
        self::assertEquals($now, $loaded->getCreatedAt());
        self::assertEquals($now, $loaded->getUpdatedAt());
    }

    public function testFindIsTenantScopedAndReturnsNullCrossTenant(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $doc = new CoreDocument($id, $this->tenantA->getId(), null, 'Tenant-A only', 'body', [], null, $now);
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        // Same id, wrong tenant → must be null (no "this id exists" leak).
        self::assertNull($this->repo->findOneByIdForTenant($id, $this->tenantB->getId()));
        self::assertNotNull($this->repo->findOneByIdForTenant($id, $this->tenantA->getId()));
    }

    public function testUpdateRoundTrips(): void
    {
        $id = Uuid::v7();
        $created = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $modified = new \DateTimeImmutable('2026-06-16T11:00:00+00:00');
        $doc = new CoreDocument($id, $this->tenantA->getId(), null, 'v1', 'old', ['x'], null, $created);
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        $loaded = $this->repo->findOneByIdForTenant($id, $this->tenantA->getId());
        self::assertNotNull($loaded);
        $loaded->update('v2', 'new', ['y', 'z'], 'project', $modified);
        $this->em->flush();
        $this->em->clear();

        $again = $this->repo->findOneByIdForTenant($id, $this->tenantA->getId());
        self::assertNotNull($again);
        self::assertSame('v2', $again->getTitle());
        self::assertSame('new', $again->getBody());
        self::assertSame(['y', 'z'], $again->getTags());
        self::assertSame('project', $again->getCollection());
        self::assertEquals($created, $again->getCreatedAt());
        self::assertEquals($modified, $again->getUpdatedAt());
    }
}
