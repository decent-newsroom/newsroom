<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Repository\UserEntityRepository;
use App\Security\AppIdentityBridge;
use App\Service\Nostr\NostrIdentityService;
use DecentNewsroom\IdentityBundle\Entity\UserIdentityLink;
use DecentNewsroom\IdentityBundle\Repository\UserIdentityLinkRepository;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisNip19Decoder;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisNip19Encoder;
use Doctrine\ORM\EntityManagerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Infrastructure\Adapter\Bech32EncoderAdapter;
use PHPUnit\Framework\TestCase;

final class AppIdentityBridgeTest extends TestCase
{
    public function testFirstNostrLoginCreatesUserWithNpubAndVerifiedLink(): void
    {
        $hexPubkey = str_repeat('a', 64);
        $npub = PublicKey::fromHex($hexPubkey)?->toBech32();
        self::assertNotNull($npub);

        $userRepository = $this->createMock(UserEntityRepository::class);
        $userRepository->expects(self::once())
            ->method('findOneBy')
            ->with(['npub' => $npub])
            ->willReturn(null);

        $links = $this->createMock(UserIdentityLinkRepository::class);
        $links->expects(self::once())
            ->method('findOneByProviderAndExternalId')
            ->with('nostr', $hexPubkey)
            ->willReturn(null);

        $persistedUser = null;
        $persistedLink = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(static function (object $entity) use (&$persistedUser, &$persistedLink): bool {
                if ($entity instanceof User) {
                    $entity->setId(42);
                    $persistedUser = $entity;

                    return true;
                }

                if ($entity instanceof UserIdentityLink) {
                    $persistedLink = $entity;

                    return true;
                }

                return false;
            }));
        $entityManager->expects(self::exactly(2))->method('flush');

        $bridge = new AppIdentityBridge(
            $userRepository,
            $links,
            $this->identityService(),
            $entityManager,
        );

        $user = $bridge->findOrCreateByIdentity('nostr', $hexPubkey);

        self::assertSame($persistedUser, $user);
        self::assertSame($npub, $user->getNpub());
        self::assertNull($user->getLocalIdentifier());
        self::assertInstanceOf(UserIdentityLink::class, $persistedLink);
        self::assertSame('42', $persistedLink->getOwnerId());
        self::assertSame('nostr', $persistedLink->getProvider());
        self::assertSame($hexPubkey, $persistedLink->getExternalId());
        self::assertNotNull($persistedLink->getVerifiedAt());
    }

    public function testFirstNonNostrLoginCreatesLocalIdentifierUser(): void
    {
        $userRepository = $this->createMock(UserEntityRepository::class);
        $userRepository->expects(self::never())->method('findOneBy');

        $links = $this->createMock(UserIdentityLinkRepository::class);
        $links->method('findOneByProviderAndExternalId')->willReturn(null);

        $persistedUser = null;
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))
            ->method('persist')
            ->with(self::callback(static function (object $entity) use (&$persistedUser): bool {
                if ($entity instanceof User) {
                    $entity->setId(77);
                    $persistedUser = $entity;
                }

                return $entity instanceof User || $entity instanceof UserIdentityLink;
            }));
        $entityManager->expects(self::exactly(2))->method('flush');

        $bridge = new AppIdentityBridge(
            $userRepository,
            $links,
            $this->identityService(),
            $entityManager,
        );

        $user = $bridge->findOrCreateByIdentity('email_otp', 'reader@example.test');

        self::assertSame($persistedUser, $user);
        self::assertNull($user->getNpub());
        self::assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) $user->getLocalIdentifier());
    }

    private function identityService(): NostrIdentityService
    {
        $encoder = new Bech32EncoderAdapter();

        return new NostrIdentityService(
            new InnisNip19Decoder($encoder),
            new InnisNip19Encoder($encoder),
        );
    }
}
