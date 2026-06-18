<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Conversation\EventAppender;
use App\Conversation\ProjectionFolder;
use App\Conversation\ThreadLifecycleService;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ActorType;
use App\Enum\MembershipRole;
use App\Repository\ConversationEventRepository;
use App\Repository\ThreadRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Fold + rebuild coverage for the event-sourced thread lifecycle (step-03
 * chunk D2), mirroring ThreadAttachmentFoldTest. Asserts rename folds the
 * title onto the projection, archive flips status to `archived` (soft hide —
 * the active-list query stops returning it while the full history survives),
 * the last_sequence guard makes a re-fold a no-op, and a full rebuild
 * reconstructs identical state.
 */
final class ThreadLifecycleFoldTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private EventAppender $appender;
    private ThreadLifecycleService $service;
    private ConversationEventRepository $events;
    private ThreadRepository $threads;
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
        $hash = $hasher->hashPassword(new TransientLifecycleHashUser(), 'hunter2hunter2');
        $this->user = new User('beau-lifecycle@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);
        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->appender = $container->get(EventAppender::class);
        $this->events = $container->get(ConversationEventRepository::class);
        $this->threads = $container->get(ThreadRepository::class);
        $this->folder = $container->get(ProjectionFolder::class);
        $this->service = new ThreadLifecycleService($this->appender);

        $this->console = new Application(self::$kernel);
        $this->console->setAutoExit(false);
    }

    public function testRenameFoldsTitleAndArchiveSoftHides(): void
    {
        $threadId = $this->seedThread();

        // A freshly seeded thread is active and listed.
        $active = $this->threads->findActiveByTenantOrderedByUpdatedAt($this->tenant->getId());
        self::assertCount(1, $active);
        self::assertNull($active[0]->getTitle());

        $this->service->rename($threadId, $this->tenant->getId(), 'Renamed thread', $this->user->getId()->toRfc4122());
        $this->em->clear();

        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread);
        self::assertSame('Renamed thread', $thread->getTitle());
        self::assertSame('active', $thread->getStatus());

        $this->service->archive($threadId, $this->tenant->getId(), $this->user->getId()->toRfc4122());
        $this->em->clear();

        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread);
        self::assertSame('archived', $thread->getStatus());
        // Title survives archival — archive is a soft hide, not a wipe.
        self::assertSame('Renamed thread', $thread->getTitle());

        // Active-only list no longer returns it; the unfiltered list still does.
        self::assertCount(0, $this->threads->findActiveByTenantOrderedByUpdatedAt($this->tenant->getId()));
        self::assertCount(1, $this->threads->findByTenantOrderedByUpdatedAt($this->tenant->getId()));
    }

    public function testReplayIsIdempotent(): void
    {
        $threadId = $this->seedThread();
        $this->service->rename($threadId, $this->tenant->getId(), 'Replay title', $this->user->getId()->toRfc4122());
        $this->service->archive($threadId, $this->tenant->getId(), $this->user->getId()->toRfc4122());

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

    public function testRebuildReconstructsIdenticalThreadState(): void
    {
        $threadId = $this->seedThread();
        $this->service->rename($threadId, $this->tenant->getId(), 'Rebuilt title', $this->user->getId()->toRfc4122());
        $this->service->archive($threadId, $this->tenant->getId(), $this->user->getId()->toRfc4122());

        $before = $this->snapshot($threadId);
        self::assertSame('Rebuilt title', $before['title']);
        self::assertSame('archived', $before['status']);

        $this->em->clear();
        $tester = new CommandTester($this->console->find('app:projections:rebuild'));
        $exit = $tester->execute(['thread' => $threadId->toRfc4122()]);
        self::assertSame(0, $exit);

        $this->em->clear();
        self::assertSame($before, $this->snapshot($threadId));
    }

    private function seedThread(): Uuid
    {
        $threadId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $this->tenant->getId(),
            threadId: $threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $this->user->getId()->toRfc4122(),
            payload: new UserMessageSubmitted(Uuid::v7(), 'hello'),
        ));
        $this->em->clear();

        return $threadId;
    }

    /** @return array{title: ?string, status: string, last_sequence: int} */
    private function snapshot(Uuid $threadId): array
    {
        $thread = $this->threads->find($threadId);
        self::assertNotNull($thread);

        return [
            'title' => $thread->getTitle(),
            'status' => $thread->getStatus(),
            'last_sequence' => $thread->getLastSequence(),
        ];
    }
}

final class TransientLifecycleHashUser implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
