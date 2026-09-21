<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Dto\UserMetadata;
use App\Service\Cache\RedisCacheService;
use App\Service\ProfileUpdateDispatcher;
use App\Twig\Components\Molecules\UserFromNpub;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;

class UserFromNpubTest extends TestCase
{
    private const PUBKEY = 'aa9047325603dacd4f8142093567973566de3b1e20a89557b728c3be4c6a844b';

    private function npub(): string
    {
        return PublicKey::fromHex(self::PUBKEY)?->toBech32()
            ?? throw new \LogicException('The fixture pubkey must be valid.');
    }

    public function testUsesValidRelayHintForProfileRefresh(): void
    {
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects($this->once())
            ->method('getMetadata')
            ->with(self::PUBKEY)
            ->willReturn(UserMetadata::createDefault(self::PUBKEY));

        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects($this->once())
            ->method('dispatch')
            ->with(self::PUBKEY, ['wss://relay.example'])
            ->willReturn(true);

        $component = new UserFromNpub($cache, $dispatcher);
        $component->mount($this->npub(), relayHint: 'wss://relay.example');

        self::assertSame($this->npub(), $component->npub);
    }

    public function testIgnoresNonRelayHint(): void
    {
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects($this->once())
            ->method('getMetadata')
            ->with(self::PUBKEY)
            ->willReturn(UserMetadata::createDefault(self::PUBKEY));

        $dispatcher = $this->createMock(ProfileUpdateDispatcher::class);
        $dispatcher->expects($this->never())->method('dispatch');

        $component = new UserFromNpub($cache, $dispatcher);
        $component->mount($this->npub(), relayHint: 'https://relay.example');

        self::assertSame($this->npub(), $component->npub);
    }

    public function testRejectsNprofileIdentifiers(): void
    {
        $component = new UserFromNpub(
            $this->createMock(RedisCacheService::class),
            $this->createMock(ProfileUpdateDispatcher::class),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('UserFromNpub expects npub or hex pubkey');

        $component->mount('nprofile1qqsp8j8tj');
    }
}
