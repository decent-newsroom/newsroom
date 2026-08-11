<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\ReadingNookController;
use App\Dto\UserMetadata;
use App\Enum\KindsEnum;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ReadingNookControllerTest extends TestCase
{
    public function testNpubSubscriptionUsesProfileDisplayName(): void
    {
        $pubkey = str_repeat('a', 64);
        $metadata = new UserMetadata(name: 'fallback-name', displayName: 'Profile Name');
        $controller = $this->controllerWithMetadata($pubkey, $metadata);

        self::assertSame('Profile Name', $this->resolveTitle($controller, $pubkey));
    }

    public function testNpubSubscriptionAcceptsNpubAndFallsBackToProfileName(): void
    {
        $pubkey = str_repeat('b', 64);
        $npub = (static function (string $pubkey): string { return PublicKey::fromHex(strtolower(trim($pubkey)))?->toBech32() ?? throw new \InvalidArgumentException('Not a valid hex pubkey'); })((string) ($pubkey));
        $metadata = new UserMetadata(name: 'profile-name');
        $controller = $this->controllerWithMetadata($pubkey, $metadata);

        self::assertSame('profile-name', $this->resolveTitle($controller, $npub));
    }

    public function testNpubSubscriptionWithoutProfileNameUsesExistingFallback(): void
    {
        $pubkey = str_repeat('c', 64);
        $controller = $this->controllerWithMetadata($pubkey, new UserMetadata());

        self::assertNull($this->resolveTitle($controller, $pubkey));
    }

    public function testDefaultBookmarkListTitleIsBookmarks(): void
    {
        $controller = new ReadingNookController($this->createMock(RedisCacheService::class));
        $method = new \ReflectionMethod($controller, 'defaultTitleForKind');

        self::assertSame('Bookmarks', $method->invoke($controller, KindsEnum::BOOKMARKS->value));
    }

    private function controllerWithMetadata(string $pubkey, UserMetadata $metadata): ReadingNookController
    {
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects(self::once())
            ->method('getMetadata')
            ->with($pubkey)
            ->willReturn($metadata);

        return new ReadingNookController($cache);
    }

    private function resolveTitle(ReadingNookController $controller, string $sourceValue): ?string
    {
        $method = new \ReflectionMethod($controller, 'resolveSubscriptionTitle');

        return $method->invoke(
            $controller,
            UpdateSourceTypeEnum::NPUB,
            $sourceValue,
            $this->createMock(EventRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }
}
