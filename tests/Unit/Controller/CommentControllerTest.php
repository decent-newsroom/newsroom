<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\CommentController;
use App\Service\Cache\RedisCacheService;
use App\Service\CommentEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrSigner;
use App\Service\Nostr\RelayPublishResult;
use App\Service\Nostr\UserRelayListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

final class CommentControllerTest extends TestCase
{
    public function testSuccessfulPublishProjectsCommentAndInvalidatesCachedPayload(): void
    {
        $commenterPubkey = str_repeat('a', 64);
        $articleAuthorPubkey = str_repeat('b', 64);
        $coordinate = '30023:' . $articleAuthorPubkey . ':article';
        $event = [
            'id' => str_repeat('c', 64),
            'pubkey' => $commenterPubkey,
            'created_at' => 1_700_000_000,
            'kind' => 1111,
            'tags' => [['A', $coordinate], ['P', $articleAuthorPubkey]],
            'content' => 'A reply',
            'sig' => str_repeat('d', 128),
        ];

        $signer = $this->createMock(NostrSigner::class);
        $signer->expects($this->once())->method('verify')->willReturn(true);

        $nostrClient = $this->createMock(NostrClient::class);
        $nostrClient->expects($this->once())
            ->method('publishEvent')
            ->willReturn(['wss://relay.example' => new RelayPublishResult(true)]);

        $relayLists = $this->createMock(UserRelayListService::class);
        $relayLists->expects($this->exactly(2))
            ->method('getRelaysForPublishing')
            ->willReturn(['wss://relay.example']);

        $projector = $this->createMock(CommentEventProjector::class);
        $projector->expects($this->once())
            ->method('projectCommentFromEvent')
            ->with($this->callback(static function (object $published) use ($event): bool {
                return $published->id === $event['id'];
            }));
        $projector->expects($this->once())->method('flush');

        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects($this->once())
            ->method('invalidateCommentsPayload')
            ->with($coordinate);

        $controller = new CommentController(
            $nostrClient,
            $relayLists,
            new NostrEventVerifier($signer),
            $projector,
            $cache,
            $this->createMock(LoggerInterface::class),
        );

        $response = $controller->publish(new Request(content: json_encode(['event' => $event, 'formData' => []], JSON_THROW_ON_ERROR)));
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('ok', $payload['status']);
        self::assertSame(1, $payload['relays']['success']);
    }
}
