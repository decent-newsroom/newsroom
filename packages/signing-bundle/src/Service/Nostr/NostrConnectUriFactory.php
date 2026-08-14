<?php

declare(strict_types=1);

namespace DecentNewsroom\SigningBundle\Service\Nostr;

use DecentNewsroom\SigningBundle\Contract\SignerRelayProviderInterface;
use Endroid\QrCode\Builder\Builder;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Symfony\Component\HttpFoundation\Request;

final readonly class NostrConnectUriFactory
{
    /**
     * @param list<string> $requestedPermissions
     */
    public function __construct(
        private SignerRelayProviderInterface $relayProvider,
        private SignatureServiceInterface $signatureService,
        private string $appName,
        private ?string $configuredAppUrl,
        private array $requestedPermissions,
    ) {
    }

    /** @return array<string, mixed> */
    public function create(Request $request): array
    {
        $privateKey = PrivateKey::generate();
        $privkeyHex = $privateKey->toHex();
        $clientPubkeyHex = $this->signatureService->derivePublicKey($privateKey)->toHex();

        $relays = $this->relayProvider->getSignerRelays();
        $secret = substr(bin2hex(random_bytes(8)), 0, 12);
        $appUrl = $this->configuredAppUrl ?: $request->getSchemeAndHttpHost();
        $permissions = implode(',', $this->requestedPermissions);

        $queryParts = [];
        foreach ($relays as $relay) {
            $queryParts[] = 'relay='.rawurlencode($relay);
        }
        $queryParts[] = 'secret='.rawurlencode($secret);
        $queryParts[] = 'perms='.rawurlencode($permissions);
        $queryParts[] = 'name='.rawurlencode($this->appName);
        $queryParts[] = 'url='.rawurlencode($appUrl);

        $uri = sprintf('nostrconnect://%s?%s', $clientPubkeyHex, implode('&', $queryParts));
        $qrResult = (new Builder())->build(data: $uri);

        return [
            'uri' => $uri,
            'qr' => $qrResult->getDataUri(),
            'pubkey' => $clientPubkeyHex,
            'privkey' => $privkeyHex,
            'relay' => $relays[0] ?? null,
            'relays' => $relays,
            'secret' => $secret,
            'name' => $this->appName,
            'url' => $appUrl,
            'permissions' => $permissions,
        ];
    }
}
