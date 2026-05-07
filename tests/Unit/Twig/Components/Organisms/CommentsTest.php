<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Organisms;

use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
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
}


