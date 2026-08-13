<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Security\IdentityProvider;

use DecentNewsroom\IdentityBundle\Security\IdentityProvider\NostrIdentityProvider;
use DecentNewsroom\NostrKernelBundle\Application\Auth\ValidateNostrHttpAuth;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisHttpAuthValidator;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisSignatureVerifier;
use Innis\Nostr\Core\Domain\Entity\Event as InnisEvent;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

final class NostrIdentityProviderTest extends TestCase
{
    public function testItSupportsNostrLoginRequests(): void
    {
        $request = Request::create('/login', 'POST');
        $request->headers->set('Authorization', 'Nostr ' . \base64_encode('{}'));

        self::assertTrue($this->provider()->supports($request));
    }

    public function testItAuthenticatesValidNip98EventsAndStoresSignMethod(): void
    {
        $signatureService = Secp256k1SignatureAdapter::create();
        $keyPair = KeyPair::fromPrivateKey(PrivateKey::generate(), $signatureService);
        $request = Request::create('/login', 'GET');
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->headers->set('Authorization', $this->authHeader(
            $keyPair,
            $signatureService,
            'GET',
            'http://localhost/login',
            [['t', 'extension']],
        ));

        $pubkey = $this->provider()->authenticate($request);

        self::assertSame($keyPair->getPublicKey()->toHex(), $pubkey);
        self::assertSame('extension', $request->getSession()->get('nostr_sign_method'));
    }

    public function testItRejectsInvalidBase64(): void
    {
        $request = Request::create('/login', 'GET');
        $request->headers->set('Authorization', 'Nostr not-valid-base64!');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid base64 encoding');

        $this->provider()->authenticate($request);
    }

    public function testItRejectsMismatchedUrl(): void
    {
        $signatureService = Secp256k1SignatureAdapter::create();
        $keyPair = KeyPair::fromPrivateKey(PrivateKey::generate(), $signatureService);
        $request = Request::create('/login', 'GET');
        $request->headers->set('Authorization', $this->authHeader(
            $keyPair,
            $signatureService,
            'GET',
            'https://example.test/login',
        ));

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('URL tag does not match request URL');

        $this->provider()->authenticate($request);
    }

    /**
     * @param list<list<string>> $extraTags
     */
    private function authHeader(
        KeyPair $keyPair,
        Secp256k1SignatureAdapter $signatureService,
        string $method,
        string $url,
        array $extraTags = [],
    ): string {
        $event = InnisEvent::fromArray([
            'pubkey' => $keyPair->getPublicKey()->toHex(),
            'created_at' => \time(),
            'kind' => 27235,
            'tags' => \array_merge([['u', $url], ['method', $method]], $extraTags),
            'content' => '',
        ])->sign($keyPair, $signatureService);

        return 'Nostr ' . \base64_encode(\json_encode($event->toArray(), \JSON_THROW_ON_ERROR));
    }

    private function provider(): NostrIdentityProvider
    {
        $signatureService = Secp256k1SignatureAdapter::create();

        return new NostrIdentityProvider(new ValidateNostrHttpAuth(
            new InnisHttpAuthValidator(new InnisSignatureVerifier($signatureService)),
        ));
    }
}
