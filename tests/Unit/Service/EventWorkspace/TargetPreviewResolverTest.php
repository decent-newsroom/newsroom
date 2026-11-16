<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\BookmarkReferencePage;
use App\Service\EventWorkspace\BookshelfReferencePage;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Bundle\SecurityBundle\Security;

final class TargetPreviewResolverTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const READER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private EventRepository&MockObject $events;
    private DeletedEventRepository&MockObject $deletions;
    private HiddenCoordinateRepository&MockObject $hidden;
    private ContentAuthorAccessPolicy&MockObject $authors;
    private UserMuteListService&MockObject $mutes;
    private EventPresentationFactory $factory;
    private TargetPreviewResolver $resolver;

    protected function setUp(): void
    {
        $this->events = $this->createMock(EventRepository::class);
        $this->deletions = $this->createMock(DeletedEventRepository::class);
        $this->hidden = $this->createMock(HiddenCoordinateRepository::class);
        $this->authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $this->mutes = $this->createMock(UserMuteListService::class);
        $security = $this->createMock(Security::class);
        $user = new User();
        $user->setNpub(PublicKey::fromHex(self::READER)->toBech32());
        $security->method('getUser')->willReturn($user);
        $this->factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $this->resolver = new TargetPreviewResolver(
            $this->events, $this->deletions, $this->hidden, $this->authors,
            $this->factory, $security, $this->mutes,
        );
    }

    public function testBothSurfacesShareExactLatestDuplicateAndMissingResolution(): void
    {
        $old = $this->event('c', 30040, [['d', 'book'], ['title', 'Old book']]);
        $latest = $this->event('d', 30040, [['d', 'book'], ['title', 'Latest book']]);
        $coordinate = '30040:' . self::AUTHOR . ':book';
        $missing = str_repeat('e', 64);
        $this->events->expects(self::exactly(2))->method('findWorkspacePreviewTargets')
            ->with([$coordinate], [$old->getId(), $missing])
            ->willReturn(['coordinates' => [$coordinate => $latest], 'eventIds' => [$old->getId() => $old]]);
        $this->mutes->expects(self::exactly(2))->method('getMutedPubkeysStrict')->with(self::READER)->willReturn([]);
        foreach ([10003, 30045] as $kind) {
            $source = $this->event('f', $kind, [
                ['title', 'Source'], ['e', $old->getId()], ['a', $coordinate],
                ['e', $old->getId()], ['e', $missing], ['a', 'bad'],
            ]);
            $entries = $this->page($source)['entries'];
            self::assertSame(['available', 'available', 'available', 'missing', null], array_map(
                static fn (array $entry): ?string => $entry['targetResolution']?->status->value, $entries,
            ));
            self::assertSame('Old book', $entries[0]['targetResolution']->presentation->title);
            self::assertSame('Latest book', $entries[1]['targetResolution']->presentation->title);
            self::assertSame($old->getId(), $entries[2]['targetResolution']->presentation->eventId);
            self::assertNull($entries[3]['targetResolution']->presentation);
        }
    }

    public function testDirectoryPinsAreExactAndMismatchNeverFallsBackButBookmarkFourthSlotIsNotPin(): void
    {
        $coordinate = '30040:' . self::AUTHOR . ':book';
        $pin = $this->event('c', 30040, [['d', 'different'], ['title', 'Must not leak']]);
        $latest = $this->event('d', 30040, [['d', 'book'], ['title', 'Latest']]);
        $this->events->expects(self::exactly(2))->method('findWorkspacePreviewTargets')
            ->willReturnCallback(static function (array $coordinates, array $ids) use ($coordinate, $pin, $latest): array {
                if ($ids !== []) {
                    self::assertSame([], $coordinates);
                    self::assertSame([$pin->getId()], $ids);

                    return ['coordinates' => [], 'eventIds' => [$pin->getId() => $pin]];
                }
                self::assertSame([$coordinate], $coordinates);

                return ['coordinates' => [$coordinate => $latest], 'eventIds' => []];
            });
        $tags = [
            ['a', $coordinate, '', $pin->getId()],
            ['a', $coordinate, '', 'not-a-pin'],
            ['a', $coordinate, '', 123],
        ];
        $directory = $this->page($this->event('f', 30045, $tags));
        self::assertSame(['unavailable', 'invalid', 'invalid'], array_map(
            static fn (array $entry): string => $entry['targetResolution']->status->value, $directory['entries'],
        ));
        self::assertStringNotContainsString('Must not leak', serialize($directory));
        $bookmarks = $this->page($this->event('f', 30003, $tags));
        foreach ($bookmarks['entries'] as $entry) {
            self::assertSame('Latest', $entry['targetResolution']->presentation->title);
            self::assertNull($entry['targetResolution']->identity->versionEventId);
        }
    }

    public function testMatchingAndMissingDirectoryPinsNeverQueryLatestCoordinate(): void
    {
        $coordinate = '30041:' . self::AUTHOR . ':chapter';
        $pin = $this->event('c', 30041, [['d', 'chapter'], ['title', 'Exact chapter']]);
        $missing = str_repeat('d', 64);
        $this->events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([], [$pin->getId(), $missing])
            ->willReturn(['coordinates' => [], 'eventIds' => [$pin->getId() => $pin]]);
        $page = $this->page($this->event('f', 30045, [
            ['a', $coordinate, '', $pin->getId()],
            ['a', $coordinate, '', $missing],
        ]));
        self::assertSame('Exact chapter', $page['entries'][0]['targetResolution']->presentation->title);
        self::assertSame($pin->getId(), $page['entries'][0]['targetResolution']->identity->versionEventId);
        self::assertSame('missing', $page['entries'][1]['targetResolution']->status->value);
    }

    public function testNonPublicAddressesProfilesTopicsAndMalformedReferencesNeverQueryTargets(): void
    {
        $this->events->expects(self::never())->method('findWorkspacePreviewTargets');
        $this->mutes->expects(self::never())->method('getMutedPubkeysStrict');
        $page = $this->page($this->event('f', 10003, [
            ['a', '30024:' . self::AUTHOR . ':private'],
            ['a', '30023:' . self::AUTHOR . ':article'],
            ['a', '30045:' . self::AUTHOR . ':directory'],
            ['p', self::AUTHOR], ['t', 'topic'], ['e', 'bad-id'],
        ]));
        self::assertSame('restricted', $page['entries'][0]['targetResolution']->status->value);
        self::assertSame('restricted', $page['entries'][1]['targetResolution']->status->value);
        self::assertSame('restricted', $page['entries'][2]['targetResolution']->status->value);
        self::assertNull($page['entries'][3]['targetResolution']);
        self::assertNull($page['entries'][4]['targetResolution']);
        self::assertNull($page['entries'][5]['targetResolution']);
    }

    public function testAudienceScopesAreKindAwareBeforeGenericTargetMetadata(): void
    {
        $index = $this->event('a', 30040, [['d', 'index'], ['s', 'members'], ['title', 'Secret index']]);
        $chapter = $this->event('b', 30041, [['d', 'chapter'], ['s'], ['title', 'Secret chapter']]);
        $note = $this->event('c', 1, [['s', 'not-an-audience'], ['title', 'Public note']]);
        $publicIndex = $this->event('d', 30040, [['d', 'public'], ['title', 'Public index']]);
        $targets = [$index, $chapter, $note, $publicIndex];
        $byId = [];
        foreach ($targets as $target) {
            $byId[$target->getId()] = $target;
        }
        $this->events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->willReturn(['coordinates' => [], 'eventIds' => $byId]);
        $source = $this->event('f', 10003, array_map(
            static fn (Event $target): array => ['e', $target->getId()],
            $targets,
        ));
        $entries = $this->page($source)['entries'];
        self::assertSame(['restricted', 'restricted', 'available', 'available'], array_map(
            static fn (array $entry): string => $entry['targetResolution']->status->value,
            $entries,
        ));
        self::assertNull($entries[0]['targetResolution']->presentation);
        self::assertNull($entries[1]['targetResolution']->presentation);
        self::assertSame('Public note', $entries[2]['targetResolution']->presentation->title);
        self::assertStringNotContainsString('Secret', serialize($entries));
    }

    /** @dataProvider targetPolicies */
    public function testTargetPolicyRunsBeforeAnyMetadataIsReturned(string $policy, int $kind, string $status): void
    {
        $target = $this->event('c', $kind, [
            ['d', 'secret'], ['title', 'secret title'], ['image', 'https://secret.test/image'],
            ...($policy === 'sensitive' ? [['content-warning', 'secret warning']] : []),
        ]);
        $this->events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->willReturn(['coordinates' => [], 'eventIds' => [$target->getId() => $target]]);
        $this->mutes->expects(self::once())->method('getMutedPubkeysStrict')->with(self::READER)
            ->willReturn($policy === 'mute' ? [self::AUTHOR] : []);
        $this->deletions->method('findSuppressedEventIds')->willReturn($policy === 'deleted' ? [$target->getId()] : []);
        $this->authors->method('suppressedPubkeys')->willReturn($policy === 'author' ? [self::AUTHOR] : []);
        $this->hidden->method('findHiddenCoordinates')->willReturn(
            $policy === 'hidden' ? ['30041:' . self::AUTHOR . ':secret'] : [],
        );
        $result = $this->page($this->event('f', 10003, [['e', $target->getId()]]))['entries'][0]['targetResolution'];
        self::assertSame($status, $result->status->value);
        self::assertStringNotContainsString('secret title', serialize($result));
        self::assertStringNotContainsString('secret warning', serialize($result));
        self::assertStringNotContainsString('https://secret.test', serialize($result));
        self::assertStringNotContainsString('secret body', serialize($result));
        if ($status === 'available') {
            self::assertTrue($result->presentation->isSensitive);
            self::assertNull($result->presentation->title);
            self::assertNull($result->presentation->textContent);
            self::assertNull($result->presentation->imageUrl);
            self::assertSame([], $result->presentation->getRawTags());
            self::assertSame([], $result->presentation->references);
        } else {
            self::assertNull($result->presentation);
        }
    }

    /** @dataProvider publicKinds */
    public function testPublicTargetsNeedNoArticleProjection(int $kind): void
    {
        $target = $this->event('c', $kind, [['title', 'Public title']]);
        $this->events->expects(self::once())->method('findWorkspacePreviewTargets')
            ->with([], [$target->getId()])
            ->willReturn(['coordinates' => [], 'eventIds' => [$target->getId() => $target]]);
        $result = $this->page($this->event('f', 10003, [['e', $target->getId()]]))['entries'][0]['targetResolution'];
        self::assertSame('available', $result->status->value);
        self::assertSame('Public title', $result->presentation->title);
        self::assertNull($result->presentation->textContent);
        self::assertNull($result->presentation->imageUrl);
    }

    /** @return iterable<array{int}> */
    public static function publicKinds(): iterable
    {
        foreach ([30040, 30041, 1, 20, 21, 22] as $kind) {
            yield [$kind];
        }
    }

    /** @return iterable<string, array{string, int, string}> */
    public static function targetPolicies(): iterable
    {
        yield 'draft' => ['private', 30024, 'restricted'];
        yield 'private app data' => ['private', 30078, 'restricted'];
        yield 'encrypted message' => ['private', 4, 'restricted'];
        yield 'article without safe entitlement' => ['article', 30023, 'restricted'];
        yield 'NIP09' => ['deleted', 30041, 'restricted'];
        yield 'suppressed author' => ['author', 30041, 'restricted'];
        yield 'strict reader mute' => ['mute', 30041, 'restricted'];
        yield 'hidden coordinate' => ['hidden', 30041, 'restricted'];
        yield 'sensitive title and media' => ['sensitive', 20, 'available'];
    }

    public function testOnlyVisibleSourceOccurrencesAreResolvedOnEachSurface(): void
    {
        $firstId = str_repeat('c', 64);
        $secondId = str_repeat('d', 64);
        $this->events->expects(self::exactly(4))->method('findWorkspacePreviewTargets')
            ->willReturnCallback(static function (array $coordinates, array $ids) use ($firstId, $secondId): array {
                self::assertSame([], $coordinates);
                self::assertCount(1, $ids);
                self::assertContains($ids[0], [$firstId, $secondId]);

                return ['coordinates' => [], 'eventIds' => []];
            });
        foreach ([10003, 30045] as $kind) {
            $source = $this->event('f', $kind, [...array_fill(0, 50, ['e', $firstId]), ['e', $secondId]]);
            $first = $this->page($source);
            self::assertCount(50, $first['entries']);
            self::assertTrue($first['hasNext']);
            self::assertSame($firstId, $first['entries'][0]['targetResolution']->identity->value);
            $second = $this->page($source, 2);
            self::assertCount(1, $second['entries']);
            self::assertSame($secondId, $second['entries'][0]['targetResolution']->identity->value);
            self::assertFalse($second['hasNext']);
        }
    }

    public function testStrictMuteFailureCannotReturnTargetMetadata(): void
    {
        $this->mutes->method('getMutedPubkeysStrict')->willThrowException(new \RuntimeException('DB unavailable'));
        $this->events->expects(self::never())->method('findWorkspacePreviewTargets');
        $this->expectException(\RuntimeException::class);
        $this->page($this->event('f', 10003, [['e', str_repeat('c', 64)]]));
    }

    public function testDirectoryTargetLookupIsSeparateAndScopedDirectoriesStayRestricted(): void
    {
        $coordinate = '30045:' . self::AUTHOR . ':private-folder';
        $directory = $this->event('c', 30045, [
            ['d', 'private-folder'],
            ['title', 'Must not be exposed'],
            ['h', 'private-scope'],
        ]);
        $directory->setContent('');
        $this->events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $directory], 'eventIds' => []]);
        $this->deletions->method('findSuppressedEventIds')->willReturn([]);
        $this->authors->method('suppressedPubkeys')->willReturn([]);
        $this->hidden->method('findHiddenCoordinates')->willReturn([]);
        $this->mutes->method('getMutedPubkeysStrict')->with(self::READER)->willReturn([]);
        $reference = $this->factory->fromAuthorizedEvent($this->event('f', 30045, [
            ['d', 'parent'],
            ['a', $coordinate],
        ]))->references[0];

        $result = $this->resolver->resolveDirectoryPage([$reference]);

        self::assertSame('restricted', $result[$reference->position]->status->value);
        self::assertNull($result[$reference->position]->presentation);
        self::assertStringNotContainsString('Must not be exposed', serialize($result));
    }

    /**
     * @dataProvider directoryChildReferenceProvider
     * @param list<array<mixed>> $targetTags
     */
    public function testDirectoryChildReferencesDoNotScopeDirectoryMetadata(array $targetTags): void
    {
        $coordinate = '30045:' . self::AUTHOR . ':folder-with-children';
        $directory = $this->event('c', 30045, [
            ['d', 'folder-with-children'],
            ['title', 'Directory remains mountable'],
            ...$targetTags,
        ]);
        $directory->setContent('');
        $this->events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with([$coordinate], [])
            ->willReturn(['coordinates' => [$coordinate => $directory], 'eventIds' => []]);
        $this->deletions->method('findSuppressedEventIds')->willReturn([]);
        $this->authors->method('suppressedPubkeys')->willReturn([]);
        $this->hidden->method('findHiddenCoordinates')->willReturn([]);
        $this->mutes->method('getMutedPubkeysStrict')->with(self::READER)->willReturn([]);
        $reference = $this->factory->fromAuthorizedEvent($this->event('f', 30045, [
            ['d', 'parent'],
            ['a', $coordinate],
        ]))->references[0];

        $result = $this->resolver->resolveDirectoryPage([$reference]);

        self::assertSame('available', $result[$reference->position]->status->value);
        self::assertSame('Directory remains mountable', $result[$reference->position]->presentation->title);
    }

    /** @return iterable<string, array{list<array<mixed>>}> */
    public static function directoryChildReferenceProvider(): iterable
    {
        yield 'malformed string child' => [[['a', 'not-an-address']]];
        yield 'missing child value' => [[['a']]];
        yield 'non-string child value' => [[['a', 123]]];
        yield 'community child' => [[['a', '34550:' . self::AUTHOR . ':community']]];
    }

    /**
     * @dataProvider directoryIdentityProvider
     * @param list<array<mixed>> $directoryTags
     */
    public function testDirectoryTargetMetadataRequiresAValidRawDTag(
        string $referenceKind,
        array $directoryTags,
        string $expectedStatus,
    ): void {
        $directoryId = str_repeat('c', 64);
        $coordinate = '30045:' . self::AUTHOR . ':';
        $directory = $this->event('c', 30045, [
            ...$directoryTags,
            ['title', 'Directory identity test'],
        ]);
        $directory->setId($directoryId);
        $directory->setContent('');
        // A projected d_tag cannot replace the signed raw first d tag.
        $directory->setDTag('folder');
        $sourceTag = $referenceKind === 'exact'
            ? ['e', $directoryId]
            : ['a', $coordinate];
        $source = $this->event('f', 30045, [['d', 'parent'], $sourceTag]);
        $reference = $this->factory->fromAuthorizedEvent($source)->references[0];
        $requestedCoordinates = $referenceKind === 'address' ? [$coordinate] : [];
        $requestedIds = $referenceKind === 'exact' ? [$directoryId] : [];
        $this->events->expects(self::once())->method('findWorkspaceDirectoryTargets')
            ->with($requestedCoordinates, $requestedIds)
            ->willReturn([
                'coordinates' => $requestedCoordinates === [] ? [] : [$coordinate => $directory],
                'eventIds' => $requestedIds === [] ? [] : [$directoryId => $directory],
            ]);
        $this->deletions->method('findSuppressedEventIds')->willReturn([]);
        $this->authors->method('suppressedPubkeys')->willReturn([]);
        $this->hidden->method('findHiddenCoordinates')->willReturn([]);
        $this->mutes->method('getMutedPubkeysStrict')->with(self::READER)->willReturn([]);

        $result = $this->resolver->resolveDirectoryPage([$reference]);

        self::assertSame($expectedStatus, $result[$reference->position]->status->value);
        if ($expectedStatus === 'available') {
            self::assertSame(
                '30045:' . self::AUTHOR . ':',
                $result[$reference->position]->presentation->coordinate,
            );
        } else {
            self::assertNull($result[$reference->position]->presentation);
            self::assertStringNotContainsString('Directory identity test', serialize($result));
        }
    }

    /** @return iterable<string, array{string, list<array<mixed>>, string}> */
    public static function directoryIdentityProvider(): iterable
    {
        yield 'address missing d' => ['address', [], 'restricted'];
        yield 'address malformed d' => ['address', [['d', 42]], 'restricted'];
        yield 'address explicit empty d' => ['address', [['d', '']], 'available'];
        yield 'exact id missing d' => ['exact', [], 'restricted'];
        yield 'exact id malformed d' => ['exact', [['d', 42]], 'restricted'];
        yield 'exact id explicit empty d' => ['exact', [['d', '']], 'available'];
    }

    public function testOversizedBatchIsRejected(): void
    {
        $reference = $this->factory->fromAuthorizedEvent($this->event('f', 10003, [['e', str_repeat('c', 64)]]))->references[0];
        $this->events->expects(self::never())->method('findWorkspacePreviewTargets');
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolvePage(array_fill(0, 51, $reference));
    }

    /** @return array<string, mixed> */
    private function page(Event $source, int $page = 1): array
    {
        $adapter = new EventWorkspaceSourceAdapter(new RecordIdentityService());

        return $source->getKind() === 30045
            ? (new BookshelfReferencePage($adapter, $this->factory, $this->resolver))->fromAuthorizedEvent($source, $page)
            : (new BookmarkReferencePage($adapter, $this->factory, new NostrNip19Service(), $this->resolver))->fromAuthorizedEvent($source, $page);
    }

    /** @param list<array<mixed>> $tags */
    private function event(string $idCharacter, int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat($idCharacter, 64));
        $event->setPubkey(self::AUTHOR);
        $event->setKind($kind);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent('secret body');

        return $event;
    }
}
