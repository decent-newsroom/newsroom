<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

readonly class Nip05VerificationService
{
    private const int CACHE_TTL = 3600; // 1 hour
    private const int REQUEST_TIMEOUT = 5; // 5 seconds

    public function __construct(
        private CacheInterface $redisCache,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Verify a NIP-05 identifier against a public key
     *
     * @param string $nip05 The NIP-05 identifier (e.g., "bob@example.com")
     * @param string $pubkeyHex The public key in hex format
     * @return array{verified: bool, relays: array<string>}
     */
    public function verify(string $nip05, string $pubkeyHex): array
    {
        // Validate the identifier format
        if (!$this->isValidIdentifier($nip05)) {
            $this->logger->warning('Invalid NIP-05 identifier format', ['nip05' => $nip05]);
            return ['verified' => false, 'relays' => []];
        }

        // Split identifier into local part and domain
        [$localPart, $domain] = $this->splitIdentifier($nip05);

        // Create cache key
        $cacheKey = 'nip05_' . md5($nip05);

        try {
            return $this->redisCache->get($cacheKey, function (ItemInterface $item) use ($localPart, $domain, $pubkeyHex, $nip05) {
                $item->expiresAfter(self::CACHE_TTL);

                $wellKnownUrl = "https://{$domain}/.well-known/nostr.json?name=" . urlencode(strtolower($localPart));

                // Fetch the well-known document
                $result = $this->fetchWellKnown($wellKnownUrl);

                if (!$result['success']) {
                    return ['verified' => false, 'relays' => []];
                }

                $data = $result['data'];

                // Check if names field exists
                if (!isset($data['names'])) {
                    $this->logger->warning('Missing names field in well-known response', ['nip05' => $nip05]);
                    return ['verified' => false, 'relays' => []];
                }

                // Check if the name exists and matches
                $normalizedLocalPart = strtolower($localPart);
                if (!isset($data['names'][$normalizedLocalPart])) {
                    $this->logger->info('Name not found in well-known response', [
                        'nip05' => $nip05,
                        'localPart' => $normalizedLocalPart
                    ]);
                    return ['verified' => false, 'relays' => []];
                }

                $returnedPubkey = $data['names'][$normalizedLocalPart];

                // Validate hex format
                if (!$this->isValidHexPubkey($returnedPubkey)) {
                    $this->logger->warning('Invalid pubkey format in well-known response', [
                        'nip05' => $nip05,
                        'pubkey' => $returnedPubkey
                    ]);
                    return ['verified' => false, 'relays' => []];
                }

                // Check if pubkeys match
                if (strtolower($returnedPubkey) !== strtolower($pubkeyHex)) {
                    $this->logger->info('Pubkey mismatch in NIP-05 verification', [
                        'nip05' => $nip05,
                        'expected' => $pubkeyHex,
                        'received' => $returnedPubkey
                    ]);
                    return ['verified' => false, 'relays' => []];
                }

                // Extract relay information if available
                $relays = [];
                if (isset($data['relays'][$returnedPubkey]) && is_array($data['relays'][$returnedPubkey])) {
                    $relays = $data['relays'][$returnedPubkey];
                }

                $this->logger->info('NIP-05 verification successful', [
                    'nip05' => $nip05,
                    'pubkey' => $pubkeyHex,
                    'relays' => count($relays)
                ]);

                return ['verified' => true, 'relays' => $relays];
            });
        } catch (\Exception $e) {
            $this->logger->error('Error during NIP-05 verification', [
                'nip05' => $nip05,
                'error' => $e->getMessage()
            ]);
            return ['verified' => false, 'relays' => []];
        }
    }

    /**
     * Fetch and parse the well-known nostr.json document
     */
    private function fetchWellKnown(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => self::REQUEST_TIMEOUT,
                'follow_location' => 0, // Do NOT follow redirects (NIP-05 security requirement)
                'ignore_errors' => true,
                'header' => 'Accept: application/json'
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        // Check for redirects in response headers
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\d\.\d\s+(301|302|303|307|308)/', $header)) {
                    $this->logger->warning('NIP-05 verification rejected due to redirect', ['url' => $url]);
                    return ['success' => false, 'data' => null];
                }
            }
        }

        if ($response === false) {
            $this->logger->warning('Failed to fetch well-known document', ['url' => $url]);
            return ['success' => false, 'data' => null];
        }

        $data = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->logger->warning('Invalid JSON in well-known response', [
                'url' => $url,
                'error' => json_last_error_msg()
            ]);
            return ['success' => false, 'data' => null];
        }

        return ['success' => true, 'data' => $data];
    }

    /**
     * Validate NIP-05 identifier format
     * Local part must contain only: a-z0-9-_.
     */
    private function isValidIdentifier(string $identifier): bool
    {
        if (!str_contains($identifier, '@')) {
            return false;
        }

        [$localPart, $domain] = explode('@', $identifier, 2);

        // Validate local part (case-insensitive a-z0-9-_.)
        if (!preg_match('/^[a-zA-Z0-9\-_.]+$/', $localPart)) {
            return false;
        }

        // Validate domain (basic check)
        if (empty($domain) || !str_contains($domain, '.')) {
            return false;
        }

        return true;
    }

    /**
     * Split identifier into local part and domain
     */
    private function splitIdentifier(string $identifier): array
    {
        return explode('@', $identifier, 2);
    }

    /**
     * Validate that the pubkey is in hex format (not npub)
     */
    private function isValidHexPubkey(string $pubkey): bool
    {
        // Must be 64 character hex string
        return preg_match('/^[0-9a-fA-F]{64}$/', $pubkey) === 1;
    }

    /**
     * Format identifier for display
     * "_@domain.com" should be displayed as "domain.com"
     */
    public function formatForDisplay(string $nip05): string
    {
        if (str_starts_with($nip05, '_@')) {
            return substr($nip05, 2);
        }

        return $nip05;
    }
}

