<?php

declare(strict_types=1);

namespace App\Tests\Functional\TypedEntity;

use App\Entity\CoreDocument;
use App\Entity\Tenant;
use App\Repository\CoreDocumentRepository;
use App\TypedEntity\EntityResolver;
use App\TypedEntity\Reference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Uid\Uuid;

final class EntityResolverTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EntityResolver $resolver;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE core_documents, message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $clock = Clock::get();
        $this->tenant = new Tenant('alpha', 'Alpha', $clock);
        $this->otherTenant = new Tenant('beta', 'Beta', $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($this->otherTenant);
        $this->em->flush();

        // EntityResolver is autowired but currently has no in-app consumer, so
        // the container compiler inlines it. Construct directly until chunk 7
        // (PromptAssembler) pulls it in as a dependency.
        $this->resolver = new EntityResolver($container->get(CoreDocumentRepository::class));
    }

    public function testResolvesCoreDocumentInstance(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $doc = new CoreDocument($id, $this->tenant->getId(), null, 'Spec', "# H\n\nbody", ['adr'], 'inbox', $now);
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        $ref = new Reference('core', 'core.document', $id->toRfc4122(), resolved: true);
        $resolved = $this->resolver->resolve($ref, $this->tenant->getId());

        self::assertTrue($resolved->isResolved());
        self::assertSame($ref, $resolved->reference);
        self::assertNotNull($resolved->instance);
        self::assertSame('Spec', $resolved->instance['title']);
        self::assertSame('adr', $resolved->instance['tags'][0]);
    }

    public function testDanglingWhenIdNotFoundInTenant(): void
    {
        $ref = new Reference('core', 'core.document', Uuid::v7()->toRfc4122(), resolved: true);

        $resolved = $this->resolver->resolve($ref, $this->tenant->getId());

        self::assertFalse($resolved->isResolved());
        self::assertNull($resolved->instance);
        self::assertSame($ref, $resolved->reference);
    }

    public function testDanglingForCrossTenantId(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $doc = new CoreDocument($id, $this->tenant->getId(), null, 'Spec', 'body', [], null, $now);
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        $ref = new Reference('core', 'core.document', $id->toRfc4122(), resolved: true);
        $resolved = $this->resolver->resolve($ref, $this->otherTenant->getId());

        self::assertFalse($resolved->isResolved(), 'cross-tenant id must dangle, not resolve');
    }

    public function testDanglingForMalformedId(): void
    {
        $ref = new Reference('core', 'core.document', 'not-a-uuid', resolved: true);

        $resolved = $this->resolver->resolve($ref, $this->tenant->getId());

        self::assertFalse($resolved->isResolved());
    }

    public function testDanglingForUnknownProvider(): void
    {
        $ref = new Reference('hypomnema', 'hypomnema.note', 'whatever', resolved: true);

        $resolved = $this->resolver->resolve($ref, $this->tenant->getId());

        self::assertFalse($resolved->isResolved());
    }

    public function testIdOpaquenessByteEqualityWorks(): void
    {
        $id = Uuid::v7();
        $now = new \DateTimeImmutable('2026-06-16T10:00:00+00:00');
        $doc = new CoreDocument($id, $this->tenant->getId(), null, 'Spec', 'body', [], null, $now);
        $this->em->persist($doc);
        $this->em->flush();
        $this->em->clear();

        // Different string forms of the same uuid resolve to the same row
        // because Uuid::fromString accepts both; the host treats the canonical
        // rfc4122 form as the byte-equality key.
        $rfc = $id->toRfc4122();
        $hex = (string) $id;

        $a = $this->resolver->resolve(new Reference('core', 'core.document', $rfc, true), $this->tenant->getId());
        $b = $this->resolver->resolve(new Reference('core', 'core.document', $hex, true), $this->tenant->getId());

        self::assertTrue($a->isResolved());
        self::assertTrue($b->isResolved());
    }
}
