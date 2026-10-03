<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\EventStatusEnum;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Unfold\InteractionHydrator;
use App\Service\Nostr\RelayRegistry;
use DecentNewsroom\UnfoldBundle\Contract\Comment;
use DecentNewsroom\UnfoldBundle\Contract\InteractionTarget;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationTreeLookupInterface;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Doctrine\DBAL\Connection;

final class InteractionHydrationTest extends TestCase
{
    public function testTargetPrefersLatestArticleProjectionAndPreservesLocalData(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $publicationCoordinate = '30040:' . str_repeat('b', 64) . ':magazine';

        $article = $this->article(
            pubkey: str_repeat('a', 64),
            slug: 'story',
            createdAt: 2000,
            raw: [
                'id' => str_repeat('1', 64),
                'pubkey' => str_repeat('a', 64),
                'kind' => KindsEnum::LONGFORM->value,
                'content' => 'Article body',
                'tags' => [
                    ['d', 'story'],
                    ['title', 'Story title'],
                    ['summary', 'Story summary'],
                    ['published_at', '2000'],
                ],
                'created_at' => 2000,
                'sig' => str_repeat('c', 128),
            ],
        );
        $event = $this->event(
            id: str_repeat('2', 64),
            pubkey: str_repeat('a', 64),
            kind: KindsEnum::LONGFORM->value,
            content: 'Older body',
            tags: [
                ['d', 'story'],
                ['title', 'Older title'],
            ],
            createdAt: 1000,
            sig: str_repeat('d', 128),
        );

        $hydrator = $this->hydrator(
            articles: [$article],
            events: [$event],
            descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), KindsEnum::LONGFORM->value)],
        );

        $target = $hydrator->target($publicationCoordinate, $coordinate);

        self::assertInstanceOf(InteractionTarget::class, $target);
        self::assertSame($publicationCoordinate, $target->publicationCoordinate);
        self::assertSame($coordinate, $target->post->coordinate);
        self::assertSame(str_repeat('1', 64), $target->post->eventId);
        self::assertSame('Story title', $target->post->title);
        self::assertNull($target->original, 'Fake signatures cannot become repost originals');
        self::assertSame('wss://relay.example', $target->relayHint);
    }

    public function testTargetResolvesWhenOnlyArticleProjectionExists(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $publicationCoordinate = '30040:' . str_repeat('b', 64) . ':magazine';

        $hydrator = $this->hydrator(
            articles: [$this->article(
                pubkey: str_repeat('a', 64),
                slug: 'story',
                createdAt: 2000,
                raw: [
                    'id' => str_repeat('1', 64),
                    'pubkey' => str_repeat('a', 64),
                    'kind' => KindsEnum::LONGFORM->value,
                    'content' => 'Article body',
                    'tags' => [
                        ['d', 'story'],
                        ['title', 'Story title'],
                    ],
                    'created_at' => 2000,
                    'sig' => str_repeat('c', 128),
                ],
            )],
            indexes: [$this->event(str_repeat('f', 64), str_repeat('b', 64), 30040, '', [
                ['d', 'magazine'], ['a', $coordinate],
            ], 2000, str_repeat('a', 128))],
        );

        $target = $hydrator->target($publicationCoordinate, $coordinate);

        self::assertInstanceOf(InteractionTarget::class, $target);
        self::assertNull($target->original);
    }

    public function testTargetReturnsNullForForeignPublicationMember(): void
    {
        $hydrator = $this->hydrator();

        self::assertNull(
            $hydrator->target(
                '30040:' . str_repeat('b', 64) . ':magazine',
                '30023:' . str_repeat('a', 64) . ':story',
            ),
        );
    }

    public function testLatestRawIndexesMustBePublicAndMatchExactIdentity(): void
    {
        $author = str_repeat('b', 64);
        $coordinate = '30023:' . str_repeat('a', 64) . ':story: exact ';
        $article = $this->article(str_repeat('a', 64), 'story: exact ', 2000, [
            'id' => str_repeat('1', 64), 'pubkey' => str_repeat('a', 64), 'kind' => 30023,
            'content' => '', 'created_at' => 2000, 'tags' => [['d', 'story: exact ']], 'sig' => str_repeat('c', 128),
        ]);
        $root = $this->event(str_repeat('2', 64), $author, 30040, '', [['d', 'magazine'], ['a', '30040:' . $author . ':child: exact ']], 3000, str_repeat('a', 128));
        $child = $this->event(str_repeat('3', 64), $author, 30040, '', [['d', 'child: exact '], ['a', $coordinate]], 2000, str_repeat('a', 128));
        $publication = '30040:' . $author . ':magazine';
        $target = $this->hydrator(articles: [$article], indexes: [$root, $child])->target($publication, $coordinate);
        self::assertSame($coordinate, $target?->post->coordinate);
        $child->setTags([['d', 'child: exact '], ['a', $coordinate], ['s', 'secret']]);
        self::assertNull($this->hydrator(articles: [$article], indexes: [$root, $child])->target($publication, $coordinate));
        $child->setTags([['d', 'child: exact '], ['a', $coordinate]]);
        $root->setTags([['d', 'magazine'], ['a', $coordinate], ['s', 'secret']]);
        self::assertNull($this->hydrator(articles: [$article], indexes: [$root, $child])->target($publication, $coordinate));
        $root->setTags([['d', 'magazine'], ['a', $coordinate]]);
        $root->setPubkey(str_repeat('9', 64));
        self::assertNull($this->hydrator(articles: [$article], indexes: [$root])->target($publication, $coordinate));
    }

    public function testMalformedScopedAndMismatchedArticleSourcesFailClosed(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $source = ['id' => str_repeat('1', 64), 'pubkey' => str_repeat('a', 64), 'kind' => 30023,
            'content' => '', 'created_at' => 2000, 'tags' => [['d', 'story']], 'sig' => str_repeat('c', 128)];
        foreach ([
            ['tags' => [['d', 'story'], ['s', 'secret'], ['broken', null]]],
            ['tags' => 'not-an-array'],
            ['tags' => [['d', 'other']]],
            ['pubkey' => str_repeat('9', 64)],
            ['id' => null],
            ['created_at' => null],
        ] as $change) {
            $article = $this->article(str_repeat('a', 64), 'story', 2000, array_replace($source, $change));
            self::assertNull($this->hydrator(articles: [$article], descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), 30023)])
                ->target('30040:' . str_repeat('b', 64) . ':magazine', $coordinate));
        }
    }

    public function testRepostOriginalRequiresHostVerificationOfTheUnmodifiedSource(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $source = ['id' => str_repeat('1', 64), 'pubkey' => str_repeat('a', 64), 'kind' => 30023,
            'content' => 'signed body', 'created_at' => 2000, 'tags' => [['d', 'story']], 'sig' => str_repeat('c', 128)];
        $article = $this->article(str_repeat('a', 64), 'story', 2000, $source);
        foreach ([true, false] as $valid) {
            $signer = $this->createMock(\App\Service\Nostr\NostrSigner::class);
            $signer->expects(self::once())->method('verify')->with(self::callback(static function (\Innis\Nostr\Core\Domain\Entity\Event $event) use ($source): bool {
                $actual = json_decode($event->toJson(), true, 512, JSON_THROW_ON_ERROR);
                foreach ($source as $key => $value) {
                    self::assertSame($value, $actual[$key]);
                }
                return true;
            }))->willReturn($valid);
            $target = $this->hydrator(articles: [$article], descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), 30023)],
                verifier: new \App\Service\Nostr\NostrEventVerifier($signer))
                ->target('30040:' . str_repeat('b', 64) . ':magazine', $coordinate);
            self::assertNotNull($target);
            self::assertSame($valid ? $source['id'] : null, $target->original?->id);
        }
    }

    public function testReplyChainsRejectConflictingRootsForeignParentsMissingParentsAndCycles(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $rootTags = [['A', $coordinate], ['K', '30023'], ['P', str_repeat('a', 64)]];
        $root = $this->comment(str_repeat('1', 64), 1111, str_repeat('c', 64), '', 100, $rootTags);
        $reply = static fn (string $id, string $parent, array $tags): Comment => new Comment($id, 1111, str_repeat('c', 64), '', 200, [
            ...$tags, ['e', $parent], ['k', '1111'], ['p', str_repeat('c', 64)],
        ]);
        $comments = [
            $root,
            $reply(str_repeat('2', 64), str_repeat('9', 64), $rootTags),
            $reply(str_repeat('3', 64), $root->id, [...$rootTags, ['A', '30023:' . str_repeat('9', 64) . ':foreign']]),
            $reply(str_repeat('4', 64), str_repeat('5', 64), $rootTags),
            $reply(str_repeat('5', 64), str_repeat('4', 64), $rootTags),
            new Comment(str_repeat('6', 64), 1111, str_repeat('c', 64), '', 100, [['A', '30023:' . str_repeat('9', 64) . ':foreign'], ['K', '30023'], ['P', str_repeat('9', 64)]]),
            $reply(str_repeat('7', 64), str_repeat('6', 64), $rootTags),
            new Comment(str_repeat('8', 64), 1111, str_repeat('c', 64), '', 100, [['A', $coordinate], ['K', '1111'], ['P', str_repeat('a', 64)]]),
        ];
        $hydrator = $this->hydrator(comments: $comments);
        $target = $this->targetForCoordinate($coordinate);
        self::assertEquals([$root], $hydrator->thread($target)->comments);
        foreach (array_slice($comments, 1) as $comment) {
            self::assertNull($hydrator->parent($target, $comment->id));
        }
    }

    public function testPaginationContinuesInsideLargeRootWithoutDroppingReplies(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $rootTags = [['A', $coordinate], ['K', '30023'], ['P', str_repeat('a', 64)]];
        $root = new Comment(str_repeat('a', 64), 1111, str_repeat('c', 64), '', 100, $rootTags);
        $comments = [$root];
        for ($i = 1; $i <= 620; ++$i) {
            $comments[] = new Comment(sprintf('%064x', $i), 1111, str_repeat('d', 64), '', 100 + $i,
                [...$rootTags, ['e', $root->id], ['k', '1111'], ['p', $root->pubkey]]);
        }
        $comments[] = new Comment(str_repeat('b', 64), 1111, str_repeat('c', 64), '', 90, $rootTags);
        $hydrator = $this->hydrator(comments: $comments);
        $target = $this->targetForCoordinate($coordinate);
        $ids = [];
        $cursor = null;
        $sizes = [];
        do {
            $page = $hydrator->thread($target, $cursor);
            self::assertSame(622, $page->count);
            $sizes[] = count($page->comments);
            foreach ($page->comments as $comment) {
                self::assertArrayNotHasKey($comment->id, $ids);
                $ids[$comment->id] = true;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        self::assertSame([100, 100, 100, 100, 100, 100, 22], $sizes);
        self::assertCount(622, $ids);
    }

    public function testRefreshWorkerResolvesMembershipBeforeNetworkAndIncludesRevisionOnlyEvents(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $publication = '30040:' . str_repeat('b', 64) . ':magazine';
        $article = $this->article(str_repeat('a', 64), 'story', 2000, [
            'id' => str_repeat('1', 64), 'pubkey' => str_repeat('a', 64), 'kind' => 30023,
            'content' => '', 'created_at' => 2000, 'tags' => [['d', 'story']], 'sig' => str_repeat('c', 128),
        ]);
        $root = $this->event(str_repeat('2', 64), str_repeat('b', 64), 30040, '', [['d', 'magazine'], ['a', $coordinate]], 2000, str_repeat('a', 128));
        $nostr = $this->createMock(\App\Service\Nostr\NostrClient::class);
        $projector = $this->createMock(\App\Service\GenericEventProjector::class);
        $signer = $this->createMock(\App\Service\Nostr\NostrSigner::class);
        $signer->method('verify')->willReturnCallback(static fn (\Innis\Nostr\Core\Domain\Entity\Event $event): bool =>
            json_decode($event->toJson(), true)['id'] !== str_repeat('f', 64));
        $deleted = $this->createMock(\App\Repository\DeletedEventRepository::class);
        $targetTombstoned = false;
        $deleted->method('isSuppressed')->willReturnCallback(static function (string $id) use (&$targetTombstoned): bool {
            return $id === str_repeat('e', 64) || ($targetTombstoned && $id === str_repeat('1', 64));
        });
        $events = [];
        foreach ([1111, 7, 16, 9735] as $i => $kind) {
            $events[] = (object) ['id' => sprintf('%064x', $i + 10), 'kind' => $kind, 'tags' => [['e', str_repeat('1', 64)]],
                'pubkey' => str_repeat('a', 64), 'created_at' => 3000, 'content' => '', 'sig' => str_repeat('c', 128)];
        }
        $reply = (object) ['id' => str_repeat('9', 64), 'kind' => 1111, 'tags' => [['e', $events[0]->id]],
            'pubkey' => str_repeat('a', 64), 'created_at' => 4000, 'content' => '', 'sig' => str_repeat('c', 128)];
        $suppressed = clone $events[1];
        $suppressed->id = str_repeat('e', 64);
        $invalid = clone $events[1];
        $invalid->id = str_repeat('f', 64);
        $nostr->expects(self::exactly(3))->method('getComments')->willReturnCallback(static function (string $reference, ?int $since, ?string $author) use ($coordinate, $events, $reply, $suppressed, $invalid): array {
            self::assertContains($reference, [$coordinate, str_repeat('1', 64), $events[0]->id]);
            self::assertSame(str_repeat('a', 64), $author);
            return $reference === $coordinate ? [$events[0]] : ($reference === $events[0]->id ? [$reply] : [...$events, $suppressed, $invalid]);
        });
        $projected = [];
        $projector->expects(self::exactly(5))->method('projectEventFromNostrEvent')->willReturnCallback(function (object $event) use (&$projected): Event {
            $projected[] = $event->id;
            return $this->event($event->id, $event->pubkey, $event->kind, $event->content, $event->tags, $event->created_at, $event->sig);
        });
        $handler = new \App\MessageHandler\RefreshReaderInteractionsHandler($this->hydrator(articles: [$article], indexes: [$root]), $nostr, $projector,
            new \App\Service\Nostr\NostrEventVerifier($signer), $deleted);
        $message = new \App\Message\RefreshReaderInteractionsMessage($publication, $coordinate);
        $handler($message);
        self::assertSame([...array_column($events, 'id'), $reply->id], $projected);
        $root->setTags([['d', 'magazine'], ['a', $coordinate], ['s', 'secret']]);
        $handler($message);
        $root->setTags([['d', 'magazine'], ['a', $coordinate]]);
        $targetTombstoned = true;
        $handler($message);
    }

    public function testProductionThreadCapacityOverflowIsExplicitNotATruncatedTotal(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $comments = [];
        for ($i = 0; $i <= 10000; ++$i) {
            $comments[] = new Comment(sprintf('%064x', $i), 1111, str_repeat('c', 64), '', 100,
                [['A', $coordinate], ['K', '30023'], ['P', str_repeat('a', 64)]]);
        }
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unfold_interactions.unavailable');
        $this->hydrator(comments: $comments)->thread($this->targetForCoordinate($coordinate));
    }

    public function testRefreshScansOnlyFortyRecentLocalReplyParents(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $article = $this->article(str_repeat('a', 64), 'story', 2000, [
            'id' => str_repeat('1', 64), 'pubkey' => str_repeat('a', 64), 'kind' => 30023,
            'content' => '', 'created_at' => 2000, 'tags' => [['d', 'story']], 'sig' => str_repeat('c', 128),
        ]);
        $root = $this->event(str_repeat('2', 64), str_repeat('b', 64), 30040, '', [['d', 'magazine'], ['a', $coordinate]], 2000, str_repeat('a', 128));
        $comments = [];
        for ($i = 1; $i <= 45; ++$i) {
            $comments[] = new Comment(sprintf('%064x', $i), 1111, str_repeat('c', 64), '', $i,
                [['A', $coordinate], ['K', '30023'], ['P', str_repeat('a', 64)]]);
        }
        $nostr = $this->createMock(\App\Service\Nostr\NostrClient::class);
        $scanned = [];
        $nostr->expects(self::exactly(42))->method('getComments')->willReturnCallback(static function (string $reference) use (&$scanned, $coordinate): array {
            if ($reference !== $coordinate && $reference !== str_repeat('1', 64)) {
                $scanned[] = $reference;
            }
            return [];
        });
        $projector = $this->createMock(\App\Service\GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $signer = $this->createMock(\App\Service\Nostr\NostrSigner::class);
        $signer->expects(self::never())->method('verify');
        $deleted = $this->createMock(\App\Repository\DeletedEventRepository::class);
        $deleted->method('isSuppressed')->willReturn(false);
        $handler = new \App\MessageHandler\RefreshReaderInteractionsHandler(
            $this->hydrator(articles: [$article], comments: $comments, indexes: [$root]), $nostr, $projector,
            new \App\Service\Nostr\NostrEventVerifier($signer), $deleted,
        );
        $handler(new \App\Message\RefreshReaderInteractionsMessage('30040:' . str_repeat('b', 64) . ':magazine', $coordinate));
        self::assertSame(array_map(static fn (int $i): string => sprintf('%064x', $i), range(45, 6)), $scanned);
    }

    public function testParentLookupReservesOneDepthSlotForTheNewReply(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $rootTags = [['A', $coordinate], ['K', '30023'], ['P', str_repeat('a', 64)]];
        $comments = [];
        for ($i = 1; $i <= 100; ++$i) {
            $comments[] = new Comment(sprintf('%064x', $i), 1111, str_repeat('c', 64), '', 100 + $i,
                $i === 1 ? $rootTags : [...$rootTags, ['e', sprintf('%064x', $i - 1)], ['k', '1111'], ['p', str_repeat('c', 64)]]);
        }
        $hydrator = $this->hydrator(comments: $comments);
        $target = $this->targetForCoordinate($coordinate);
        self::assertSame(100, $hydrator->thread($target)->count);
        self::assertNotNull($hydrator->parent($target, sprintf('%064x', 99)));
        self::assertNull($hydrator->parent($target, sprintf('%064x', 100)));
    }

    public function testThreadIncludesZapsAndExcludesForeignComments(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $target = $this->targetForCoordinate($coordinate);

        $root = $this->comment(
            id: str_repeat('1', 64),
            kind: KindsEnum::COMMENTS->value,
            pubkey: str_repeat('c', 64),
            content: 'Root comment',
            createdAt: 100,
            tags: [
                ['A', $coordinate],
                ['K', '30023'],
                ['P', str_repeat('a', 64)],
            ],
        );
        $reply = $this->comment(
            id: str_repeat('2', 64),
            kind: KindsEnum::COMMENTS->value,
            pubkey: str_repeat('d', 64),
            content: 'Reply',
            createdAt: 200,
            tags: [
                ['A', $coordinate],
                ['K', '30023'],
                ['P', str_repeat('a', 64)],
                ['e', $root->id],
                ['k', '1111'],
                ['p', str_repeat('c', 64)],
            ],
        );
        $zap = $this->comment(
            id: str_repeat('3', 64),
            kind: KindsEnum::ZAP_RECEIPT->value,
            pubkey: str_repeat('e', 64),
            content: '',
            createdAt: 300,
            tags: [
                ['a', $coordinate],
                ['e', $root->id],
                ['p', str_repeat('c', 64)],
            ],
        );
        $foreign = $this->comment(
            id: str_repeat('4', 64),
            kind: KindsEnum::COMMENTS->value,
            pubkey: str_repeat('f', 64),
            content: 'Foreign',
            createdAt: 400,
            tags: [
                ['A', '30023:' . str_repeat('9', 64) . ':other'],
                ['K', '30023'],
                ['P', str_repeat('a', 64)],
            ],
        );

        $hydrator = $this->hydrator(
            articles: [$this->article(
                pubkey: str_repeat('a', 64),
                slug: 'story',
                createdAt: 2000,
                raw: [
                    'id' => str_repeat('1', 64),
                    'pubkey' => str_repeat('a', 64),
                    'kind' => KindsEnum::LONGFORM->value,
                    'content' => 'Article body',
                    'tags' => [
                        ['d', 'story'],
                        ['title', 'Story title'],
                    ],
                    'created_at' => 2000,
                    'sig' => str_repeat('c', 128),
                ],
            )],
            comments: [$root, $reply, $zap, $foreign],
            descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), KindsEnum::LONGFORM->value)],
        );

        $page = $hydrator->thread($target);

        self::assertSame(2, $page->count);
        self::assertCount(3, $page->comments);
        self::assertContainsOnlyInstancesOf(Comment::class, $page->comments);
        self::assertNull($page->nextCursor);
        self::assertSame([$zap->id, $root->id, $reply->id], array_map(static fn (Comment $comment): string => $comment->id, $page->comments));
    }

    public function testThreadCountIsTotalAcrossRootGroups(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $comments = [];
        for ($i = 0; $i < 26; ++$i) {
            $comments[] = $this->comment(
                id: sprintf('%064x', $i + 1),
                kind: KindsEnum::COMMENTS->value,
                pubkey: str_repeat('c', 64),
                content: 'Comment ' . $i,
                createdAt: 1000 + $i,
                tags: [
                    ['A', $coordinate],
                    ['K', '30023'],
                    ['P', str_repeat('a', 64)],
                ],
            );
        }

        $hydrator = $this->hydrator(
            articles: [$this->article(
                pubkey: str_repeat('a', 64),
                slug: 'story',
                createdAt: 2000,
                raw: [
                    'id' => str_repeat('1', 64),
                    'pubkey' => str_repeat('a', 64),
                    'kind' => KindsEnum::LONGFORM->value,
                    'content' => 'Article body',
                    'tags' => [
                        ['d', 'story'],
                        ['title', 'Story title'],
                    ],
                    'created_at' => 2000,
                    'sig' => str_repeat('c', 128),
                ],
            )],
            comments: $comments,
            descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), KindsEnum::LONGFORM->value)],
        );

        $page = $hydrator->thread($this->targetForCoordinate($coordinate));

        self::assertSame(26, $page->count);
        self::assertCount(26, $page->comments);
        self::assertNull($page->nextCursor);
    }

    public function testParentReturnsOnlyCommentInTargetThread(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $root = $this->comment(
            id: str_repeat('1', 64),
            kind: KindsEnum::COMMENTS->value,
            pubkey: str_repeat('c', 64),
            content: 'Root comment',
            createdAt: 100,
            tags: [
                ['A', $coordinate],
                ['K', '30023'],
                ['P', str_repeat('a', 64)],
            ],
        );
        $foreign = $this->comment(
            id: str_repeat('9', 64),
            kind: KindsEnum::COMMENTS->value,
            pubkey: str_repeat('f', 64),
            content: 'Foreign',
            createdAt: 200,
            tags: [
                ['A', '30023:' . str_repeat('9', 64) . ':other'],
                ['K', '30023'],
                ['P', str_repeat('a', 64)],
            ],
        );

        $hydrator = $this->hydrator(
            articles: [$this->article(
                pubkey: str_repeat('a', 64),
                slug: 'story',
                createdAt: 2000,
                raw: [
                    'id' => str_repeat('1', 64),
                    'pubkey' => str_repeat('a', 64),
                    'kind' => KindsEnum::LONGFORM->value,
                    'content' => 'Article body',
                    'tags' => [
                        ['d', 'story'],
                        ['title', 'Story title'],
                    ],
                    'created_at' => 2000,
                    'sig' => str_repeat('c', 128),
                ],
            )],
            comments: [$root, $foreign],
            descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), KindsEnum::LONGFORM->value)],
        );

        $target = $this->targetForCoordinate($coordinate);
        $parent = $hydrator->parent($target, $root->id);
        self::assertInstanceOf(Comment::class, $parent);
        self::assertSame($root->id, $parent->id);
        self::assertNull($hydrator->parent($target, $foreign->id));
        self::assertNull($hydrator->parent($target, str_repeat('x', 64)));
    }

    public function testStateCountsDistinctLikesAndReposts(): void
    {
        $coordinate = '30023:' . str_repeat('a', 64) . ':story';
        $readerPubkey = str_repeat('b', 64);

        $hydrator = $this->hydrator(
            articles: [$this->article(
                pubkey: str_repeat('a', 64),
                slug: 'story',
                createdAt: 2000,
                raw: [
                    'id' => str_repeat('1', 64),
                    'pubkey' => str_repeat('a', 64),
                    'kind' => KindsEnum::LONGFORM->value,
                    'content' => 'Article body',
                    'tags' => [
                        ['d', 'story'],
                        ['title', 'Story title'],
                    ],
                    'created_at' => 2000,
                    'sig' => str_repeat('c', 128),
                ],
            )],
            events: [
                $this->event(str_repeat('1', 64), str_repeat('b', 64), KindsEnum::REACTION->value, '+', [['a', $coordinate]], 10, str_repeat('1', 128)),
                $this->event(str_repeat('2', 64), str_repeat('c', 64), KindsEnum::REACTION->value, '+', [['a', $coordinate]], 11, str_repeat('2', 128)),
                $this->event(str_repeat('3', 64), str_repeat('d', 64), KindsEnum::REACTION->value, '-', [['a', $coordinate]], 12, str_repeat('3', 128)),
                $this->event(str_repeat('4', 64), str_repeat('b', 64), KindsEnum::GENERIC_REPOST->value, 'post', [['a', $coordinate]], 13, str_repeat('4', 128)),
                $this->event(str_repeat('5', 64), str_repeat('b', 64), KindsEnum::GENERIC_REPOST->value, 'post', [['a', $coordinate]], 14, str_repeat('5', 128)),
                $this->event(str_repeat('6', 64), str_repeat('d', 64), KindsEnum::GENERIC_REPOST->value, 'post', [['a', $coordinate]], 15, str_repeat('6', 128)),
                $this->event(str_repeat('7', 64), str_repeat('e', 64), 7, '+', [['a', $coordinate], ['a', '30023:' . str_repeat('9', 64) . ':foreign']], 16, str_repeat('7', 128)),
                $this->event(str_repeat('8', 64), str_repeat('e', 64), 16, '', [['a', $coordinate], ['e', str_repeat('9', 64)]], 17, str_repeat('8', 128)),
                $this->event(str_repeat('9', 64), str_repeat('e', 64), 7, '❤️', [['a', $coordinate]], 18, str_repeat('9', 128)),
                $this->event(str_repeat('a', 64), str_repeat('e', 64), 16, '', [['a', $coordinate], ['k', '1111']], 19, str_repeat('a', 128)),
            ],
            descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), KindsEnum::LONGFORM->value)],
        );

        $state = $hydrator->state($this->targetForCoordinate($coordinate), $readerPubkey);

        self::assertTrue($state->liked);
        self::assertTrue($state->reposted);
        self::assertSame(2, $state->likes);
        self::assertSame(2, $state->reposts);
    }

    private function targetForCoordinate(string $coordinate): InteractionTarget
    {
        $hydrator = $this->hydrator(
            articles: [$this->article(
                pubkey: str_repeat('a', 64),
                slug: 'story',
                createdAt: 2000,
                raw: [
                    'id' => str_repeat('1', 64),
                    'pubkey' => str_repeat('a', 64),
                    'kind' => KindsEnum::LONGFORM->value,
                    'content' => 'Article body',
                    'tags' => [
                        ['d', 'story'],
                        ['title', 'Story title'],
                    ],
                    'created_at' => 2000,
                    'sig' => str_repeat('c', 128),
                ],
            )],
            descendants: [$this->nostrEvent($coordinate, str_repeat('a', 64), KindsEnum::LONGFORM->value)],
        );

        $target = $hydrator->target('30040:' . str_repeat('b', 64) . ':magazine', $coordinate);
        self::assertInstanceOf(InteractionTarget::class, $target);

        return $target;
    }

    /**
     * @param list<Article> $articles
     * @param list<Event> $events
     * @param list<Comment> $comments
     * @param list<NostrEvent> $descendants
     */
    private function hydrator(array $articles = [], array $events = [], array $comments = [], array $descendants = [], array $contentRelays = ['wss://relay.example'], ?array $indexes = null, ?\App\Service\Nostr\NostrEventVerifier $verifier = null): InteractionHydrator
    {
        $articleRepo = $this->createMock(ArticleRepository::class);
        $articleRepo->method('findByCoordinates')->willReturnCallback(function (array $coordinates) use ($articles): array {
            $map = [];
            foreach ($articles as $article) {
                foreach ($coordinates as $coordinate) {
                    if ($this->articleCoordinate($article) === $this->coordinateFromCriteria($coordinate)) {
                        $map[$this->articleCoordinate($article)] = $article;
                    }
                }
            }

            return $map;
        });
        $articleRepo->method('findBy')->willReturnCallback(function (array $criteria) use ($articles): array {
            return array_values(array_filter($articles, fn (Article $article): bool => $this->articleMatches($article, $criteria)));
        });

        $indexes ??= $descendants === [] ? [] : [$this->event(str_repeat('f', 64), str_repeat('b', 64), 30040, '', [
            ['d', 'magazine'],
            ...array_map(static fn (NostrEvent $event): array => ['a', \DecentNewsroom\UnfoldBundle\Config\ContentReference::fromEvent($event)->coordinate], $descendants),
        ], 2000, str_repeat('a', 128))];
        // Execute the repository's actual raw-d lookup, not a graph lookup mock.
        $eventRepo = $this->getMockBuilder(EventRepository::class)->disableOriginalConstructor()
            ->onlyMethods(['findBy', 'findByCoordinates'])->getMock();
        $eventRepo->method('findByCoordinates')->willReturnCallback(function (array $coordinates) use ($events): array {
            $map = [];
            foreach ($events as $event) {
                foreach ($coordinates as $coordinate) {
                    if ($this->eventCoordinate($event) === (string) $coordinate) {
                        $map[$this->eventCoordinate($event)] = $event;
                    }
                }
            }

            return $map;
        });
        $eventRepo->method('findBy')->willReturnCallback(function (array $criteria) use ($events, $indexes): array {
            return array_values(array_filter([...$indexes, ...$events], fn (Event $event): bool => $this->eventMatches($event, $criteria)));
        });

        $commentsProvider = $this->createMock(\DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface::class);
        $commentsProvider->expects(self::never())->method('findByCoordinate');

        $treeLookup = $this->createMock(PublicationTreeLookupInterface::class);
        $treeLookup->expects(self::never())->method('findDescendants');

        $relayRegistry = $this->createMock(RelayRegistry::class);
        $relayRegistry->method('getContentRelays')->willReturn($contentRelays);
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturnCallback(static function (string $sql, array $parameters) use ($events, $comments): array {
            self::assertStringContainsString('tags @> CAST', $sql);
            self::assertNotEmpty($parameters);
            if (str_contains($sql, 'WHERE kind IN (1111,9735)')) {
                self::assertStringContainsString('LIMIT 10001', $sql);
                return array_map(static fn (Comment $comment): array => [
                    'id' => $comment->id, 'kind' => $comment->kind, 'pubkey' => $comment->pubkey,
                    'content' => $comment->content, 'created_at' => $comment->createdAt, 'tags' => json_encode($comment->tags),
                ], array_slice($comments, 0, 10001));
            }
            if (str_contains($sql, 'WHERE kind = :kind')) {
                self::assertStringContainsString('LIMIT 40', $sql);
                $events = array_slice(array_values(array_filter($events, static fn (Event $event): bool => $event->getKind() === $parameters['kind']
                    && $event->getPubkey() === $parameters['author']
                    && in_array(json_decode($parameters['identifier'], true)[0], $event->getTags(), true))), 0, 40);
            } else {
                self::assertStringContainsString('WHERE kind IN (7,16)', $sql);
                self::assertStringContainsString('LIMIT 10001', $sql);
                $events = array_values(array_filter($events, static fn (Event $event): bool => in_array($event->getKind(), [7, 16], true)));
            }
            return array_map(static fn (Event $event): array => [
                'id' => $event->getId(), 'pubkey' => $event->getPubkey(),
                'kind' => $event->getKind(), 'content' => $event->getContent(),
                'tags' => json_encode($event->getTags()), 'created_at' => $event->getCreatedAt(), 'sig' => $event->getSig(),
            ], $events);
        });

        return new InteractionHydrator(
            $commentsProvider,
            $articleRepo,
            $eventRepo,
            $treeLookup,
            $relayRegistry,
            new NullLogger(),
            $connection,
            $verifier,
        );
    }

    private function article(string $pubkey, string $slug, int $createdAt, array $raw): Article
    {
        $article = new Article();
        $article->setKind(KindsEnum::LONGFORM);
        $article->setSlug($slug);
        $article->setPubkey($pubkey);
        $article->setCreatedAt($createdAt);
        $article->setTitle($raw['title'] ?? 'Story title');
        $article->setSummary($raw['summary'] ?? 'Story summary');
        $article->setContent((string) ($raw['content'] ?? ''));
        $article->setSig((string) ($raw['sig'] ?? str_repeat('c', 128)));
        $article->setEventId((string) ($raw['id'] ?? str_repeat('1', 64)));
        $article->setPublishedAt(new DateTimeImmutable('@' . $createdAt));
        $article->setEventStatus(EventStatusEnum::PUBLISHED);
        $article->setRaw($raw);

        return $article;
    }

    private function event(string $id, string $pubkey, int $kind, string $content, array $tags, int $createdAt, string $sig): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($pubkey);
        $event->setKind($kind);
        $event->setContent($content);
        $event->setTags($tags);
        $event->setCreatedAt($createdAt);
        $event->setSig($sig);

        return $event;
    }

    private function nostrEvent(string $coordinate, string $pubkey, int $kind): NostrEvent
    {
        [$kindValue, $pubkeyValue, $slug] = explode(':', $coordinate, 3);

        return new NostrEvent(
            id: str_repeat('1', 64),
            pubkey: $pubkey,
            kind: $kind,
            content: 'Article body',
            tags: [
                ['d', $slug],
                ['title', 'Story title'],
                ['summary', 'Story summary'],
            ],
            createdAt: 2000,
            sig: str_repeat('c', 128),
        );
    }

    private function comment(string $id, int $kind, string $pubkey, string $content, int $createdAt, array $tags): Comment
    {
        return new Comment($id, $kind, $pubkey, $content, $createdAt, $tags);
    }

    private function articleCoordinate(Article $article): string
    {
        return ($article->getKind()?->value ?? 0) . ':' . strtolower((string) $article->getPubkey()) . ':' . (string) $article->getSlug();
    }

    private function eventCoordinate(Event $event): string
    {
        $slug = '';
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd' && is_string($tag[1] ?? null)) {
                $slug = $tag[1];
                break;
            }
        }

        return $event->getKind() . ':' . strtolower($event->getPubkey()) . ':' . $slug;
    }

    private function coordinateFromCriteria(array $criteria): string
    {
        return (string) ($criteria['kind'] ?? 0) . ':' . strtolower((string) ($criteria['pubkey'] ?? '')) . ':' . (string) ($criteria['slug'] ?? '');
    }

    private function articleMatches(Article $article, array $criteria): bool
    {
        if (isset($criteria['kind'])) {
            $kind = $criteria['kind'] instanceof KindsEnum ? $criteria['kind']->value : (int) $criteria['kind'];
            if ($article->getKind()?->value !== $kind) {
                return false;
            }
        }

        if (isset($criteria['pubkey']) && $article->getPubkey() !== $criteria['pubkey']) {
            return false;
        }

        if (isset($criteria['slug']) && $article->getSlug() !== $criteria['slug']) {
            return false;
        }

        return true;
    }

    private function eventMatches(Event $event, array $criteria): bool
    {
        if (isset($criteria['kind']) && $event->getKind() !== ((int) $criteria['kind'])) {
            return false;
        }

        if (isset($criteria['pubkey']) && $event->getPubkey() !== $criteria['pubkey']) {
            return false;
        }

        return true;
    }
}
