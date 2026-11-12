<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventBodyFormat;
use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventWorkspaceIdentity;
use App\Dto\EventWorkspace\EventWorkspaceResolutionResult;
use App\Dto\EventWorkspace\EventWorkspaceResolutionStatus;
use PHPUnit\Framework\TestCase;

final class EventWorkspaceResolutionResultTest extends TestCase
{
    public function testDistinguishesNonAvailableResolutionStates(): void
    {
        $identity = EventWorkspaceIdentity::eventId(str_repeat('a', 64));

        self::assertSame(EventWorkspaceResolutionStatus::Missing, EventWorkspaceResolutionResult::missing($identity)->status);
        self::assertSame(EventWorkspaceResolutionStatus::Loading, EventWorkspaceResolutionResult::loading($identity)->status);
        self::assertSame(EventWorkspaceResolutionStatus::Unavailable, EventWorkspaceResolutionResult::unavailable($identity)->status);
        self::assertSame(EventWorkspaceResolutionStatus::Restricted, EventWorkspaceResolutionResult::restricted($identity)->status);
        self::assertSame(EventWorkspaceResolutionStatus::Invalid, EventWorkspaceResolutionResult::invalid($identity)->status);
        self::assertSame(EventWorkspaceResolutionStatus::Failed, EventWorkspaceResolutionResult::failed($identity)->status);
    }

    public function testAvailableResultRequiresTheRequestedExactEvent(): void
    {
        $presentation = $this->presentation(str_repeat('a', 64), null, 1, str_repeat('b', 64));
        $result = EventWorkspaceResolutionResult::available(
            EventWorkspaceIdentity::eventId(str_repeat('A', 64)),
            $presentation,
        );

        self::assertSame(EventWorkspaceResolutionStatus::Available, $result->status);
        self::assertSame($presentation, $result->presentation);
    }

    public function testAddressResultHonorsVersionPin(): void
    {
        $presentation = $this->presentation(str_repeat('a', 64), '30040:' . str_repeat('b', 64) . ':book', 30040, str_repeat('b', 64));
        $identity = EventWorkspaceIdentity::address(
            '30040:' . str_repeat('b', 64) . ':book',
            str_repeat('a', 64),
        );

        self::assertSame($presentation, EventWorkspaceResolutionResult::available($identity, $presentation)->presentation);
    }

    public function testExactEventAndCurrentAddressRemainDifferentIdentities(): void
    {
        $eventId = str_repeat('a', 64);
        $coordinate = '30040:' . str_repeat('b', 64) . ':book';

        $exact = EventWorkspaceIdentity::eventId($eventId);
        $current = EventWorkspaceIdentity::address($coordinate);

        self::assertNotSame($exact->type, $current->type);
        self::assertSame($eventId, $exact->value);
        self::assertSame($coordinate, $current->value);
    }

    public function testMismatchedAvailablePresentationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        EventWorkspaceResolutionResult::available(
            EventWorkspaceIdentity::eventId(str_repeat('a', 64)),
            $this->presentation(str_repeat('b', 64), null, 1, str_repeat('c', 64)),
        );
    }

    public function testProfileResolutionMustBeTheRequestedAuthorsMetadata(): void
    {
        $presentation = $this->presentation(str_repeat('a', 64), '0:' . str_repeat('b', 64), 0, str_repeat('b', 64));

        self::assertSame(
            $presentation,
            EventWorkspaceResolutionResult::available(
                EventWorkspaceIdentity::profile(str_repeat('B', 64)),
                $presentation,
            )->presentation,
        );
    }

    private function presentation(string $eventId, ?string $coordinate, int $kind, string $pubkey): EventPresentation
    {
        return new EventPresentation(
            eventId: $eventId,
            kind: $kind,
            authorPubkey: $pubkey,
            authorNpub: null,
            coordinate: $coordinate,
            recordUid: null,
            createdAt: 1,
            publishedAt: null,
            title: null,
            description: null,
            descriptionOnReveal: null,
            imageUrl: null,
            hasContentWarning: false,
            contentWarningReason: null,
            isSensitive: false,
            bodyFormat: EventBodyFormat::Opaque,
            textContent: null,
            autoUpdate: null,
            references: [],
            rawTags: [],
        );
    }
}
