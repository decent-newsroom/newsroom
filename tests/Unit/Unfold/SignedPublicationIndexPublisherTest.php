<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Entity\Event;
use App\Service\GenericEventProjector;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrSigner;
use App\Unfold\SignedPublicationIndexPublisher;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SignedPublicationIndexPublisherTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const COORDINATE = '30040:' . self::OWNER . ':magazine';
    private const EVENT_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testPublishesACommittedInitialRootWithoutChangingAboutSettings(): void
    {
        $connection = $this->createMock(Connection::class);
        $insideTransaction = false;
        $connection->expects(self::once())->method('transactional')
            ->willReturnCallback(function (callable $callback) use (&$insideTransaction): mixed {
                $insideTransaction = true;
                try {
                    return $callback();
                } finally {
                    $insideTransaction = false;
                }
            });
        $queries = [];
        $connection->expects(self::exactly(2))->method('fetchOne')
            ->willReturnCallback(function (string $sql, array $params) use (&$queries): string|false {
                $queries[] = [$sql, $params];

                return count($queries) === 1 ? false : self::EVENT_ID;
            });

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())->method('projectEventFromNostrEvent')->with(
            self::callback(static fn (object $event): bool => $event->id === self::EVENT_ID
                && $event->kind === 30040
                && $event->pubkey === self::OWNER
                && $event->tags === [['d', 'magazine']]),
            'unfold-root',
        )->willReturn($this->storedEvent());

        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('publishEvent')->with(
            self::isInstanceOf(\Innis\Nostr\Core\Domain\Entity\Event::class),
            [],
            10,
        )->willReturnCallback(function () use (&$insideTransaction): array {
            self::assertFalse($insideTransaction, 'Relays must be called after the transaction commits.');

            return ['wss://relay.example' => true];
        });

        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->expects(self::never())->method('get');
        $settings->expects(self::never())->method('savePresentation');

        $result = $this->publisher($connection, $projector, $client, $settings)->publishRoot(
            $this->signedEvent(),
            self::COORDINATE,
        );

        self::assertSame([
            'event_id' => self::EVENT_ID,
            'published' => true,
            'relay_results' => ['wss://relay.example' => ['ok' => true]],
        ], $result);
        self::assertStringContainsString('FOR UPDATE', $queries[0][0]);
        self::assertSame(['coordinate' => self::COORDINATE], $queries[0][1]);
        self::assertSame(['coordinate' => self::COORDINATE], $queries[1][1]);
    }

    public function testRejectsAnExistingRootBeforeProjectingOrPublishing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $connection->expects(self::once())->method('fetchOne')
            ->willReturn(self::EVENT_ID);

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');

        $this->expectExceptionObject(new PublicationIndexConflictException('Magazine index already exists.'));
        $this->publisher($connection, $projector, $client)->publishRoot($this->signedEvent(), self::COORDINATE);
    }

    /**
     * @dataProvider invalidRootEvents
     */
    public function testRejectsEventsThatDoNotMatchThePublicationCoordinate(array $event): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');

        $this->expectException(\InvalidArgumentException::class);
        $this->publisher($connection, $projector, $client)->publishRoot($event, self::COORDINATE);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRootEvents(): iterable
    {
        yield 'wrong kind' => [[
            ...self::signedEventPayload(),
            'kind' => 30023,
        ]];
        yield 'different owner' => [[
            ...self::signedEventPayload(),
            'pubkey' => str_repeat('c', 64),
        ]];
        yield 'different identifier' => [[
            ...self::signedEventPayload(),
            'tags' => [['d', 'other']],
        ]];
        yield 'missing identifier' => [[
            ...self::signedEventPayload(),
            'tags' => [['title', 'Magazine']],
        ]];
        yield 'ambiguous identifiers' => [[
            ...self::signedEventPayload(),
            'tags' => [['d', 'magazine'], ['d', 'other']],
        ]];
        yield 'malformed and valid identifiers' => [[
            ...self::signedEventPayload(),
            'tags' => [['d'], ['d', 'magazine']],
        ]];
    }

    public function testRejectsInvalidSignaturesBeforeOpeningTheTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $projector = $this->createMock(GenericEventProjector::class);
        $client = $this->createMock(NostrClient::class);

        $this->expectExceptionObject(new \InvalidArgumentException('Invalid index signature.'));
        $this->publisher($connection, $projector, $client, signatureValid: false)
            ->publishRoot($this->signedEvent(), self::COORDINATE);
    }

    public function testRejectsAProjectedRootThatDidNotBecomeCurrentBeforePublishing(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('transactional')
            ->willReturnCallback(static fn (callable $callback): mixed => $callback());
        $connection->expects(self::exactly(2))->method('fetchOne')
            ->willReturnOnConsecutiveCalls(false, str_repeat('d', 64));

        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::once())->method('projectEventFromNostrEvent')
            ->willReturn($this->storedEvent());
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');

        $this->expectExceptionObject(new PublicationIndexConflictException('Signed magazine index did not become current.'));
        $this->publisher($connection, $projector, $client)->publishRoot($this->signedEvent(), self::COORDINATE);
    }

    /** @return array<string, mixed> */
    private function signedEvent(): array
    {
        return self::signedEventPayload();
    }

    /** @return array<string, mixed> */
    private static function signedEventPayload(): array
    {
        return [
            'id' => self::EVENT_ID,
            'pubkey' => self::OWNER,
            'created_at' => 1_700_000_000,
            'kind' => 30040,
            'tags' => [['d', 'magazine']],
            'content' => '',
            'sig' => str_repeat('e', 128),
        ];
    }

    private function storedEvent(): Event
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);

        return $event;
    }

    private function publisher(
        Connection $connection,
        GenericEventProjector $projector,
        NostrClient $client,
        ?PublicationSettingsManager $settings = null,
        bool $signatureValid = true,
    ): SignedPublicationIndexPublisher {
        $signer = $this->createMock(NostrSigner::class);
        $signer->expects(self::once())->method('verify')->willReturn($signatureValid);

        return new SignedPublicationIndexPublisher(
            new NostrEventVerifier($signer),
            $projector,
            $client,
            $settings ?? $this->createMock(PublicationSettingsManager::class),
            $connection,
            $this->createMock(LoggerInterface::class),
        );
    }
}
