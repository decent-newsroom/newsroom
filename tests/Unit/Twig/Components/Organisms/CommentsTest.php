<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Organisms;

use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\DispatchThrottle;
use App\Service\Nostr\NostrLinkParser;
use App\Twig\Components\Organisms\Comments;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class CommentsTest extends TestCase
{
    public function testLoadCommentsNormalizesProfilesFromMercurePayload(): void
    {
        $component = new Comments(
            new NostrLinkParser($this->createMock(LoggerInterface::class)),
            $this->createMock(RedisCacheService::class),
            $this->createMock(EventRepository::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(DispatchThrottle::class),
        );

        $rootAuthor = str_repeat('a', 64);
        $replyAuthor = str_repeat('b', 64);
        $replyId = str_repeat('c', 64);
        $rootId = str_repeat('d', 64);

        $component->loadComments(json_encode([
            'comments' => [
                [
                    'id' => $rootId,
                    'kind' => 1111,
                    'pubkey' => $rootAuthor,
                    'content' => 'Root comment',
                    'created_at' => 100,
                    'tags' => [
                        ['A', '30023:' . $rootAuthor . ':article'],
                    ],
                    'sig' => str_repeat('e', 128),
                ],
                [
                    'id' => $replyId,
                    'kind' => 1111,
                    'pubkey' => $replyAuthor,
                    'content' => 'Reply comment',
                    'created_at' => 101,
                    'tags' => [
                        ['K', '30023'],
                        ['A', '30023:' . $rootAuthor . ':article'],
                        ['P', $rootAuthor],
                        ['k', '1111'],
                        ['e', $rootId],
                        ['p', $rootAuthor],
                    ],
                    'sig' => str_repeat('f', 128),
                ],
            ],
            'profiles' => [
                $rootAuthor => [
                    'display_name' => 'Root Name',
                    'name' => 'root',
                ],
                $replyAuthor => [
                    'display_name' => 'Reply Name',
                    'name' => 'reply',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(\stdClass::class, $component->authorsMetadata[$rootAuthor]);
        self::assertSame('Root Name', $component->authorsMetadata[$rootAuthor]->display_name);
        self::assertSame(['Root Name'], $component->replyingTo[$replyId]);
        self::assertSame($rootAuthor, $component->parentPreview[$replyId]['pubkey']);
        self::assertFalse($component->loading);
    }

    public function testLoadCommentsAggregatesReactionsAndRemovesThemFromList(): void
    {
        $component = new Comments(
            new NostrLinkParser($this->createMock(LoggerInterface::class)),
            $this->createMock(RedisCacheService::class),
            $this->createMock(EventRepository::class),
            $this->createMock(MessageBusInterface::class),
            $this->createMock(DispatchThrottle::class),
        );

        $articleAuthor = str_repeat('a', 64);
        $coordinate = '30023:' . $articleAuthor . ':article';
        $component->current = $coordinate;

        $component->loadComments(json_encode([
            'comments' => [
                $this->commentEvent(str_repeat('1', 64), str_repeat('b', 64), 'Visible comment', $coordinate),
                $this->reactionEvent(str_repeat('2', 64), str_repeat('c', 64), '+', $coordinate),
                $this->reactionEvent(str_repeat('3', 64), str_repeat('c', 64), '+', $coordinate),
                $this->reactionEvent(str_repeat('4', 64), str_repeat('d', 64), '', $coordinate),
                $this->reactionEvent(str_repeat('5', 64), str_repeat('e', 64), '-', $coordinate),
                $this->reactionEvent(str_repeat('6', 64), str_repeat('f', 64), '🔥', $coordinate),
                $this->reactionEvent(str_repeat('7', 64), str_repeat('f', 64), '🔥', $coordinate),
                $this->reactionEvent(
                    str_repeat('8', 64),
                    str_repeat('8', 64),
                    ':party:',
                    $coordinate,
                    [['emoji', 'party', 'https://example.com/party.png']]
                ),
                $this->reactionEvent(
                    str_repeat('9', 64),
                    str_repeat('9', 64),
                    ':party:',
                    $coordinate,
                    [['emoji', 'party', 'https://example.com/party.png']]
                ),
                $this->reactionEvent(str_repeat('0', 64), str_repeat('1', 64), '+', '30023:' . str_repeat('2', 64) . ':other'),
            ],
            'profiles' => [],
        ], JSON_THROW_ON_ERROR));

        self::assertCount(1, $component->list);
        self::assertSame(1111, $component->list[0]['kind']);

        self::assertArrayHasKey('+', $component->reactions);
        self::assertTrue($component->reactions['+']['isLike']);
        self::assertSame(2, $component->reactions['+']['count']);
        self::assertNull($component->reactions['+']['emoji']);

        self::assertArrayHasKey('🔥', $component->reactions);
        self::assertFalse($component->reactions['🔥']['isLike']);
        self::assertSame('🔥', $component->reactions['🔥']['emoji']);
        self::assertSame(1, $component->reactions['🔥']['count']);

        self::assertArrayHasKey(':party:', $component->reactions);
        self::assertSame(2, $component->reactions[':party:']['count']);
        self::assertSame([
            'shortcode' => 'party',
            'url' => 'https://example.com/party.png',
        ], $component->reactions[':party:']['custom']);

        self::assertArrayNotHasKey('-', $component->reactions);
        self::assertFalse($component->loading);
    }

    /**
     * @return array{id: string, kind: int, pubkey: string, content: string, created_at: int, tags: array<int, array<int, string>>, sig: string}
     */
    private function commentEvent(string $id, string $pubkey, string $content, string $coordinate): array
    {
        return [
            'id' => $id,
            'kind' => 1111,
            'pubkey' => $pubkey,
            'content' => $content,
            'created_at' => 100,
            'tags' => [['A', $coordinate]],
            'sig' => str_repeat('a', 128),
        ];
    }

    /**
     * @param array<int, array<int, string>> $extraTags
     * @return array{id: string, kind: int, pubkey: string, content: string, created_at: int, tags: array<int, array<int, string>>, sig: string}
     */
    private function reactionEvent(string $id, string $pubkey, string $content, string $coordinate, array $extraTags = []): array
    {
        return [
            'id' => $id,
            'kind' => 7,
            'pubkey' => $pubkey,
            'content' => $content,
            'created_at' => 101,
            'tags' => array_merge([['a', $coordinate]], $extraTags),
            'sig' => str_repeat('b', 128),
        ];
    }
}
