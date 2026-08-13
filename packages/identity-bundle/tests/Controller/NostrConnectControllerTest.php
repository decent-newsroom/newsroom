<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Tests\Controller;

use DecentNewsroom\IdentityBundle\Contract\SignerRelayProviderInterface;
use DecentNewsroom\IdentityBundle\Controller\NostrConnectController;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class NostrConnectControllerTest extends TestCase
{
    public function testItBuildsNostrConnectQrPayload(): void
    {
        $controller = new NostrConnectController(
            new StaticSignerRelayProvider(['wss://relay.example', 'wss://relay2.example']),
            Secp256k1SignatureAdapter::create(),
        );

        $response = $controller->qr(Request::create('https://news.example/nostr-connect/qr'));
        $payload = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertStringStartsWith('nostrconnect://', $payload['uri']);
        self::assertStringContainsString('relay=wss%3A%2F%2Frelay.example', $payload['uri']);
        self::assertStringContainsString('perms=sign_event%3A27235%2Cget_public_key', $payload['uri']);
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