<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Unfold\PublicationAdminIdentityAdapter;
use App\Unfold\PublicationAdminLogin;
use DecentNewsroom\UnfoldBundle\Contract\SiteRegistryInterface;
use Innis\Nostr\Core\Domain\Service\Bech32Codec;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

final class PublicationAdminIdentityAdapterTest extends TestCase
{
    public function testAnonymousIdentityReturnsNull(): void
    {
        self::assertNull($this->adapter(null)->pubkey());
    }

    public function testNpubIdentityIsConvertedToNormalizedHex(): void
    {
        $hex = str_repeat('ab', 32);
        $npub = PublicKey::fromHex($hex)->toBech32();

        self::assertSame($hex, $this->adapter($npub)->pubkey());
        self::assertSame($hex, $this->adapter(strtoupper($npub))->pubkey());
    }

    /** @dataProvider invalidIdentifiers */
    public function testMalformedAuthenticatedIdentityFailsClosed(string $identifier): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->adapter($identifier)->pubkey();
    }

    public static function invalidIdentifiers(): iterable
    {
        yield 'empty' => [''];
        yield 'arbitrary username' => ['owner@example.test'];
        yield 'hex instead of account npub' => [str_repeat('ab', 32)];
        yield 'invalid checksum' => ['npub1notavalidkey'];
        yield 'short payload with valid checksum' => [Bech32Codec::encode('npub', [1, 2, 3])];
    }

    public function testLoginUsesHostLoginIntegration(): void
    {
        $request = Request::create('https://journal.example.test/admin/settings');
        $url = $this->adapter(null)->loginUrl($request);
        self::assertStringStartsWith('https://example.test/login?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        self::assertSame('https://journal.example.test/admin/settings', $query['unfold_return']);
    }

    private function adapter(?string $identifier): PublicationAdminIdentityAdapter
    {
        $user = null;
        if ($identifier !== null) {
            $user = $this->createMock(UserInterface::class);
            $user->method('getUserIdentifier')->willReturn($identifier);
        }
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return new PublicationAdminIdentityAdapter(
            $security,
            new PublicationAdminLogin($this->createMock(SiteRegistryInterface::class), 'example.test'),
        );
    }
}
