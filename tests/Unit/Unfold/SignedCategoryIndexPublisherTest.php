<?php

declare(strict_types=1);

namespace App\Tests\Unit\Unfold;

use App\Entity\Event;
use App\Service\GenericEventProjector;
use App\Service\Graph\RecordIdentityService;
use App\Service\Graph\ReferenceParserService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrSigner;
use App\Unfold\SignedCategoryIndexPublisher;
use DecentNewsroom\UnfoldBundle\Config\ContentReference;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\PublicationIndexConflictException;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SignedCategoryIndexPublisherTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ROOT = '30040:' . self::OWNER . ':root';
    private const CHILD = '30040:' . self::OWNER . ':child';
    private const LEAF = '30817:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:Spec';
    private const BASE = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

    /** @dataProvider relayOutcomes */
    public function testCommitBeforeRelaysAndReportsPartialFailure(array $relays, bool $published, bool $complete): void
    {
        $inside = false;
        $connection = $this->connection($this->child(), $inside);
        $connection->expects(self::exactly(4))->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::BASE, self::ID, 1);
        $projector = $this->createMock(GenericEventProjector::class);
        $stored = new Event();
        $stored->setId(self::ID);
        $projector->expects(self::once())->method('projectEventFromNostrEvent')->with(self::anything(), 'unfold-category', true)->willReturn($stored);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('publishEvent')->willReturnCallback(static function () use (&$inside, $relays): array {
            self::assertFalse($inside);
            return $relays;
        });
        $result = $this->publisher($connection, $projector, $client)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
        self::assertTrue($result['local_commit']);
        self::assertSame($published, $result['published']);
        self::assertSame($complete, $result['relay_complete']);
        self::assertSame(!$complete, $result['retryable']);
        self::assertCount(count($relays), $result['relay_results']);
    }

    public static function relayOutcomes(): array
    {
        return [
            'all success' => [['wss://one.example' => true], true, true],
            'partial' => [['wss://one.example' => true, 'wss://two.example' => false], true, false],
            'all failed' => [['wss://one.example' => false], false, false],
            'no relay' => [[], false, false],
        ];
    }

    public function testSameSignedEventRetrySkipsProjectionAndLeafFetch(): void
    {
        $inside = false;
        $connection = $this->connection($this->signed(), $inside);
        $connection->expects(self::exactly(2))->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::ID);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::once())->method('publishEvent')->willReturn(['wss://relay.example' => true]);
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::never())->method('findByCoordinate');
        self::assertTrue($this->publisher($connection, $projector, $client, $events)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add')['relay_complete']);
    }

    public function testSupersededSignedEventCannotBeRebroadcast(): void
    {
        $inside = false;
        $child = $this->child();
        $child['id'] = str_repeat('f', 64);
        // The persisted event identity, not the caller's base, governs CAS.
        $connection = $this->connection($child, $inside);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), str_repeat('f', 64));
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $this->expectExceptionObject(new PublicationIndexConflictException('unfold_category.stale'));
        $this->publisher($connection, $projector, $client)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    public function testRequiresExistingLocalProjection(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->method('fetchOne')->willReturn(false);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $this->expectExceptionObject(new PublicationIndexConflictException('unfold_category.refresh_required'));
        $this->publisher($connection, $projector, $client)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    /** @dataProvider tamperedEvents */
    public function testRejectsUnauthorizedSignedDelta(array $changes): void
    {
        $inside = false;
        $connection = $this->connection($this->child(), $inside);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::BASE);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $this->expectException(\InvalidArgumentException::class);
        $this->publisher($connection, $projector, $client)->publish([...$this->signed(), ...$changes], self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    public static function tamperedEvents(): array
    {
        return [
            'body' => [['content' => 'rewritten']],
            'opaque tags' => [['tags' => [['d', 'child'], ['a', self::LEAF]]]],
            'future' => [['created_at' => PHP_INT_MAX]],
            'old timestamp' => [['created_at' => 123]],
            'foreign owner' => [['pubkey' => str_repeat('b', 64)]],
            'root instead of child' => [['tags' => [['d', 'root'], ['a', self::LEAF]]]],
        ];
    }

    public function testGraphProjectionMustBeComplete(): void
    {
        $inside = false;
        $connection = $this->connection($this->child(), $inside);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::BASE, self::ID, 0);
        $stored = new Event();
        $stored->setId(self::ID);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->method('projectEventFromNostrEvent')->willReturn($stored);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::once())->method('resetManager');
        $this->expectExceptionObject(new PublicationIndexConflictException('unfold_category.projection_failed'));
        $this->publisher($connection, $projector, $client, registry: $registry)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    public function testScopedLeafCannotBeAdded(): void
    {
        $inside = false;
        $connection = $this->connection($this->child(), $inside);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::BASE);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->willReturn(new NostrEvent('leaf', str_repeat('b', 64), 30817, 'private', [['d', 'Spec'], ['s', 'restricted']], 123, ''));
        $this->expectExceptionObject(new \InvalidArgumentException('unfold_category.scoped'));
        $this->publisher($connection, $projector, $this->createMock(NostrClient::class), $events)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    public function testInvalidSignatureNeverLocksOrPublishes(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $signer = $this->createMock(NostrSigner::class);
        $signer->method('verify')->willReturn(false);
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $publisher = new SignedCategoryIndexPublisher(
            new NostrEventVerifier($signer), $this->createMock(GenericEventProjector::class), $client,
            $connection, $this->createMock(EventReadGatewayInterface::class), new NullLogger(),
            new ReferenceParserService(new RecordIdentityService()),
            $this->createMock(ManagerRegistry::class),
        );
        $this->expectExceptionObject(new \InvalidArgumentException('unfold_category.invalid_signature'));
        $publisher->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    public function testUnresolvedRemovalNeedsNoLeafFetch(): void
    {
        $inside = false;
        $child = $this->child();
        $child['tags'][] = ['a', self::LEAF, 'wss://relay.example', 'opaque-marker'];
        $connection = $this->connection($child, $inside);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::BASE, self::ID, 0);
        $projector = $this->createMock(GenericEventProjector::class);
        $stored = new Event();
        $stored->setId(self::ID);
        $projector->expects(self::once())->method('projectEventFromNostrEvent')->willReturn($stored);
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->expects(self::never())->method('findByCoordinate');
        $client = $this->createMock(NostrClient::class);
        $client->method('publishEvent')->willReturn([]);
        $signed = [...$this->signed(), 'tags' => $this->child()['tags']];
        self::assertTrue($this->publisher($connection, $projector, $client, $events)->publish($signed, self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'remove')['local_commit']);
    }

    public function testDetachedChildRejectedUnderRootLockBeforeChildLookup(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static fn (callable $callback) => $callback());
        $connection->expects(self::once())->method('fetchOne')->willReturn(str_repeat('d', 64));
        $connection->expects(self::once())->method('fetchAssociative')->willReturn([
            'id' => str_repeat('d', 64), 'pubkey' => self::OWNER, 'kind' => 30040, 'content' => '',
            'tags' => [['d', 'root']], 'created_at' => 122, 'sig' => '',
        ]);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $this->expectExceptionObject(new \InvalidArgumentException('unfold_category.detached'));
        $this->publisher($connection, $projector, $client)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    /** @dataProvider malformedTags */
    public function testRejectsMalformedLocalSnapshotRatherThanSigningLossyTags(array $tags): void
    {
        $inside = false;
        $child = [...$this->child(), 'tags' => $tags];
        $connection = $this->connection($child, $inside);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(str_repeat('d', 64), self::BASE);
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $this->expectExceptionObject(new \InvalidArgumentException('unfold_category.invalid'));
        $this->publisher($connection, $projector, $client)->publish($this->signed(), self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    /** @dataProvider malformedTags */
    public function testRejectsMalformedSignedTagsBeforeVerificationOrLocks(array $tags): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::never())->method('transactional');
        $projector = $this->createMock(GenericEventProjector::class);
        $projector->expects(self::never())->method('projectEventFromNostrEvent');
        $client = $this->createMock(NostrClient::class);
        $client->expects(self::never())->method('publishEvent');
        $this->expectExceptionObject(new \InvalidArgumentException('unfold_category.invalid'));
        $this->publisher($connection, $projector, $client)->publish([...$this->signed(), 'tags' => $tags], self::ROOT, self::CHILD, self::BASE, ContentReference::fromInput(self::LEAF), 'add');
    }

    public static function malformedTags(): array
    {
        return [
            'number coerced to string' => [[['d', 'child'], ['x', 123]]],
            'boolean coerced to string' => [[['d', 'child'], ['x', true]]],
            'null coerced to empty string' => [[['d', 'child'], ['x', null]]],
            'malformed tag omitted' => [[['d', 'child'], 'opaque']],
            'non-list tag' => [[['d', 'child'], ['name' => 'x', 'value' => 'opaque']]],
            'empty tag' => [[['d', 'child'], []]],
            'non-list collection' => [['first' => ['d', 'child']]],
        ];
    }

    private function connection(array $child, bool &$inside): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('transactional')->willReturnCallback(static function (callable $callback) use (&$inside) {
            $inside = true;
            try { return $callback(); } finally { $inside = false; }
        });
        $connection->method('fetchAssociative')->willReturnOnConsecutiveCalls([
            'id' => str_repeat('d', 64), 'pubkey' => self::OWNER, 'kind' => 30040, 'content' => '',
            'tags' => json_encode([['d', 'root'], ['a', self::CHILD]]), 'created_at' => 122, 'sig' => '',
        ], $child);
        return $connection;
    }

    private function child(): array
    {
        return ['id' => self::BASE, 'pubkey' => self::OWNER, 'kind' => 30040, 'content' => 'opaque content', 'tags' => [['d', 'child'], ['x', 'opaque', 'order']], 'created_at' => 123, 'sig' => 'old'];
    }

    private function signed(): array
    {
        return [...$this->child(), 'id' => self::ID, 'created_at' => 124, 'sig' => str_repeat('f', 128), 'tags' => [...$this->child()['tags'], ['a', self::LEAF]]];
    }

    private function publisher(Connection $connection, GenericEventProjector $projector, NostrClient $client, ?EventReadGatewayInterface $events = null, ?ManagerRegistry $registry = null): SignedCategoryIndexPublisher
    {
        $signer = $this->createMock(NostrSigner::class);
        $signer->method('verify')->willReturn(true);
        if ($events === null) {
            $events = $this->createMock(EventReadGatewayInterface::class);
            $events->method('findByCoordinate')->willReturn(new NostrEvent('leaf', str_repeat('b', 64), 30817, 'public', [['d', 'Spec']], 123, ''));
        }
        return new SignedCategoryIndexPublisher(new NostrEventVerifier($signer), $projector, $client, $connection, $events, new NullLogger(), new ReferenceParserService(new RecordIdentityService()), $registry ?? $this->createMock(ManagerRegistry::class));
    }
}
