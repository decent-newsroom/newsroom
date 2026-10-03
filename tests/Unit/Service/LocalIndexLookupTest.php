<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Dto\CategoryDraft;
use App\Entity\Event;
use App\Repository\EventRepository;
use App\Service\ReadingListManager;
use App\Service\ReadingListWorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class LocalIndexLookupTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER_OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /**
     * @dataProvider indexLookupCases
     */
    public function testLookupUsesExactOwnerAndRawTags(string $identifier, bool $excludeMagazineIndexes, ?string $expectedId): void
    {
        $events = [
            $this->event('other-owner', self::OTHER_OWNER, 100, [['d', 'category']]),
            $this->event('wrong-kind', self::OWNER, 90, [['d', 'category']], 30041),
            $this->event('magazine', self::OWNER, 80, [['d', 'category'], ['a', '30040:' . self::OWNER . ':child']]),
            $this->event('newest', self::OWNER, 70, [['d', 'category'], ['a', '30023:' . self::OTHER_OWNER . ':article']]),
            $this->event('older', self::OWNER, 60, [['d', 'category']]),
            $this->event('whitespace', self::OWNER, 50, [['d', ' category ']]),
            $this->event('last-d-tag', self::OWNER, 40, [['d', 'first'], ['d', 'last']]),
            $this->event('missing-d-tag', self::OWNER, 30, [['title', 'No identifier']]),
            $this->event('empty-d-tag', self::OWNER, 20, [['d', '']]),
            $this->event('other-only', self::OTHER_OWNER, 10, [['d', 'other-only']]),
        ];
        // The cached identifier must not override the raw tag, including legacy null values.
        $events[3]->setDTag('stale-cached-identifier');
        self::assertNull($events[5]->getDTag());

        $repository = $this->getMockBuilder(EventRepository::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findBy'])
            ->getMock();
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['kind' => 30040, 'pubkey' => self::OWNER], ['created_at' => 'DESC'])
            ->willReturnCallback(static function (array $criteria, array $orderBy) use ($events): array {
                $matching = array_values(array_filter(
                    $events,
                    static fn (Event $event): bool => $event->getKind() === $criteria['kind']
                        && $event->getPubkey() === $criteria['pubkey'],
                ));
                usort($matching, static fn (Event $a, Event $b): int => $b->getCreatedAt() <=> $a->getCreatedAt());

                return $matching;
            });

        $result = $repository->findLatestIndexByIdentifier(self::OWNER, $identifier, $excludeMagazineIndexes);
        if ($expectedId === null) {
            self::assertNull($result);

            return;
        }

        $expected = array_values(array_filter($events, static fn (Event $event): bool => $event->getId() === $expectedId))[0];
        self::assertSame($expected, $result);
    }

    public static function indexLookupCases(): iterable
    {
        yield 'root allowed by default' => ['category', false, 'magazine'];
        yield 'wizard selects newest non-magazine revision' => ['category', true, 'newest'];
        yield 'significant whitespace and legacy null d_tag' => [' category ', true, 'whitespace'];
        yield 'partial whitespace does not match' => ['category ', false, null];
        yield 'last raw d tag preserves wizard behavior' => ['last', false, 'last-d-tag'];
        yield 'earlier raw d tag is not the identifier' => ['first', false, null];
        yield 'explicit empty d tag differs from absent tag' => ['', false, 'empty-d-tag'];
        yield 'other author cannot satisfy missing local data' => ['other-only', false, null];
        yield 'missing identifier' => ['missing', false, null];
    }

    public function testWizardUsesSharedLookupAndPreservesDraftMetadataAndSession(): void
    {
        $slug = ' category ';
        $event = $this->event('stored-category', self::OWNER, 123, [
            ['d', $slug],
            ['title', 'Stored title'],
            ['summary', 'Stored summary'],
            ['image', 'https://example.com/cover.jpg'],
            ['author', 'Display author'],
            ['t', 'one'],
            ['t', 'two'],
            ['a', '30023:' . self::OTHER_OWNER . ':article'],
            ['type', 'custom-list'],
            ['unrecognized', 'ignored'],
        ]);
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestIndexByIdentifier')
            ->with(self::OWNER, $slug, true)
            ->willReturn($event);
        $repository->expects(self::never())->method('findBy');
        $session = new Session(new MockArraySessionStorage());
        $manager = $this->manager($repository, $session);

        $draft = $manager->loadPublishedListIntoDraft($slug);

        self::assertInstanceOf(CategoryDraft::class, $draft);
        self::assertSame($slug, $draft->slug);
        self::assertSame('Stored title', $draft->title);
        self::assertSame('Stored summary', $draft->summary);
        self::assertSame('https://example.com/cover.jpg', $draft->image);
        self::assertSame('Display author', $draft->author);
        self::assertSame(['one', 'two'], $draft->tags);
        self::assertSame(['30023:' . self::OTHER_OWNER . ':article'], $draft->articles);
        self::assertSame($draft, $session->get('read_wizard'));
        self::assertSame('custom-list', $session->get('read_wizard_type'));
        self::assertSame($slug, $session->get('selected_reading_list_slug'));
    }

    public function testMissingWizardLookupLeavesExistingSessionUntouched(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestIndexByIdentifier')
            ->with(self::OWNER, 'missing', true)
            ->willReturn(null);
        $repository->expects(self::never())->method('findBy');
        $session = new Session(new MockArraySessionStorage());
        $existingDraft = new CategoryDraft();
        $session->set('read_wizard', $existingDraft);
        $session->set('read_wizard_type', 'custom-list');
        $session->set('selected_reading_list_slug', 'existing');
        $manager = $this->manager($repository, $session);

        self::assertNull($manager->loadPublishedListIntoDraft('missing'));
        self::assertSame($existingDraft, $session->get('read_wizard'));
        self::assertSame('custom-list', $session->get('read_wizard_type'));
        self::assertSame('existing', $session->get('selected_reading_list_slug'));
    }

    public function testWizardDefaultsMissingTypeToReadingList(): void
    {
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())
            ->method('findLatestIndexByIdentifier')
            ->with(self::OWNER, 'category', true)
            ->willReturn($this->event('category', self::OWNER, 1, [['d', 'category']]));
        $session = new Session(new MockArraySessionStorage());

        self::assertInstanceOf(CategoryDraft::class, $this->manager($repository, $session)->loadPublishedListIntoDraft('category'));
        self::assertSame('reading-list', $session->get('read_wizard_type'));
    }

    private function event(string $id, string $pubkey, int $createdAt, array $tags, int $kind = 30040): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($pubkey);
        $event->setKind($kind);
        $event->setCreatedAt($createdAt);
        $event->setTags($tags);

        return $event;
    }

    private function manager(EventRepository $repository, Session $session): ReadingListManager
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('getRepository')->with(Event::class)->willReturn($repository);
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn(self::OWNER);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->method('getToken')->willReturn($token);
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack();
        $requestStack->push($request);
        $workflow = $this->createMock(ReadingListWorkflowService::class);
        $workflow->expects(self::never())->method('initializeDraft');

        return new ReadingListManager($em, $tokenStorage, $requestStack, $workflow);
    }
}
