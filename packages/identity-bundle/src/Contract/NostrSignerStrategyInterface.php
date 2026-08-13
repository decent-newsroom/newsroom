<?php

declare(strict_types=1);

namespace DecentNewsroom\IdentityBundle\Contract;

/**
 * NIP-46-like abstraction over "how do we get a signature for this user's
 * Nostr session server-side" (e.g. for NIP-42 relay AUTH challenges).
 *
 * Bunker, Amber, and Primal's remote signer all speak the same NIP-46 RPC
 * protocol over relays, so a single strategy implementation covers all of
 * them today. This interface exists so a genuinely different transport (an
 * HTTP-based signer API, a WebUSB hardware key, etc.) can be added later
 * without touching call sites — register it under the
 * `identity.nostr_signer_strategy` service tag and it becomes available
 * through the strategy registry.
 */
interface NostrSignerStrategyInterface
{
    /**
     * Machine name for this signing method, e.g. "nip46".
     */
    public function getMethod(): string;

    /**
     * Whether this strategy can currently sign on behalf of the given user
     * (owner id), e.g. because a session/credential is stored for them.
     */
    public function supports(string $ownerId): bool;

    /**
     * Sign an unsigned Nostr event on behalf of the given user.
     *
     * @param array<string,mixed> $unsignedEvent
     * @return array<string,mixed>|null the signed event, or null on failure/timeout
     */
    public function sign(string $ownerId, array $unsignedEvent): ?array;
}
