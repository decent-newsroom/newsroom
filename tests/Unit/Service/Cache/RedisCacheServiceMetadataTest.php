<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Cache;

use App\Dto\UserMetadata;
use App\Entity\User;
use App\Repository\UserEntityRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrClient;
use App\Service\ProfileUpdateDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;

final class RedisCacheServiceMetadataTest extends TestCase
{
    private const PUBKEY = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testLocalOnlyCacheMissReturnsDefaultWithoutDispatchOrWrites(): void
    {
        $this->assertCacheMiss(refreshOnMiss: false);
    }

    public function testCacheMissStillDispatchesByDefault(): void
    {
        $this->assertCacheMiss(refreshOnMiss: null);
    }

    private function assertCacheMiss(?bool $refreshOnMiss): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::once())->method('getItem')->with('0_' . self::PUBKEY)->willReturn($item);
        $pool->expects(self::never())->method('save');
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::once())->method('findOneBy')->with(['npub' => $this->npub()])->willReturn(null);
        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects($refreshOnMiss === null ? self::once() : self::never())
            ->method('dispatch')->with(self::PUBKEY)->willReturn(true);
        $service = $this->service($pool, $users, $dispatcher);

        $metadata = $refreshOnMiss === null
            ? $service->getMetadata(self::PUBKEY)
            : $service->getMetadata(self::PUBKEY, $refreshOnMiss);

        self::assertSame(UserMetadata::createDefault(self::PUBKEY)->name, $metadata->name);
    }

    public function testLocalOnlyLookupUsesCachedProfileWithoutDatabaseOrDispatch(): void
    {
        $cached = (object) ['display_name' => 'Cached author'];
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(true);
        $item->method('get')->willReturn($cached);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->expects(self::never())->method('save');
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::never())->method('findOneBy');
        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $metadata = $this->service($pool, $users, $dispatcher)->getMetadata(self::PUBKEY, false);

        self::assertSame('Cached author', $metadata->displayName);
    }

    public function testLocalOnlyLookupFallsBackToExistingUserProjection(): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->method('getItem')->willReturn($item);
        $pool->expects(self::never())->method('save');
        $user = new User();
        $user->setNpub($this->npub());
        $user->setDisplayName('Local author');
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::once())->method('findOneBy')->with(['npub' => $this->npub()])->willReturn($user);
        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects(self::never())->method('dispatch');

        $metadata = $this->service($pool, $users, $dispatcher)->getMetadata(self::PUBKEY, false);

        self::assertSame('Local author', $metadata->displayName);
    }

    /** @dataProvider batchRefreshModes */
    public function testBatchLookupCanSkipRefreshForMissingProfiles(?bool $refreshOnMiss, bool $dispatch): void
    {
        $item = $this->createMock(CacheItemInterface::class);
        $item->method('isHit')->willReturn(false);
        $pool = $this->createMock(CacheItemPoolInterface::class);
        $pool->expects(self::once())->method('getItems')
            ->with(['0_' . self::PUBKEY])->willReturn(['0_' . self::PUBKEY => $item]);
        $pool->expects(self::never())->method('save');
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::once())->method('findByNpubs')->with([$this->npub()])->willReturn([]);
        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects($dispatch ? self::once() : self::never())
            ->method('dispatchBatch')->with([self::PUBKEY])->willReturn(1);
        $service = $this->service($pool, $users, $dispatcher);

        $metadata = $refreshOnMiss === null
            ? $service->getMultipleMetadata([self::PUBKEY])
            : $service->getMultipleMetadata([self::PUBKEY], $refreshOnMiss);

        self::assertSame(UserMetadata::createDefault(self::PUBKEY)->name, $metadata[self::PUBKEY]->name);
    }

    public static function batchRefreshModes(): iterable
    {
        yield 'default refresh' => [null, true];
        yield 'local only' => [false, false];
    }

    private function npub(): string
    {
        return PublicKey::fromHex(self::PUBKEY)?->toBech32()
            ?? throw new \LogicException('The fixture pubkey must be valid.');
    }

    private function service(
        CacheItemPoolInterface $pool,
        UserEntityRepository $users,
        ProfileUpdateDispatcher $dispatcher,
    ): RedisCacheService {
        return new RedisCacheService(
            $this->createMock(NostrClient::class),
            $pool,
            $this->createMock(EntityManagerInterface::class),
            new NullLogger(),
            $dispatcher,
            $users,
        );
    }
}
