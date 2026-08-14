<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Controller;

use DecentNewsroom\IdentityBundle\Contract\SignerRelayProviderInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Exception\ValidationException;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Random\RandomException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class NostrConnectController
{
    public const REQUESTED_PERMISSIONS = 'sign_event:27235,sign_event:22242,get_public_key';

    public function __construct(
        private SignerRelayProviderInterface $relayProvider,
        private SignatureServiceInterface $signatureService,
    ) {
    }

    /**
     * Build a nostrconnect URI according to NIP-46 with explicit query params:
     * relay, secret, perms, name, and url.
     *
     * @throws RandomException
     * @throws ValidationException
     */
    #[Route('/nostr-connect/qr', name: 'nostr_connect_qr', methods: ['GET'])]
    public function qr(Request $request): JsonResponse
    {
        $privateKey = PrivateKey::generate();
        $privkeyHex = $privateKey->toHex();
        $pubkey = $this->signatureService->derivePublicKey($privateKey)->toHex();

        $relays = $this->relayProvider->getSignerRelays();
        $secret = substr(bin2hex(random_bytes(8)), 0, 12);
        $name = 'Decent Newsroom';
        $appUrl = $request->getSchemeAndHttpHost();

        $queryParts = [];
        foreach ($relays as $relay) {
            $queryParts[] = 'relay=' . rawurlencode($relay);
        }
        $queryParts[] = 'secret=' . rawurlencode($secret);
        $queryParts[] = 'perms=' . rawurlencode(self::REQUESTED_PERMISSIONS);
        $queryParts[] = 'name=' . rawurlencode($name);
        $queryParts[] = 'url=' . rawurlencode($appUrl);

        $uri = sprintf('nostrconnect://%s?%s', $pubkey, implode('&', $queryParts));
        $qrResult = (new Builder())->build(data: $uri);

        return new JsonResponse([
            'uri' => $uri,
            'qr' => $qrResult->getDataUri(),
            'pubkey' => $pubkey,
            'privkey' => $privkeyHex,
            'relay' => $relays[0] ?? null,
            'relays' => $relays,
            'secret' => $secret,
            'name' => $name,
            'url' => $appUrl,
        ]);
    }
}
