<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Dto\UserMetadata;
use App\Entity\FollowPackSource;
use App\Enum\FollowPackPurpose;
use App\Repository\FollowPackSourceRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\FollowPackService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\FeaturedWritersRecommendations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FeaturedWritersRecommendationsTest extends TestCase
{
    private function key(int $i): string
    {
        return str_pad(dechex($i), 64, '0', STR_PAD_LEFT);
    }

    public function testSelectsPackMembersWithoutExcludedSuppressedOrDuplicateProfiles(): void
    {
        $curator = $this->key(100);
        $coordinate = '39089:' . $curator . ':Writers:MixedCase';
        $sources = $this->createMock(FollowPackSourceRepository::class);
        $sources->expects(self::once())->method('findByPurpose')->with(FollowPackPurpose::ESSAYIST_WRITERS)
            ->willReturn((new FollowPackSource())->setCoordinate($coordinate));
        $packs = $this->createMock(FollowPackService::class);
        $packs->expects(self::once())->method('getPubkeysFromCoordinate')->with($coordinate)
            ->willReturn([$this->key(1), strtoupper($this->key(1)), $this->key(2), $this->key(3), $this->key(4), 'invalid']);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('suppressedPubkeys')->with([$curator, $this->key(1), $this->key(4)])
            ->willReturn([$this->key(4)]);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::once())->method('getMultipleMetadata')->with([$this->key(1)])
            ->willReturn([$this->key(1) => new UserMetadata(displayName: 'Writer')]);
        $keys = new NostrKeyService();
        $service = new FeaturedWritersRecommendations($sources, $packs, $cache, $access, $keys, new NullLogger());

        $result = $service->load(1, [$this->key(2)], $this->key(3), '39089:' . $this->key(3) . ':personal');
        self::assertSame($keys->convertPublicKeyToBech32($curator), $result['packNpub']);
        self::assertSame('Writers:MixedCase', $result['packDtag']);
        self::assertFalse($result['personal']);
        self::assertSame($keys->convertPublicKeyToBech32($this->key(1)), $result['members'][0]['npub']);
        self::assertSame('Writer', $result['members'][0]['metadata']->displayName);
        self::assertCount(1, $result['members']);
    }

    public function testBannedCuratorHidesAllRecommendations(): void
    {
        $curator = $this->key(100);
        $sources = $this->createMock(FollowPackSourceRepository::class);
        $sources->method('findByPurpose')->willReturn((new FollowPackSource())->setCoordinate('39089:' . $curator . ':writers'));
        $packs = $this->createMock(FollowPackService::class);
        $packs->method('getPubkeysFromCoordinate')->willReturn([$this->key(1)]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->method('suppressedPubkeys')->willReturn([$curator]);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::never())->method('getMultipleMetadata');
        $service = new FeaturedWritersRecommendations($sources, $packs, $cache, $access, new NostrKeyService(), new NullLogger());
        self::assertNull($service->load(1, [], null, null));
    }

    public function testMissingOrInvalidPackDoesNotFetchMembers(): void
    {
        $sources = $this->createMock(FollowPackSourceRepository::class);
        $sources->method('findByPurpose')->willReturnOnConsecutiveCalls(null, (new FollowPackSource())->setCoordinate('30023:bad:pack'));
        $packs = $this->createMock(FollowPackService::class);
        $packs->expects(self::never())->method('getPubkeysFromCoordinate');
        $service = new FeaturedWritersRecommendations($sources, $packs, $this->createMock(RedisCacheService::class), $this->createMock(ContentAuthorAccessPolicy::class), new NostrKeyService(), new NullLogger());
        self::assertNull($service->load(1, [], null, null));
        self::assertNull($service->load(1, [], null, null));
    }

    public function testSelectedPackIsUsedWhenNoSitePackIsConfigured(): void
    {
        $curator = $this->key(100);
        $member = $this->key(1);
        $coordinate = '39089:' . $curator . ':personal';
        $sources = $this->createMock(FollowPackSourceRepository::class);
        $sources->method('findByPurpose')->willReturn(null);
        $packs = $this->createMock(FollowPackService::class);
        $packs->expects(self::once())->method('getPubkeysFromCoordinate')->with($coordinate)->willReturn([$curator, $member]);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->expects(self::once())->method('suppressedPubkeys')->with([$curator, $member])->willReturn([]);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::once())->method('getMultipleMetadata')->with([$member])->willReturn([]);
        $keys = new NostrKeyService();
        $service = new FeaturedWritersRecommendations($sources, $packs, $cache, $access, $keys, new NullLogger());

        $result = $service->load(1, [], $curator, $coordinate);
        self::assertTrue($result['personal']);
        self::assertCount(1, $result['members']);
        self::assertSame($keys->convertPublicKeyToBech32($member), $result['members'][0]['npub']);
    }

    public function testPagesRotateAndBoundMetadataFetch(): void
    {
        $curator = $this->key(100);
        $pubkeys = array_map(fn (int $i): string => $this->key($i), range(1, 50));
        $sources = $this->createMock(FollowPackSourceRepository::class);
        $sources->method('findByPurpose')->willReturn((new FollowPackSource())->setCoordinate('39089:' . $curator . ':writers'));
        $packs = $this->createMock(FollowPackService::class);
        $packs->method('getPubkeysFromCoordinate')->willReturn($pubkeys);
        $access = $this->createMock(ContentAuthorAccessPolicy::class);
        $access->method('suppressedPubkeys')->willReturn([]);
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::once())->method('getMultipleMetadata')->with(array_slice($pubkeys, 24, 24))->willReturn([]);
        $keys = new NostrKeyService();
        $service = new FeaturedWritersRecommendations($sources, $packs, $cache, $access, $keys, new NullLogger());
        $result = $service->load(2, [], $curator, null);
        self::assertCount(24, $result['members']);
        self::assertSame($keys->convertPublicKeyToBech32($pubkeys[24]), $result['members'][0]['npub']);
        self::assertNotEmpty($result['members'][0]['metadata']->name);
    }

    public function testBreaksDoNotConsumeRowsAndIncludeShortOrEmptyFeeds(): void
    {
        $service = new FeaturedWritersRecommendations(
            $this->createMock(FollowPackSourceRepository::class),
            $this->createMock(FollowPackService::class),
            $this->createMock(RedisCacheService::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
            new NostrKeyService(),
            new NullLogger(),
        );
        $members = array_map(fn (int $i): array => ['npub' => (string) $i, 'metadata' => new UserMetadata()], range(1, 8));
        self::assertSame([], $service->positionBreaks([], 20));
        self::assertSame([0 => $members], $service->positionBreaks($members, 0));
        self::assertSame([3 => $members], $service->positionBreaks($members, 3));
        self::assertSame([5 => $members], $service->positionBreaks($members, 14));
        $breaks = $service->positionBreaks($members, 20);
        self::assertSame([5, 15], array_keys($breaks));
        self::assertSame($members, [...$breaks[5], ...$breaks[15]]);
    }
}
