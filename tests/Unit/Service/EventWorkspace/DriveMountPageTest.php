<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\ArticleRepository;
use App\Service\EventWorkspace\DriveMountPage;
use App\Service\EventWorkspace\DriveMountEventAccess;
use App\Service\EventWorkspace\DriveMountEventOpenSigner;
use App\Service\EventWorkspace\DriveMountEventAccessInterface;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

final class DriveMountPageTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const READER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testDriveKeepsOrderedDuplicateAndMalformedRootOccurrences(): void
    {
        $coordinate = '30045:' . self::OWNER . ':root';
        $directory = $this->event('b', 30045, [['d', 'root'], ['title', 'Root']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $directory], 'eventIds' => []]);
        $page = $this->page($events);
        $drive = $this->event('a', 30042, [
            ['d', 'main'],
            ['a', $coordinate, 'wss://relay.example'],
            ['a', $coordinate, 'wss://relay.example'],
            ['a', 'malformed'],
        ]);

        $result = $page->fromAuthorizedEvent($drive);

        self::assertSame([1, 2, 3], array_map(
            static fn (array $entry): int => $entry['reference']->position,
            $result['entries'],
        ));
        self::assertSame('available', $result['entries'][0]['targetResolution']->status->value);
        self::assertSame($directory->getId(), $result['entries'][0]['directory']['eventId']);
        self::assertSame($directory->getId(), $result['entries'][1]['directory']['eventId']);
        self::assertNotNull($result['entries'][0]['nestedSignature']);
        self::assertSame('invalid', $result['entries'][2]['reference']->status->value);
        self::assertNull($result['entries'][2]['directory']);
    }

    public function testMissingAndUnauthorizedDirectoryTargetsShareDrivePresentationStatus(): void
    {
        $coordinate = '30045:' . self::OWNER . ':root';
        foreach (['missing', 'restricted'] as $case) {
            $directory = $this->event('b', 30045, [['d', 'root'], ['title', 'Do not reveal']]);
            $events = $this->createMock(EventRepository::class);
            $events->expects(self::once())->method('findWorkspaceDirectoryTargets')
                ->with([$coordinate], [])
                ->willReturn($case === 'missing'
                    ? ['coordinates' => [], 'eventIds' => []]
                    : ['coordinates' => [$coordinate => $directory], 'eventIds' => []]);
            $drive = $this->event('a', 30042, [['d', 'main'], ['a', $coordinate]]);

            $result = $this->page(
                $events,
                $case === 'restricted' ? [$coordinate] : [],
            )->fromAuthorizedEvent($drive);

            self::assertSame(
                'unavailable',
                $result['entries'][0]['targetResolution']->status->value,
                $case . ' directory targets must be indistinguishable in a mounted drive.',
            );
            self::assertNull($result['entries'][0]['directory']);
        }
    }

    public function testDirectoryChildCommunityAndMalformedReferencesRemainVisibleOccurrences(): void
    {
        $coordinate = '30045:' . self::OWNER . ':root';
        $directory = $this->event('b', 30045, [
            ['d', 'root'],
            ['title', 'Browsable directory'],
            ['a', 'malformed-child-address'],
            ['a'],
            ['a', '34550:' . self::OWNER . ':community-child'],
        ]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $directory], 'eventIds' => []]);
        $page = $this->page($events);
        $drive = $this->event('a', 30042, [['d', 'main'], ['a', $coordinate]]);

        $root = $page->fromAuthorizedEvent($drive);
        $children = $page->fromAuthorizedEvent(
            $directory,
            ancestorPath: [$drive->getId()],
            occurrenceId: str_repeat('f', 64),
            driveId: $drive->getId(),
        );

        self::assertSame('available', $root['entries'][0]['targetResolution']->status->value);
        self::assertSame($directory->getId(), $root['entries'][0]['directory']['eventId']);
        self::assertNotNull($root['entries'][0]['nestedSignature']);
        self::assertSame([2, 3, 4], array_map(
            static fn (array $entry): int => $entry['reference']->position,
            $children['entries'],
        ));
        self::assertSame('invalid', $children['entries'][0]['reference']->status->value);
        self::assertSame('invalid', $children['entries'][1]['reference']->status->value);
        self::assertSame('unavailable', $children['entries'][2]['targetResolution']->status->value);
    }

    public function testDirectoryCycleIsDetectedOnCurrentPathWithoutDroppingOccurrence(): void
    {
        $directoryId = str_repeat('c', 64);
        $coordinate = '30045:' . self::OWNER . ':folder';
        $directory = $this->eventId($directoryId, 30045, self::OWNER, [['d', 'folder'], ['a', $coordinate]]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $directory], 'eventIds' => []]);

        $result = $this->page($events)->fromAuthorizedEvent(
            $directory,
            ancestorPath: [str_repeat('d', 64)],
            occurrenceId: str_repeat('e', 64),
            driveId: str_repeat('d', 64),
        );

        self::assertCount(1, $result['entries']);
        self::assertTrue($result['entries'][0]['cycle']);
        self::assertNull($result['entries'][0]['nestedSignature']);
    }

    public function testDirectoryVersionPinRemainsExactBesideLatestCoordinateOccurrence(): void
    {
        $coordinate = '30045:' . self::OWNER . ':child';
        $pinned = $this->event('b', 30045, [['d', 'child'], ['title', 'Pinned']]);
        $latest = $this->event('c', 30045, [['d', 'child'], ['title', 'Latest']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [$pinned->getId()])
            ->willReturn([
                'coordinates' => [$coordinate => $latest],
                'eventIds' => [$pinned->getId() => $pinned],
            ]);
        $directory = $this->event('d', 30045, [
            ['d', 'parent'],
            ['a', $coordinate, 'wss://relay.example', $pinned->getId()],
            ['a', $coordinate, 'wss://relay.example'],
        ]);

        $result = $this->page($events)->fromAuthorizedEvent(
            $directory,
            ancestorPath: [str_repeat('a', 64)],
            occurrenceId: str_repeat('e', 64),
            driveId: str_repeat('a', 64),
        );

        self::assertSame($pinned->getId(), $result['entries'][0]['targetResolution']->presentation->eventId);
        self::assertSame($pinned->getId(), $result['entries'][0]['rawVersionPin']);
        self::assertSame($latest->getId(), $result['entries'][1]['targetResolution']->presentation->eventId);
    }

    public function testDrivePagesAreBoundedAndKeepOccurrenceOrdinals(): void
    {
        $coordinate = '30045:' . self::OWNER . ':root';
        $directory = $this->event('b', 30045, [['d', 'root']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::exactly(2))->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $directory], 'eventIds' => []]);
        $tags = [['d', 'main'], ...array_fill(0, 51, ['a', $coordinate])];
        $drive = $this->event('a', 30042, $tags);
        $page = $this->page($events);

        $first = $page->fromAuthorizedEvent($drive, 1);
        $second = $page->fromAuthorizedEvent($drive, 2);

        self::assertCount(50, $first['entries']);
        self::assertTrue($first['hasNext']);
        self::assertSame(1, $first['start']);
        self::assertCount(1, $second['entries']);
        self::assertSame(51, $second['start']);
        self::assertFalse($second['hasNext']);
        self::assertSame(51, $second['entries'][0]['reference']->position);
    }

    public function testDirectoryArticleLookupReceivesOnlyVisibleOrderedOccurrences(): void
    {
        $idA = str_repeat('1', 64);
        $idB = str_repeat('2', 64);
        $idC = str_repeat('3', 64);
        $tags = [['d', 'folder'], ...array_fill(0, 49, ['e', $idA]),
            ['p', self::OWNER], ['e', $idB], ['e', $idA], ['e', $idC]];
        $directory = $this->event('c', 30045, $tags);
        $events = $this->createMock(EventRepository::class);
        $empty = ['coordinates' => [], 'eventIds' => []];
        $events->method('findWorkspaceDirectoryTargets')->willReturn($empty);
        $events->method('findWorkspacePreviewTargets')->willReturn($empty);
        $batches = [];
        $events->expects(self::exactly(2))->method('findWorkspaceDriveArticleTargets')
            ->willReturnCallback(static function (array $coordinates, array $ids) use (&$batches, $empty): array {
                $batches[] = [$coordinates, $ids];
                return $empty;
            });
        $security = $this->createMock(Security::class);
        $decoder = $this->createMock(\DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface::class);
        $encoder = $this->createMock(\DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface::class);
        $policy = new EventPreviewAccessPolicy(
            $this->createMock(ContentAuthorAccessPolicy::class),
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
            new RecordIdentityService(),
            $security,
            $this->createMock(UserMuteListService::class),
            new NostrKeyService(),
            new NullLogger(),
            new ArticleAccessService(new NostrIdentityService($decoder, $encoder)),
        );
        $openAccess = new DriveMountEventAccess($events, $this->createMock(ArticleRepository::class), $policy);
        $page = $this->page($events, [], $openAccess);
        $first = $page->fromAuthorizedEvent($directory, 1, [str_repeat('f', 64)], driveId: str_repeat('f', 64), readerPubkey: self::READER);
        $second = $page->fromAuthorizedEvent($directory, 2, [str_repeat('f', 64)], driveId: str_repeat('f', 64), readerPubkey: self::READER);

        self::assertCount(50, $first['entries']);
        self::assertTrue($first['hasNext']);
        self::assertSame([...range(1, 49), 51], array_map(static fn (array $entry): int => $entry['reference']->position, $first['entries']));
        self::assertSame([52, 53], array_map(static fn (array $entry): int => $entry['reference']->position, $second['entries']));
        self::assertSame([$idA, $idC], array_map(static fn (array $entry): ?string => $entry['reference']->target, $second['entries']));
        self::assertSame([[[], [$idA, $idB]], [[], [$idA, $idC]]], $batches);
    }

    public function testMaximumTraversalDepthPreventsAnotherNestedDirectoryLink(): void
    {
        $coordinate = '30045:' . self::OWNER . ':child';
        $child = $this->event('b', 30045, [['d', 'child']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $child], 'eventIds' => []]);
        $source = $this->event('c', 30045, [['d', 'parent'], ['a', $coordinate]]);
        $path = [str_repeat('a', 64), str_repeat('d', 64), str_repeat('e', 64), str_repeat('f', 64), str_repeat('9', 64)];

        $result = $this->page($events)->fromAuthorizedEvent(
            $source,
            ancestorPath: $path,
            occurrenceId: str_repeat('8', 64),
            driveId: $path[0],
        );

        self::assertTrue($result['entries'][0]['depthLimited']);
        self::assertNull($result['entries'][0]['nestedSignature']);
    }

    public function testAuthorizedArticleOccurrenceHasAvailableStatusAndNoUnavailableLabel(): void
    {
        $article = $this->event('e', 30023, [['d', 'story'], ['title', 'Member article']]);
        $directory = $this->event('c', 30045, [
            ['d', 'source'],
            ['a', '30023:' . self::OWNER . ':story'],
        ]);
        $events = $this->createMock(EventRepository::class);
        $openAccess = $this->createMock(DriveMountEventAccessInterface::class);
        $openAccess->expects(self::once())->method('readableArticleTargets')
            ->willReturn([1 => $article]);

        $result = $this->page($events, [], $openAccess)->fromAuthorizedEvent(
            $directory,
            ancestorPath: [str_repeat('f', 64)],
            driveId: str_repeat('f', 64),
            readerPubkey: self::READER,
        );

        self::assertSame('available', $result['entries'][0]['targetResolution']->status->value);
        self::assertSame($article->getId(), $result['entries'][0]['targetResolution']->presentation->eventId);
        self::assertArrayNotHasKey('targetUnavailable', $result['entries'][0]);
        self::assertSame($article->getId(), $result['entries'][0]['openEvent']['targetId']);
    }

    public function testMissingAndUnauthorizedArticleOccurrencesShareUnavailableStatus(): void
    {
        $directory = $this->event('c', 30045, [
            ['d', 'source'],
            ['a', '30023:' . self::OWNER . ':story'],
        ]);
        foreach (['missing', 'unauthorized'] as $case) {
            $events = $this->createMock(EventRepository::class);
            $openAccess = $this->createMock(DriveMountEventAccessInterface::class);
            $openAccess->method('readableArticleTargets')->willReturn([]);

            $result = $this->page($events, [], $openAccess)->fromAuthorizedEvent(
                $directory,
                ancestorPath: [str_repeat('f', 64)],
                driveId: str_repeat('f', 64),
                readerPubkey: self::READER,
            );

            self::assertSame('unavailable', $result['entries'][0]['targetResolution']->status->value, $case);
            self::assertTrue($result['entries'][0]['targetUnavailable']);
            self::assertArrayNotHasKey('openEvent', $result['entries'][0]);
        }
    }

    private function page(
        EventRepository $events,
        array $hiddenCoordinates = [],
        ?DriveMountEventAccessInterface $openAccess = null,
    ): DriveMountPage
    {
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('findSuppressedEventIds')->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturn($hiddenCoordinates);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn([]);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn([]);
        $security = $this->createMock(Security::class);
        $user = new User();
        $user->setNpub(PublicKey::fromHex(self::READER)->toBech32());
        $security->method('getUser')->willReturn($user);
        $factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $targets = new TargetPreviewResolver($events, $deletions, $hidden, $authors, $factory, $security, $mutes);

        return new DriveMountPage(
            new EventWorkspaceSourceAdapter(new RecordIdentityService()),
            $factory,
            $targets,
            new PublicationTocTraversalSigner('test-secret'),
            new DriveMountEventOpenSigner('test-open-secret'),
            $openAccess,
        );
    }

    /** @param array<array-key, mixed> $tags */
    private function event(string $idPrefix, int $kind, array $tags): Event
    {
        return $this->eventId(str_repeat($idPrefix, 64), $kind, self::OWNER, $tags);
    }

    /** @param array<array-key, mixed> $tags */
    private function eventId(string $id, int $kind, string $pubkey, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent('');
        $event->setSig(str_repeat('f', 128));
        return $event;
    }
}
