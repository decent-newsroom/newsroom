<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\InventoryPolicyException;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\ProfileFeaturedContent;
use App\Service\UserMuteListService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

final class ProfileFeaturedContentTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testCurrentAuthorizedTitlesReplaceCachedMetadataAndKeepDistinctIdentities(): void
    {
        $other = str_repeat('b', 64);
        $targets = [];
        foreach ([
            $this->event('1', 30040, self::AUTHOR, 'shared', 10, [['title', 'Signed magazine']]),
            $this->event('2', 30040, $other, 'shared', 20, [['title', 'Signed list']]),
            $this->event('3', 39089, self::AUTHOR, 'shared', 30, [['title', 'Signed pack']]),
            $this->event('4', 30040, self::AUTHOR, 'private', 40, [['s', 'private'], ['title', 'Private secret']]),
            $this->event('5', 30040, self::AUTHOR, 'warning', 50, [['content-warning'], ['title', 'Warning secret']]),
            $this->event('6', 30040, self::AUTHOR, 'hidden', 60, [['title', 'Hidden secret']]),
            $this->event('7', 30040, self::AUTHOR, 'deleted', 70, [['title', 'Deleted secret']]),
            $this->event('8', 30040, str_repeat('c', 64), 'suppressed', 80, [['title', 'Suppressed secret']]),
        ] as $event) {
            $targets[$event->getKind() . ':' . $event->getPubkey() . ':' . $event->getSlug()] = $event;
        }
        $repo = $this->createMock(EventRepository::class);
        $repo->expects(self::once())->method('findReadingNookSubscriptionTargets')->willReturn($targets);
        $data = [
            'featuredMagazines' => array_map(static fn (string $slug): array => [
                'pubkey' => self::AUTHOR, 'slug' => $slug, 'title' => 'Stale secret',
            ], ['shared', 'shared', 'private', 'warning', 'hidden', 'deleted', 'missing']),
            'featuredReadingLists' => [
                ['pubkey' => $other, 'slug' => 'shared'],
                ['pubkey' => str_repeat('c', 64), 'slug' => 'suppressed'],
                ['pubkey' => 'invalid', 'slug' => 'bad'],
            ],
            'featuredInFollowPacks' => [['pubkey' => self::AUTHOR, 'dTag' => 'shared']],
            'authorMagazines' => [['pubkey' => self::AUTHOR, 'slug' => 'not-featured']],
        ];
        $rows = $this->service($repo)->load($data);
        self::assertSame(['follow_pack', 'reading_list', 'magazine'], array_column($rows, 'type'));
        self::assertSame(['Signed pack', 'Signed list', 'Signed magazine'], array_column(array_column($rows, 'item'), 'title'));
        self::assertStringNotContainsString('secret', serialize($rows));
    }

    public function testColdOverviewDoesNotQueryOrInventEntries(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects(self::never())->method('findReadingNookSubscriptionTargets');
        self::assertSame([], $this->service($repo)->load([]));
    }

    public function testAllResultsRemainAvailableBeyondOneStreamPageAndLookupsAreBatched(): void
    {
        $items = [];
        for ($i = 0; $i < 130; ++$i) {
            $items[] = ['pubkey' => self::AUTHOR, 'slug' => 'list:' . $i];
        }
        $repo = $this->createMock(EventRepository::class);
        $repo->expects(self::exactly(2))->method('findReadingNookSubscriptionTargets')
            ->willReturnCallback(function (array $identities): array {
                self::assertLessThanOrEqual(128, count($identities));
                $targets = [];
                foreach ($identities as $identity) {
                    $coordinate = '30040:' . self::AUTHOR . ':' . $identity['dTag'];
                    $targets[$coordinate] = $this->event('1', 30040, self::AUTHOR, $identity['dTag'], 1);
                }
                return $targets;
            });
        self::assertCount(130, $this->service($repo)->load(['featuredReadingLists' => $items]));
    }

    public function testMutedAuthorsAreWithheldAndPolicyFailuresPropagate(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->method('findReadingNookSubscriptionTargets')->willReturn([
            '30040:' . self::AUTHOR . ':list' => $this->event('1', 30040, self::AUTHOR, 'list', 1),
        ]);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn([self::AUTHOR]);
        $data = ['featuredReadingLists' => [['pubkey' => self::AUTHOR, 'slug' => 'list']]];
        self::assertSame([], $this->service($repo, $mutes)->load($data));

        $failedMutes = $this->createMock(UserMuteListService::class);
        $failedMutes->method('getMutedPubkeysStrict')->willThrowException(new \RuntimeException('Unavailable'));
        $this->expectException(InventoryPolicyException::class);
        $this->service($repo, $failedMutes)->load($data);
    }

    public function testMalformedFirstIdentifierCannotBeReplacedByALaterTag(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $event = $this->event('1', 30040, self::AUTHOR, 'list', 1);
        $event->setTags([['d', ['malformed']], ['d', 'list'], ['title', 'Secret']]);
        $repo->method('findReadingNookSubscriptionTargets')->willReturn(['30040:' . self::AUTHOR . ':list' => $event]);
        self::assertSame([], $this->service($repo)->load([
            'featuredReadingLists' => [['pubkey' => self::AUTHOR, 'slug' => 'list']],
        ]));
    }

    private function event(string $id, int $kind, string $pubkey, string $slug, int $created, array $tags = []): Event
    {
        $event = new Event();
        $event->setId(str_repeat($id, 64));
        $event->setPubkey($pubkey);
        $event->setKind($kind);
        $event->setCreatedAt($created);
        $event->setTags([['d', $slug], ...$tags]);
        return $event;
    }

    private function service(EventRepository $repo, ?UserMuteListService $mutes = null): ProfileFeaturedContent
    {
        $security = $this->createMock(Security::class);
        if ($mutes !== null) {
            $user = $this->createMock(User::class);
            $user->method('getUserIdentifier')->willReturn(str_repeat('d', 64));
            $security->method('getUser')->willReturn($user);
        }
        $keys = new NostrKeyService();
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn([str_repeat('c', 64)]);
        $deleted = $this->createMock(DeletedEventRepository::class);
        $deleted->method('findSuppressedEventIds')->willReturn([str_repeat('7', 64)]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturn(['30040:' . self::AUTHOR . ':hidden']);
        $access = new EventPreviewAccessPolicy(
            $authors, $deleted, $hidden, new RecordIdentityService(), $security,
            $mutes ?? $this->createMock(UserMuteListService::class), $keys, new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class), $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        return new ProfileFeaturedContent($repo, $access, $security, $keys);
    }
}
