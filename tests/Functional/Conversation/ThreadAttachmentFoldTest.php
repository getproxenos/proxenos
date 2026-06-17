<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\ThreadEntityAttached;
use App\Conversation\Event\Payload\ThreadEntityDetached;
use App\Conversation\EventAppender;
use App\Conversation\ProjectionFolder;
use App\Conversation\ThreadAttachmentService;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ActorType;
use App\Enum\MembershipRole;
use App\Repository\ConversationEventRepository;
use App\Repository\ThreadAttachmentRepository;
use App\TypedEntity\Reference;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fold + rebuild coverage for the event-sourced attach/pin projection
 * (step-02 chunk 6), mirroring ProjectionFoldTest. Asserts attach upserts a
 * row, a second attach adds another, detach removes the row, replay is
 * idempotent (last_sequence guard + no-op detach), and the
 * `ThreadAttachmentService` round-trips References in attach order.
 */
final class ThreadAttachmentFoldTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EventAppender $appender;
    private ThreadAttachmentService $service;
    private ConversationEventRepository $events;
    private ThreadAttachmentRepository $attachments;
    private ProjectionFolder $folder;
    private Application $console;

    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE thread_attachments, message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = new MockClock('2026-06-16T10:00:00+00:00');

        $this->tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientAttachmentHashUser(), 'hunter2hunter2');
        $this->user = new User('beau-attach@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
        $this->events = $container->get(ConversationEventRepository::class);
        $this->attachments = $container->get(ThreadAttachmentRepository::class);
        $this->folder = $container->get(ProjectionFolder::class);
        // ThreadAttachmentService has no runtime consumer until chunk 7, so the
        // test container inlines it away — construct it directly from its deps.
        $this->service = new ThreadAttachmentService($this->appender, $this->attachments);

        $this->console = new Application(self::$kernel);
        $this->console->setAutoExit(false);
    }

    public function testAttachThenSecondAttachThenDetach(): void
    {
        $threadId = Uuid::v7();
        $ref1 = new Reference('core', 'core.document', 'doc-one', resolved: true, label: 'One');
        $ref2 = new Reference('core', 'core.document', 'doc-two', resolved: true, label: 'Two');

        $this->appendAttach($threadId, $ref1);
        $this->em->clear();
        self::assertCount(1, $this->attachments->findForThreadInAttachOrder($threadId));

        $row = $this->attachments->findOneByIdentity($threadId, 'core', 'core.document', 'doc-one');
        self::assertNotNull($row);
        self::assertSame('doc-one', $row->getEntityId());
        self::assertSame($this->tenant->getId()->toRfc4122(), $row->getTenantId()->toRfc4122());
        $restored = Reference::fromArray($row->getReference());
        self::assertSame('One', $restored->label);
        self::assertTrue($restored->resolved);

        $this->appendAttach($threadId, $ref2);
        $this->em->clear();
        self::assertCount(2, $this->attachments->findForThreadInAttachOrder($threadId));

        $this->appendDetach($threadId, 'core', 'core.document', 'doc-one');
        $this->em->clear();
        $rows = $this->attachments->findForThreadInAttachOrder($threadId);
        self::assertCount(1, $rows);
        self::assertSame('doc-two', $rows[0]->getEntityId());
    }

    public function testDetachOfAbsentRowIsNoOp(): void
    {
        $threadId = Uuid::v7();
        // Detach with no prior attach must not throw and must leave no rows.
        $this->appendDetach($threadId, 'core', 'core.document', 'never-attached');
        $this->em->clear();

        self::assertCount(0, $this->attachments->findForThreadInAttachOrder($threadId));
    }

    public function testReplayIsIdempotent(): void
    {
        $threadId = Uuid::v7();
        $this->appendAttach($threadId, new Reference('core', 'core.document', 'doc-one', label: 'One'));
        $this->appendAttach($threadId, new Reference('core', 'core.document', 'doc-two', label: 'Two'));

        $before = $this->snapshot($threadId);

        // Re-fold the whole stream a second time through the same folder the
        // write path uses — the last_sequence guard makes it a no-op.
        $this->em->clear();
        foreach ($this->events->findByThreadOrdered($threadId) as $event) {
            $this->folder->apply($event);
        }
        $this->em->clear();

        self::assertSame($before, $this->snapshot($threadId));
    }

    public function testRebuildReconstructsIdenticalAttachmentState(): void
    {
        $threadId = Uuid::v7();
        $this->appendAttach($threadId, new Reference('core', 'core.document', 'doc-one', label: 'One'));
        $this->appendAttach($threadId, new Reference('core', 'core.document', 'doc-two', label: 'Two'));
        $this->appendDetach($threadId, 'core', 'core.document', 'doc-one');

        $before = $this->snapshot($threadId);
        // attach…detach of doc-one must leave only doc-two.
        self::assertCount(1, $before);

        $this->em->clear();
        $tester = new CommandTester($this->console->find('app:projections:rebuild'));
        $exit = $tester->execute(['thread' => $threadId->toRfc4122()]);
        self::assertSame(0, $exit);

        $this->em->clear();
        self::assertSame($before, $this->snapshot($threadId));
    }

    public function testServiceRoundTripListsInAttachOrderAndReflectsDetach(): void
    {
        $threadId = Uuid::v7();
        $tenantId = $this->tenant->getId();
        $actorId = $this->user->getId()->toRfc4122();

        $this->service->attach($threadId, $tenantId, new Reference('core', 'core.document', 'doc-one', label: 'One'), $actorId);
        $this->service->attach($threadId, $tenantId, new Reference('core', 'core.document', 'doc-two', label: 'Two'), $actorId);
        $this->em->clear();

        $list = $this->service->listForThread($threadId);
        self::assertCount(2, $list);
        self::assertContainsOnlyInstancesOf(Reference::class, $list);
        self::assertSame('doc-one', $list[0]->id);
        self::assertSame('doc-two', $list[1]->id);
        self::assertSame('One', $list[0]->label);

        $this->service->detach($threadId, $tenantId, 'core', 'core.document', 'doc-one', $actorId);
        $this->em->clear();

        $after = $this->service->listForThread($threadId);
        self::assertCount(1, $after);
        self::assertSame('doc-two', $after[0]->id);
    }

    private function appendAttach(Uuid $threadId, Reference $reference): void
    {
        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new ThreadEntityAttached($reference),
        ));
    }

    private function appendDetach(Uuid $threadId, string $provider, string $type, string $id): void
    {
        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new ThreadEntityDetached($provider, $type, $id),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function snapshot(Uuid $threadId): array
    {
        $out = [];
        foreach ($this->attachments->findForThreadInAttachOrder($threadId) as $row) {
            $out[] = [
                'entity_id' => $row->getEntityId(),
                'provider' => $row->getProvider(),
                'type' => $row->getType(),
                'last_sequence' => $row->getLastSequence(),
                // Normalize through the VO — JSONB does not preserve key order,
                // so compare the reconstructed reference, not the raw blob.
                'reference' => Reference::fromArray($row->getReference())->toArray(),
            ];
        }

        return $out;
    }
}

final class TransientAttachmentHashUser implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
