<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Visit;
use App\Repository\VisitRepository;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use function tkijewski\lnurl\decodeUrl;

/**
 * Resolves Lightning Addresses (LUD-16) and LNURL-pay (LUD-06) endpoints
 * to obtain LNURL-pay info for NIP-57 zaps
 */
class LNURLResolver
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly VisitRepository $visitRepository,
    ) {}

    /**
     * Resolve a Lightning Address (name@domain) or lnurl to LNURL-pay info
     *
     * @param string|null $lud16 Lightning Address (e.g., "alice@example.com")
     * @param string|null $lud06 LNURL bech32 string (e.g., "lnurl1...")
     * @return object Object with callback, minSendable, maxSendable, allowsNostr, nostrPubkey, bech32
     * @throws \RuntimeException If resolution fails
     */
    public function resolve(?string $lud16 = null, ?string $lud06 = null): object
    {
        if (!$lud16 && !$lud06) {
            throw new \RuntimeException('No Lightning Address or LNURL provided');
        }

        try {
            // Prefer LUD-16 (Lightning Address) over LUD-06
            if ($lud16) {
                return $this->resolveLightningAddress($lud16);
            }

            return $this->resolveLnurl($lud06);
        } catch (\Exception $e) {
            $this->logger->error('LNURL resolution failed', [
                'lud16' => $lud16,
                'lud06' => $lud06,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Could not resolve Lightning endpoint: ' . $e->getMessage());
        }
    }

    /**
     * Resolve a Lightning Address (LUD-16)
     */
    private function resolveLightningAddress(string $address): object
    {
        if (!preg_match('/^(.+)@(.+)$/', $address, $matches)) {
            throw new \RuntimeException('Invalid Lightning Address format');
        }

        [$_, $name, $domain] = $matches;
        $url = sprintf('https://%s/.well-known/lnurlp/%s', $domain, $name);

        try {
            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Lightning Address endpoint returned status ' . $response->getStatusCode());
            }

            $data = $response->toArray();
            return $this->parseLnurlPayResponse($data, null);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to fetch Lightning Address info: ' . $e->getMessage());
        }
    }

    /**
     * Resolve a bech32 LNURL (LUD-06)
     */
    private function resolveLnurl(string $lnurl): object
    {
        try {
            // Decode bech32 LNURL to get the actual URL
            $decoded = decodeUrl($lnurl);
            $url = $decoded['url'] ?? '';

            if (empty($url)) {
                throw new \RuntimeException('Could not decode LNURL');
            }

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 10,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('LNURL endpoint returned status ' . $response->getStatusCode());
            }

            $data = $response->toArray();
            return $this->parseLnurlPayResponse($data, $lnurl);
        } catch (\Exception $e) {
            throw new \RuntimeException('Failed to decode or fetch LNURL: ' . $e->getMessage());
        }
    }

    /**
     * Parse LNURL-pay response and validate required fields
     */
    private function parseLnurlPayResponse(array $data, ?string $bech32): object
    {
        // Validate required LNURL-pay fields
        if (!isset($data['callback']) || !isset($data['minSendable']) || !isset($data['maxSendable'])) {
            throw new \RuntimeException('Invalid LNURL-pay response: missing required fields');
        }

        if (isset($data['tag']) && $data['tag'] !== 'payRequest') {
            throw new \RuntimeException('Not a LNURL-pay endpoint');
        }

        // NIP-57 specific fields
        $allowsNostr = isset($data['allowsNostr']) && $data['allowsNostr'] === true;
        $nostrPubkey = $data['nostrPubkey'] ?? null;

        return (object) [
            'callback' => $data['callback'],
            'minSendable' => (int) $data['minSendable'],
            'maxSendable' => (int) $data['maxSendable'],
            'allowsNostr' => $allowsNostr,
            'nostrPubkey' => $nostrPubkey,
            'metadata' => $data['metadata'] ?? '[]',
            'bech32' => $bech32,
        ];
    }

    /**
     * Request a BOLT11 invoice from the LNURL callback
     *
     * @param string $callback The callback URL from LNURL-pay info
     * @param int $amountMillisats Amount in millisatoshis
     * @param string|null $nostrEvent Signed NIP-57 zap request event (JSON)
     * @param string|null $lnurl Original LNURL bech32 (if available)
     * @return string BOLT11 invoice
     * @throws \RuntimeException If invoice request fails
     */
    public function requestInvoice(
        string $callback,
        int $amountMillisats,
        ?string $nostrEvent = null,
        ?string $lnurl = null
    ): string {
        try {
            // Build query parameters
            $params = ['amount' => $amountMillisats];

            if ($nostrEvent !== null) {
                // URL-encode the nostr event JSON
                $params['nostr'] = $nostrEvent;
            }

            if ($lnurl !== null) {
                $params['lnurl'] = $lnurl;
            }

            // Some LNURL services expect the callback URL to already contain query params
            $separator = str_contains($callback, '?') ? '&' : '?';
            $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $url = $callback . $separator . $queryString;

            // Log if URL is very long (might cause issues with some servers)
            if (strlen($url) > 2000) {
                $this->logger->warning('LNURL callback URL is very long', [
                    'url_length' => strlen($url),
                    'callback' => $callback,
                    'has_nostr' => $nostrEvent !== null,
                ]);
            }

            $response = $this->httpClient->request('GET', $url, [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode !== 200) {
                // Try to get error details from response body
                $errorBody = null;
                try {
                    $errorBody = $response->toArray(false);
                } catch (\Exception $e) {
                    $errorBody = $response->getContent(false);
                }

                $this->logger->error('LNURL callback returned non-200 status', [
                    'status' => $statusCode,
                    'url' => $callback, // Don't log full URL with params for security
                    'response_body' => $errorBody,
                ]);

                $errorMessage = is_array($errorBody) && isset($errorBody['reason'])
                    ? $errorBody['reason']
                    : 'Callback returned status ' . $statusCode;

                throw new \RuntimeException($errorMessage);
            }

            $data = $response->toArray();

            // Check for error in response
            if (isset($data['status']) && $data['status'] === 'ERROR') {
                throw new \RuntimeException($data['reason'] ?? 'Unknown error from Lightning service');
            }

            if (!isset($data['pr'])) {
                throw new \RuntimeException('No invoice (pr) in callback response');
            }

            // Track the zap invoice generation for analytics
            $this->trackZapInvoice();

            return $data['pr'];
        } catch (\Exception $e) {
            $this->logger->error('LNURL invoice request failed', [
                'callback' => $callback,
                'amount' => $amountMillisats,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Could not obtain invoice: ' . $e->getMessage());
        }
    }

    /**
     * Track zap invoice generation for analytics
     */
    private function trackZapInvoice(): void
    {
        try {
            $visit = new Visit('/zap/invoice-generated', null);
            $this->visitRepository->save($visit);
        } catch (\Exception $e) {
            // Silently fail - don't break the zap flow for analytics
            $this->logger->warning('Failed to track zap invoice', ['error' => $e->getMessage()]);
        }
    }
}

