<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\DriveMountAccess;
use App\Service\EventWorkspace\EventWorkspaceScopePolicy;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class DriveMountAccessTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DRIVE_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const DIRECTORY_ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';

    public function testForeignDriveLookupUsesExactIdentityWithoutRequiringOwnership(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findOneBy')
            ->with(['id' => self::DRIVE_ID, 'kind' => 30042])
            ->willReturn($this->event(self::DRIVE_ID, self::OTHER, 30042, [['d', 'shared']]));
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::DRIVE_ID, 30042, self::OTHER, 'shared', 123)->willReturn(false);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::once())->method('assertReadable')->with(self::OTHER);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->expects(self::once())->method('getMutedPubkeysStrict')->with(self::OWNER)->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('findHiddenCoordinates')
            ->with(['30042:' . self::OTHER . ':shared'])->willReturn([]);

        self::assertSame(
            self::DRIVE_ID,
            (new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, new NullLogger()))
                ->readableDrive(self::DRIVE_ID, self::OWNER)?->getId(),
            'Foreign authored drives are readable only after the viewer-specific checks.',
        );
    }

    public function testOtherAuthoredDirectoryIsViewableOnlyAfterIndependentReadChecks(): void
    {
        $directory = $this->event(self::DIRECTORY_ID, self::OTHER, 30045, [['d', 'shared']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findOneBy')
            ->with(['id' => self::DIRECTORY_ID, 'kind' => 30045])->willReturn($directory);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())->method('isSuppressed')
            ->with(self::DIRECTORY_ID, 30045, self::OTHER, 'shared', 123)->willReturn(false);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::once())->method('assertReadable')->with(self::OTHER);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->expects(self::once())->method('getMutedPubkeysStrict')->with(self::OWNER)->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('findHiddenCoordinates')
            ->with(['30045:' . self::OTHER . ':shared'])->willReturn([]);

        $access = new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, new NullLogger());

        self::assertSame($directory, $access->readableDirectory(self::DIRECTORY_ID, self::OWNER));
    }

    public function testDirectoryReadAccessRequiresAStringRawDTagAndAllowsExplicitEmpty(): void
    {
        $missingD = $this->event(str_repeat('1', 64), self::OTHER, 30045, [['title', 'Missing identity']]);
        $malformedD = $this->event(str_repeat('2', 64), self::OTHER, 30045, [['d', 42]]);
        $emptyD = $this->event(str_repeat('3', 64), self::OTHER, 30045, [['d', '']]);
        $events = $this->createMock(EventRepository::class);
        $events->method('findOneBy')->willReturnCallback(static fn (array $criteria): ?Event => match ($criteria['id'] ?? '') {
            $missingD->getId() => $missingD,
            $malformedD->getId() => $malformedD,
            $emptyD->getId() => $emptyD,
            default => null,
        });
        $access = $this->access($events);

        self::assertNull($access->readableDirectory($missingD->getId(), self::OWNER));
        self::assertNull($access->readableDirectory($malformedD->getId(), self::OWNER));
        self::assertSame($emptyD, $access->readableDirectory($emptyD->getId(), self::OWNER));
    }

    public function testTraversalEdgesMustMatchDirectoryIdentityAndExactPins(): void
    {
        $directory = $this->event(self::DIRECTORY_ID, self::OTHER, 30045, [['d', 'shared']]);
        $drive = $this->event(self::DRIVE_ID, self::OWNER, 30042, [
            ['d', 'main'],
            ['a', '30045:' . self::OTHER . ':shared', 'wss://relay.example', self::DIRECTORY_ID],
        ]);
        $driveWithNkbipTagFour = $this->event(str_repeat('7', 64), self::OWNER, 30042, [
            ['d', 'main'],
            ['a', '30045:' . self::OTHER . ':shared', 'wss://relay.example', str_repeat('9', 64)],
        ]);
        $parent = $this->event(str_repeat('e', 64), self::OTHER, 30045, [
            ['d', 'parent'],
            ['a', '30045:' . self::OTHER . ':shared', 'wss://relay.example', self::DIRECTORY_ID],
        ]);
        $wrongPin = $this->event(str_repeat('f', 64), self::OTHER, 30045, [
            ['d', 'parent'],
            ['a', '30045:' . self::OTHER . ':shared', 'wss://relay.example', str_repeat('9', 64)],
        ]);
        $access = $this->access($this->createMock(EventRepository::class));

        self::assertTrue($access->referencesDirectory($drive, $directory));
        self::assertTrue($access->referencesDirectory($driveWithNkbipTagFour, $directory));
        self::assertTrue($access->referencesDirectory($parent, $directory));
        self::assertFalse($access->referencesDirectory($wrongPin, $directory));
    }

    public function testMalformedTargetAddressFailsClosedWithoutHidingSourceOccurrence(): void
    {
        self::assertTrue(EventWorkspaceScopePolicy::isScoped([['a', 'malformed']]));
        self::assertTrue(EventWorkspaceScopePolicy::isScoped([['a']]));
        self::assertTrue(EventWorkspaceScopePolicy::isScoped([['a', 123]]));
        self::assertFalse(EventWorkspaceScopePolicy::hasScopeMarkers([['a', 'malformed']]));
        self::assertTrue(EventWorkspaceScopePolicy::isScoped([['h', 'private-group']]));
        self::assertTrue(EventWorkspaceScopePolicy::isScoped([
            ['a', '34550:' . self::OTHER . ':private-group'],
        ]));
        self::assertFalse(EventWorkspaceScopePolicy::hasScopeMarkers([
            ['a', '34550:' . self::OTHER . ':private-group'],
        ]));
        self::assertTrue(EventWorkspaceScopePolicy::isScopedDriveRoot([
            ['d', 'main'],
            ['a', '34550:' . self::OTHER . ':private-group'],
        ]));
        self::assertFalse(EventWorkspaceScopePolicy::isScopedDriveRoot([
            ['d', 'main'],
            ['a', '30045:' . self::OTHER . ':root'],
            ['a', 'malformed'],
        ]));
        self::assertFalse(EventWorkspaceScopePolicy::isScopedDirectory([
            ['d', 'folder'],
            ['a', '34550:' . self::OTHER . ':private-group'],
            ['a', 'malformed'],
        ]));
        self::assertTrue(EventWorkspaceScopePolicy::isScopedDirectory([
            ['d', 'folder'],
            ['h', 'private-group'],
        ]));
    }

    public function testDriveDeniesTombstonesSuppressedAuthorsMutesHiddenCoordinatesScopesAndWarnings(): void
    {
        $cases = [
            'tombstone' => ['tags' => [['d', 'main']], 'suppressed' => true],
            'suppressed author' => ['tags' => [['d', 'main']], 'authorBlocked' => true],
            'mute' => ['tags' => [['d', 'main']], 'muted' => true],
            'hidden coordinate' => ['tags' => [['d', 'main']], 'hidden' => true],
            'scoped' => ['tags' => [['d', 'main'], ['h', 'private']]],
            'community-scoped drive' => ['tags' => [['d', 'main'], ['a', '34550:' . self::OTHER . ':private']]],
            'warning' => ['tags' => [['d', 'main'], ['content-warning', 'sensitive']]],
        ];

        foreach ($cases as $name => $case) {
            $drive = $this->event(self::DRIVE_ID, self::OTHER, 30042, $case['tags']);
            $deletions = $this->createMock(DeletedEventRepository::class);
            $deletions->method('isSuppressed')->willReturn($case['suppressed'] ?? false);
            $hidden = $this->createMock(HiddenCoordinateRepository::class);
            $hidden->method('findHiddenCoordinates')->willReturn(
                ($case['hidden'] ?? false) ? ['30042:' . self::OTHER . ':main'] : [],
            );
            $authors = $this->createMock(ContentAuthorAccessPolicy::class);
            if ($case['authorBlocked'] ?? false) {
                $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
            }
            $mutes = $this->createMock(UserMuteListService::class);
            $mutes->method('getMutedPubkeysStrict')->willReturn(($case['muted'] ?? false) ? [self::OTHER] : []);
            $access = new DriveMountAccess(
                $this->createMock(EventRepository::class),
                $deletions,
                $hidden,
                $authors,
                $mutes,
                new NullLogger(),
            );

            self::assertFalse($access->isReadableDrive($drive, self::OWNER), $name . ' must deny the drive.');
        }
    }

    public function testUnexpectedPolicyFailureFailsClosedAndLogsWithoutEventDataOrException(): void
    {
        $drive = $this->event(self::DRIVE_ID, self::OTHER, 30042, [['d', 'main']]);
        $events = $this->createMock(EventRepository::class);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn(false);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturn([]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')
            ->willThrowException(new \RuntimeException('raw exception with private event details'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with('Drive mount access policy failed; denying access.', []);

        $access = new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, $logger);

        self::assertFalse($access->isReadableDrive($drive, self::OWNER));
    }

    public function testExpectedSuppressedAuthorDenialFailsClosedWithoutInfrastructureErrorLog(): void
    {
        $drive = $this->event(self::DRIVE_ID, self::OTHER, 30042, [['d', 'main']]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $access = new DriveMountAccess(
            $this->createMock(EventRepository::class),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
            $authors,
            $this->createMock(UserMuteListService::class),
            $logger,
        );

        self::assertFalse($access->isReadableDrive($drive, self::OWNER));
    }

    private function access(EventRepository $events): DriveMountAccess
    {
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn(false);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturn([]);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn([]);

        return new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, new NullLogger());
    }

    /** @param list<array<mixed>> $tags */
    private function event(string $id, string $pubkey, int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($pubkey);
        $event->setKind($kind);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent('');
        return $event;
    }
}
