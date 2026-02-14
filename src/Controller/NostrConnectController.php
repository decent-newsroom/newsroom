<?php

namespace App\Controller;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Exception\ValidationException;
use Random\RandomException;
use swentel\nostr\Key\Key;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class NostrConnectController
{
    /**
     * Build a nostrconnect URI according to NIP-46 with explicit query params:
     *  - relay: one or more relay URLs (repeated param)
     *  - secret: short random string remote signer must echo back
     *  - name (optional): client application name
     *  - url (optional): canonical client url
     *
     * @throws RandomException
     * @throws ValidationException
     */
    #[Route('/nostr-connect/qr', name: 'nostr_connect_qr', methods: ['GET'])]
    public function qr(Request $request): JsonResponse
    {
        // Ephemeral key pair (client side session)
        $privkey = bin2hex(random_bytes(32));
        $key = new Key();
        $pubkey = $key->getPublicKey($privkey);

        // Relay list (extendable later; keep first as primary for backward compatibility)
        $relays = ['wss://relay.nsec.app','wss://relay.decentnewsroom.com'];

        // Short secret (remote signer should return as result of its connect response)
        $secret = substr(bin2hex(random_bytes(8)), 0, 12); // 12 hex chars (~48 bits truncated)

        $name = 'Decent Newsroom';
        $appUrl = $request->getSchemeAndHttpHost();

        // Build query string: multiple relay params + secret + name + url
        $queryParts = [];
        foreach ($relays as $r) {
            $queryParts[] = 'relay=' . rawurlencode($r);
        }
        $queryParts[] = 'secret=' . rawurlencode($secret);
        $queryParts[] = 'name=' . rawurlencode($name);
        $queryParts[] = 'url=' . rawurlencode($appUrl);
        $query = implode('&', $queryParts);

        $uri = sprintf('nostrconnect://%s?%s', $pubkey, $query);

        // Generate QR using default config
        $qrResult = (new Builder())->build(data: $uri);
        $dataUri = $qrResult->getDataUri();

        return new JsonResponse([
            'uri' => $uri,
            'qr' => $dataUri,
            'pubkey' => $pubkey,
            'privkey' => $privkey, // sent to browser for nip04 encryption/decryption (ephemeral only)
            'relay' => $relays[0],  // maintain existing single relay field for current JS
            'relays' => $relays,
            'secret' => $secret,
            'name' => $name,
            'url' => $appUrl,
        ]);
    }
}
