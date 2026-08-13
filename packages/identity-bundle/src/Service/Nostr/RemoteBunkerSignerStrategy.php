<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Service\Nostr;

use DecentNewsroom\IdentityBundle\Contract\Nip46AuthEventSignerInterface;
use DecentNewsroom\IdentityBundle\Contract\NostrSignerStrategyInterface;
use Psr\Log\LoggerInterface;

/**
 * NIP-46 strategy for server-side NIP-42 AUTH signing.
 *
 * This strategy owns the IdentityBundle-facing registry integration while the
 * host-provided Nip46AuthEventSignerInterface keeps the existing relay transport
 * replaceable. The later innis/nostr-client implementation can swap that signer
 * without changing RelayGatewayCommand again.
 */
final readonly class RemoteBunkerSignerStrategy implements NostrSignerStrategyInterface
{
    public const METHOD = 'nip46';

    public function __construct(
        private Nip46SessionStore $sessions,
        private Nip46AuthEventSignerInterface $signer,
        private LoggerInterface $logger,
    ) {
    }

    public function getMethod(): string
    {
        return self::METHOD;
    }

    public function supports(string $ownerId): bool
    {
        return $this->sessions->has($ownerId);
    }

    public function sign(string $ownerId, array $unsignedEvent): ?array
    {
        $session = $this->sessions->get($ownerId);
        if ($session === null) {
            return null;
        }

        $pubkey = $this->stringValue($unsignedEvent, 'pubkey') ?? $ownerId;
        $relayUrl = $this->tagValue($unsignedEvent['tags'] ?? [], 'relay');
        $challenge = $this->tagValue($unsignedEvent['tags'] ?? [], 'challenge');

        if ($relayUrl === null || $challenge === null) {
            $this->logger->warning('RemoteBunkerSignerStrategy: unsigned AUTH event is missing relay or challenge tag');
            return null;
        }

        return $this->signer->signAuthEvent(
            $pubkey,
            $relayUrl,
            $challenge,
            $session,
        );
    }

    /**
     * @param array<string,mixed> $data
     */
    private function stringValue(array $data, string $key): ?string
    {
        return isset($data[$key]) && is_string($data[$key]) ? $data[$key] : null;
    }

    /**
     * @param mixed $tags
     */
    private function tagValue(mixed $tags, string $tagName): ?string
    {
        if (!is_array($tags)) {
            return null;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }

            if (($tag[0] ?? null) === $tagName && isset($tag[1]) && is_string($tag[1])) {
                return $tag[1];
            }
        }

        return null;
    }
}