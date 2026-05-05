<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Dto\Nostr\RelayInformationDocument;
use App\Entity\RelayInformation;
use App\ReadModel\RedisView\RedisRelayInfoView;
use App\Repository\RelayInformationRepository;
use App\Util\RelayUrlNormalizer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Fetches NIP-11 Relay Information Documents over HTTP and persists the
 * result to {@see RelayInformation} + {@see RedisRelayInfoView}, then
 * pushes hot fields ({@code auth_required}) into {@see RelayHealthStore}.
 *
 * Errors are recorded on the entity and returned as a null-ish outcome to
 * the caller — fetching is best-effort and must never disrupt the calling
 * flow (cron command, admin button, etc.).
 *
 * NIP-11 transport rules:
 *   GET https://<relay-host>:<port?>/<path?>
 *   Accept: application/nostr+json
 *
 * `wss://` → `https://`, `ws://` → `http://`. Relays may serve the document
 * at the same path as the WebSocket endpoint (`wss://relay.foo/`) or at the
 * host root; we use the URL path verbatim and rely on relay-side routing.
 */
class RelayInformationFetcher
{
    private const ACCEPT = 'application/nostr+json';
    private const DEFAULT_TIMEOUT = 5.0;
    private const DEFAULT_MAX_REDIRECTS = 2;
    private const MAX_BODY_BYTES = 256 * 1024; // 256 KB cap on doc size

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly RelayInformationRepository $repository,
        private readonly RedisRelayInfoView $redisView,
        private readonly RelayHealthStore $healthStore,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly float $timeoutSeconds = self::DEFAULT_TIMEOUT,
        private readonly int $maxRedirects = self::DEFAULT_MAX_REDIRECTS,
    ) {}

    /**
     * Fetch and persist a single relay's NIP-11 document.
     *
     * Returns the persisted entity (with either fresh data or a recorded
     * error) — never throws. The caller decides whether to surface the
     * outcome (admin UI: "fetch failed: <error>") or ignore it (cron).
     */
    public function fetch(string $relayUrl): RelayInformation
    {
        $normalized = RelayUrlNormalizer::normalize($relayUrl);
        $entity = $this->repository->findOrCreate($normalized);

        try {
            $httpUrl = self::toHttpUrl($normalized);
        } catch (\InvalidArgumentException $e) {
            return $this->recordFailure($entity, 'invalid url: ' . $e->getMessage());
        }

        try {
            $response = $this->httpClient->request('GET', $httpUrl, [
                'headers' => [
                    'Accept' => self::ACCEPT,
                    'User-Agent' => 'Decent Newsroom (NIP-11 fetcher)',
                ],
                'timeout' => $this->timeoutSeconds,
                'max_redirects' => $this->maxRedirects,
            ]);

            $status = $response->getStatusCode();
            if ($status !== 200) {
                return $this->recordFailure($entity, sprintf('HTTP %d', $status));
            }

            $contentType = strtolower(implode(';', $response->getHeaders(false)['content-type'] ?? []));
            // Accept any JSON-flavoured content type — relays in the wild
            // serve `application/json`, `application/nostr+json`, or even
            // `text/plain` for the same document. We validate the body
            // shape below regardless.
            if (
                !str_contains($contentType, 'json')
                && !str_contains($contentType, 'text/plain')
            ) {
                return $this->recordFailure($entity, 'unexpected content-type: ' . ($contentType ?: 'none'));
            }

            $raw = $response->getContent(false);
            if (strlen($raw) > self::MAX_BODY_BYTES) {
                return $this->recordFailure($entity, 'response too large');
            }

            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                return $this->recordFailure($entity, 'malformed json');
            }

            $doc = RelayInformationDocument::fromArray($decoded);
            return $this->recordSuccess($entity, $doc);
        } catch (HttpExceptionInterface $e) {
            return $this->recordFailure($entity, $e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->warning('RelayInformationFetcher: unexpected error', [
                'relay' => $normalized,
                'error' => $e->getMessage(),
            ]);
            return $this->recordFailure($entity, 'unexpected: ' . $e->getMessage());
        }
    }

    /**
     * @param iterable<string> $urls
     * @return array<string, RelayInformation>
     */
    public function fetchMany(iterable $urls): array
    {
        $out = [];
        foreach ($urls as $url) {
            $out[RelayUrlNormalizer::normalize($url)] = $this->fetch($url);
        }
        return $out;
    }

    /**
     * Convert `wss://host[:port]/path` to `https://host[:port]/path`.
     * Throws if the input is not a valid ws:// or wss:// URL.
     */
    public static function toHttpUrl(string $wsUrl): string
    {
        $trim = trim($wsUrl);
        if (preg_match('#^wss://#i', $trim)) {
            return 'https://' . substr($trim, 6);
        }
        if (preg_match('#^ws://#i', $trim)) {
            return 'http://' . substr($trim, 5);
        }
        throw new \InvalidArgumentException('Not a ws:// or wss:// URL: ' . $wsUrl);
    }

    private function recordSuccess(RelayInformation $entity, RelayInformationDocument $doc): RelayInformation
    {
        $entity
            ->setName($doc->name)
            ->setDescription($doc->description)
            ->setPubkey($doc->pubkey)
            ->setContact($doc->contact)
            ->setSoftware($doc->software)
            ->setVersion($doc->version)
            ->setSupportedNips($doc->supportedNips)
            ->setLimitation($doc->limitation)
            ->setRelayCountries($doc->relayCountries)
            ->setLanguageTags($doc->languageTags)
            ->setTags($doc->tags)
            ->setPostingPolicy($doc->postingPolicy)
            ->setPaymentsUrl($doc->paymentsUrl)
            ->setIcon($doc->icon)
            ->setFees($doc->fees)
            ->setAuthRequired($doc->authRequired)
            ->setFetchedAt(new \DateTimeImmutable())
            ->setFetchError(null)
            ->setFetchAttempts(0);

        $this->em->flush();

        // Mirror to Redis so the gateway preflight is hot.
        $this->redisView->set($entity->getUrl(), [
            'auth_required'   => $entity->isAuthRequired(),
            'supported_nips'  => $entity->getSupportedNips(),
            'software'        => $entity->getSoftware(),
            'version'         => $entity->getVersion(),
            'fetched_at'      => $entity->getFetchedAt()?->getTimestamp(),
        ]);

        // Push hot fields into the health store so existing readers (admin
        // dashboard, gateway routing) automatically benefit.
        $this->healthStore->setAuthRequired($entity->getUrl(), $entity->isAuthRequired());
        $this->healthStore->setSupportedNips($entity->getUrl(), $entity->getSupportedNips());

        $this->logger->info('RelayInformationFetcher: fetched', [
            'relay'          => $entity->getUrl(),
            'auth_required'  => $entity->isAuthRequired(),
            'supported_nips' => $entity->getSupportedNips(),
            'software'       => $entity->getSoftware(),
        ]);

        return $entity;
    }

    private function recordFailure(RelayInformation $entity, string $reason): RelayInformation
    {
        $entity
            ->setFetchError($reason)
            ->incrementFetchAttempts()
            ->setFetchedAt(new \DateTimeImmutable());

        $this->em->flush();

        $this->logger->info('RelayInformationFetcher: fetch failed', [
            'relay'    => $entity->getUrl(),
            'reason'   => $reason,
            'attempts' => $entity->getFetchAttempts(),
        ]);

        return $entity;
    }
}


