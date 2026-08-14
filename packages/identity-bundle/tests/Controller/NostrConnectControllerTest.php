<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Controller;

use DecentNewsroom\IdentityBundle\Contract\SignerRelayProviderInterface;
use DecentNewsroom\IdentityBundle\Controller\NostrConnectController;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Innis\Nostr\Core\Domain\ValueObject\Identity\Signature;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NostrConnectControllerTest extends TestCase
{
    public function testItRequestsRelayAuthSigningPermission(): void
    {
        self::assertSame('sign_event:27235,sign_event:22242,get_public_key', NostrConnectController::REQUESTED_PERMISSIONS);
    }

    public function testItBuildsNostrConnectQrPayload(): void
    {
        if (!extension_loaded('sodium')) {
            self::markTestSkipped('Native sodium extension is required to generate the QR session key.');
        }

        $controller = new NostrConnectController(
            new StaticSignerRelayProvider(['wss://relay.example', 'wss://relay2.example']),
            new StaticSignatureService(),
        );

        $response = $controller->qr(Request::create('https://news.example/nostr-connect/qr'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertStringStartsWith('nostrconnect://', $payload['uri']);
        self::assertStringContainsString('relay=wss%3A%2F%2Frelay.example', $payload['uri']);
        self::assertStringContainsString('perms=' . rawurlencode(NostrConnectController::REQUESTED_PERMISSIONS), $payload['uri']);
        self::assertSame('wss://relay.example', $payload['relay']);
        self::assertSame(['wss://relay.example', 'wss://relay2.example'], $payload['relays']);
        self::assertSame('Decent Newsroom', $payload['name']);
        self::assertSame('https://news.example', $payload['url']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['privkey']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['pubkey']);
        self::assertStringStartsWith('data:image/png;base64,', $payload['qr']);
    }
}

final readonly class StaticSignerRelayProvider implements SignerRelayProviderInterface
{
    /**
     * @param string[] $relays
     */
    public function __construct(private array $relays)
    {
    }

    public function getSignerRelays(): array
    {
        return $this->relays;
    }
}

final readonly class StaticSignatureService implements SignatureServiceInterface
{
    public function sign(PrivateKey $privateKey, string $message): Signature
    {
        return Signature::fromHex(str_repeat('3', 128)) ?? throw new \RuntimeException('Invalid test signature.');
    }

    public function verify(PublicKey $publicKey, string $message, Signature $signature): bool
    {
        return true;
    }

    public function derivePublicKey(PrivateKey $privateKey): PublicKey
    {
        return PublicKey::fromHex(str_repeat('4', 64)) ?? throw new \RuntimeException('Invalid test public key.');
    }
}
