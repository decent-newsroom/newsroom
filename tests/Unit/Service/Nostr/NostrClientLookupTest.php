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

    private function client(array $outcomes): NostrClient
    {
        $set = new RelaySet([new RelayEndpoint('wss://relay.example.test')]);
        $registry = $this->createMock(RelayRegistry::class);
        $registry->method('ensureLocalRelayInList')->willReturnCallback(static fn (array $urls): array => $urls);
        $userRelays = $this->createMock(UserRelayListService::class);
        $userRelays->method('getRelaysForEventLookupCacheOrDb')->willReturn(['wss://relay.example.test']);
        $factory = $this->createMock(RelaySetFactory::class);
        $factory->method('fromUrls')->willReturn($set);
        $factory->method('getDefault')->willReturn($set);
        $factory->method('forAuthorWithFallback')->willReturn($set);
        $executor = $this->createMock(NostrRequestExecutor::class);
        $executor->expects(self::exactly(count($outcomes)))->method('buildRequest')
            ->with([30040], ['authors' => [str_repeat('a', 64)], 'tag' => ['#d', ['edition']]], $set)
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
