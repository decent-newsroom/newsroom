<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\ArticleFetchService;
use App\Service\Nostr\MediaEventService;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrRequestExecutor;
use App\Service\Nostr\RelayEndpoint;
use App\Service\Nostr\RelayQueryRequest;
use App\Service\Nostr\RelayQueryResult;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\RelaySet;
use App\Service\Nostr\RelaySetFactory;
use App\Service\Nostr\SocialEventService;
use App\Service\Nostr\UserProfileService;
use App\Service\Nostr\UserRelayListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class NostrClientLookupTest extends TestCase
{
    public function testLocalLookupUsesOneBudgetedSubscriptionWithoutDiscoveringRelays(): void
    {
        $url = 'ws://strfry:7777';
        $filters = [['ids' => [str_repeat('a', 64)]], ['kinds' => [30040], '#d' => ['book']]];
        $registry = $this->createMock(RelayRegistry::class);
        $registry->expects(self::once())->method('getLocalRelay')->willReturn($url);
        $factory = $this->createMock(RelaySetFactory::class);
        $factory->expects(self::once())->method('fromUrls')->with([$url])->willReturn(new RelaySet([new RelayEndpoint($url)]));
        $factory->expects(self::never())->method('getDefault');
        $executor = $this->createMock(NostrRequestExecutor::class);
        $executor->expects(self::once())->method('execute')->with(self::callback(
            static fn (RelayQueryRequest $request): bool => $request->getRelaySet()->getUrls() === [$url]
                && $request->getFilters() === $filters && $request->getDirectBudget() === 2,
        ))->willReturn([]);
        $relays = $this->createMock(UserRelayListService::class);
        $relays->expects(self::never())->method('getTopRelaysForAuthor');
        $client = new NostrClient(
            new NullLogger(), $registry, $relays, $factory, $executor,
            $this->createStub(ArticleFetchService::class), $this->createStub(MediaEventService::class),
            $this->createStub(SocialEventService::class), $this->createStub(UserProfileService::class),
        );

        self::assertSame([], $client->getLocalEvents($filters));
    }

    /** @dataProvider coordinateRefreshCases */
    public function testCoordinateRefreshChecksAuthorRelaysDespiteLocalHit(bool $refresh, int $timestamp, string $id, bool $replacementWins): void
    {
        $pubkey = str_repeat('a', 64);
        $coordinate = '30040:' . $pubkey . ':book';
        $local = (object) [
            'id' => str_repeat('b', 64), 'pubkey' => $pubkey, 'kind' => 30040,
            'created_at' => 20, 'tags' => [['d', 'book']],
        ];
        $remote = clone $local;
        $remote->id = $id;
        $remote->created_at = $timestamp;
        $relays = $this->createMock(UserRelayListService::class);
        $relays->expects($refresh ? self::once() : self::never())->method('getTopRelaysForAuthor')
            ->with($pubkey)->willReturn(['wss://author.example']);
        $factory = $this->createMock(RelaySetFactory::class);
        $sets = [];
        $factory->method('fromUrls')->willReturnCallback(static function (array $urls) use (&$sets): RelaySet {
            $sets[] = $urls;
            return new RelaySet();
        });
        $executor = $this->createMock(NostrRequestExecutor::class);
        $responses = [[$local], [$remote]];
        $executor->expects(self::exactly($refresh ? 2 : 1))->method('fetch')
            ->with([30040], ['authors' => [$pubkey], 'tag' => ['#d', ['book']]], self::isInstanceOf(RelaySet::class))
            ->willReturnCallback(static function () use (&$responses): array {
                return array_shift($responses);
            });
        $client = new NostrClient(
            new NullLogger(), $this->createMock(RelayRegistry::class), $relays, $factory, $executor,
            $this->createMock(ArticleFetchService::class), $this->createMock(MediaEventService::class),
            $this->createMock(SocialEventService::class), $this->createMock(UserProfileService::class),
            'ws://strfry:7777',
        );
        self::assertSame([$coordinate => $replacementWins ? $remote : $local], $client->getEventsByCoordinates([$coordinate], [], $refresh));
        self::assertSame($refresh
            ? [['ws://strfry:7777'], ['wss://author.example']]
            : [['ws://strfry:7777']], $sets);
    }

    public static function coordinateRefreshCases(): iterable
    {
        yield 'default still uses local hit' => [false, 21, str_repeat('c', 64), false];
        yield 'refresh finds replacement' => [true, 21, str_repeat('c', 64), true];
        yield 'refresh retains newer local' => [true, 19, str_repeat('c', 64), false];
        yield 'tie prefers lower id' => [true, 20, str_repeat('a', 64), true];
        yield 'tie rejects higher id' => [true, 20, str_repeat('c', 64), false];
    }

    /** @dataProvider timedOutLookups */
    public function testStrictLookupThrowsWhenNoEventWasFoundAndAnyQueryTimedOut(array $outcomes, bool $hintOnly): void
    {
        $client = $this->client($outcomes);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nostr coordinate lookup timed out.');
        $client->getEventByNaddr(
            $this->coordinate(),
            hintOnly: $hintOnly,
            allowRelayListNetworkFetch: false,
            throwOnFailure: true,
        );
    }

    public static function timedOutLookups(): iterable
    {
        yield 'primary timeout' => [['timeout', 'empty'], false];
        yield 'fallback timeout' => [['empty', 'timeout'], false];
        yield 'both timeout' => [['timeout', 'timeout'], false];
        yield 'hint-only timeout' => [['timeout'], true];
    }

    /** @dataProvider cleanEmptyLookups */
    public function testStrictLookupStillReturnsNullForCleanEmptyResults(array $outcomes, bool $hintOnly): void
    {
        self::assertNull($this->client($outcomes)->getEventByNaddr(
            $this->coordinate(),
            hintOnly: $hintOnly,
            allowRelayListNetworkFetch: false,
            throwOnFailure: true,
        ));
    }

    public static function cleanEmptyLookups(): iterable
    {
        yield 'normal lookup' => [['empty', 'empty'], false];
        yield 'hint-only lookup' => [['empty'], true];
    }

    public function testFallbackEventWinsOverPrimaryTimeout(): void
    {
        $event = (object) ['id' => 'found-event'];

        self::assertSame($event, $this->client(['timeout', $event])->getEventByNaddr(
            $this->coordinate(),
            allowRelayListNetworkFetch: false,
            throwOnFailure: true,
        ));
    }

    public function testDuplicateFallbackRelaysAreNotQueriedAgain(): void
    {
        self::assertNull($this->client(['timeout'], sameRelaySets: true)->getEventByNaddr(
            $this->coordinate(),
            allowRelayListNetworkFetch: false,
        ));
    }

    public function testHintOnlyLookupDoesNotAddTheLocalRelay(): void
    {
        $fromUrlsCalls = [];

        self::assertNull($this->client(
            ['empty'],
            addLocalRelay: true,
            fromUrlsCalls: $fromUrlsCalls,
        )->getEventByNaddr($this->coordinate(), hintOnly: true));

        self::assertSame([['wss://relay.example.test']], $fromUrlsCalls);
    }

    public function testExistingCallersKeepNullOnTimeout(): void
    {
        self::assertNull($this->client(['timeout', 'timeout'])->getEventByNaddr($this->coordinate()));
    }

    /** @dataProvider failedLookups */
    public function testStrictLookupDistinguishesTransportFailureFromMissingEvents(array $outcomes, bool $hintOnly): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Nostr coordinate lookup failed.');
        $this->client($outcomes)->getEventByNaddr($this->coordinate(), hintOnly: $hintOnly,
            allowRelayListNetworkFetch: false, throwOnFailure: true);
    }

    public static function failedLookups(): iterable
    {
        yield 'connection refused' => [['error', 'empty'], false];
        yield 'fallback failure' => [['empty', 'error'], false];
        yield 'AUTH denied' => [['closed', 'empty'], false];
        yield 'incomplete response' => [['incomplete', 'empty'], false];
        yield 'hint-only failure' => [['error'], true];
    }

    public function testFallbackEventWinsOverTransportFailureAndLegacyCallersStillReturnNull(): void
    {
        $event = (object) ['id' => 'found'];
        self::assertSame($event, $this->client(['error', $event])->getEventByNaddr(
            $this->coordinate(), throwOnFailure: true));
        self::assertNull($this->client(['error', 'error'])->getEventByNaddr($this->coordinate()));
    }

    private function coordinate(): array
    {
        return ['kind' => 30040, 'pubkey' => str_repeat('a', 64), 'identifier' => 'edition', 'relays' => ['wss://relay.example.test']];
    }

    private function client(
        array $outcomes,
        bool $sameRelaySets = false,
        bool $addLocalRelay = false,
        ?array &$fromUrlsCalls = null,
    ): NostrClient
    {
        $primarySet = new RelaySet([new RelayEndpoint('wss://relay.example.test')]);
        $fallbackSet = new RelaySet([new RelayEndpoint('wss://fallback.example.test')]);
        $registry = $this->createMock(RelayRegistry::class);
        $registry->method('ensureLocalRelayInList')->willReturnCallback(
            static fn(array $urls): array => $addLocalRelay
                ? [...$urls, 'ws://strfry:7777']
                : $urls
        );
        $userRelays = $this->createMock(UserRelayListService::class);
        $userRelays->method('getRelaysForEventLookupCacheOrDb')->willReturn(['wss://relay.example.test']);
        $factory = $this->createMock(RelaySetFactory::class);
        $factory->method('fromUrls')->willReturnCallback(
            static function (array $urls) use ($fallbackSet, $primarySet, &$fromUrlsCalls): RelaySet {
                if ($fromUrlsCalls !== null) {
                    $fromUrlsCalls[] = $urls;
                }

                return in_array('wss://fallback.example.test', $urls, true)
                    ? $fallbackSet
                    : $primarySet;
            }
        );
        $factory->method('getDefault')->willReturn($sameRelaySets ? $primarySet : $fallbackSet);
        $factory->method('forAuthorWithFallback')->willReturn($primarySet);
        $executor = $this->createMock(NostrRequestExecutor::class);
        $executor->expects(self::exactly(count($outcomes)))->method('buildRequest')
            ->willReturnCallback(static fn (array $kinds, array $filters, RelaySet $relays): RelayQueryRequest => new RelayQueryRequest($relays, [$filters]));
        $executor->expects(self::exactly(count($outcomes)))->method('execute')
            ->willReturnCallback(static function (RelayQueryRequest $request) use (&$outcomes): array {
                $outcome = array_shift($outcomes);
                return ['wss://relay.example.test' => new RelayQueryResult(
                    'wss://relay.example.test',
                    events: is_object($outcome) ? [$outcome] : [],
                    eose: $outcome === 'empty',
                    error: match ($outcome) {
                        'timeout' => 'Relay query timed out',
                        'error' => 'Connection refused',
                        'closed' => 'auth-required: authentication required',
                        default => null,
                    },
                )];
            });
        $executor->method('process')->willReturnCallback(static function (array $responses, callable $handler): array {
            $events = [];
            foreach ($responses as $response) {
                foreach ($response->events as $event) {
                    $events[] = $handler($event);
                }
            }
            return $events;
        });

        return new NostrClient(
            new NullLogger(),
            $registry,
            $userRelays,
            $factory,
            $executor,
            $this->createMock(ArticleFetchService::class),
            $this->createMock(MediaEventService::class),
            $this->createMock(SocialEventService::class),
            $this->createMock(UserProfileService::class),
        );
    }
}
