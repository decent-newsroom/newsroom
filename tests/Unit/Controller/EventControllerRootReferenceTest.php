<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\EventController;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\ArticleEventProjector;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Reader\ContentAuthorAccessPolicy;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EventControllerRootReferenceTest extends TestCase
{
    private const RELAY = 'WSS://Relay.Example/CaseSensitive';

    /**
     * @dataProvider rootReferenceTags
     * @param list<string> $tag
     */
    public function testRootLookupReceivesTheOriginalRelayHint(array $tag): void
    {
        $rootId = str_repeat('b', 64);
        $event = (object) [
            'id' => str_repeat('a', 64),
            'kind' => KindsEnum::TEXT_NOTE->value,
            'tags' => [$tag],
        ];
        $repository = $this->createMock(EventRepository::class);
        $repository->expects(self::once())->method('findById')->with($rootId)->willReturn(null);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('getEventById')->with($rootId, [self::RELAY])->willReturn(null);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');

        $controller = new EventController(
            $this->createMock(ArticleEventProjector::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
        );
        $method = new \ReflectionMethod($controller, 'resolveRootOpEvent');

        self::assertNull($method->invoke($controller, $event, $repository, $client, $projector, $logger));
    }

    /** @return array<string, array{list<string>}> */
    public static function rootReferenceTags(): array
    {
        $rootId = str_repeat('b', 64);

        return [
            'standard marker position' => [['e', $rootId, self::RELAY, 'root']],
            'compatible marker position' => [['e', $rootId, 'root', self::RELAY]],
        ];
    }
}
