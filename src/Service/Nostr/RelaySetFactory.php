<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\RelayPurpose;
use swentel\nostr\Relay\RelaySet;

/**
 * Single point of responsibility for building RelaySet instances.
 *
 * Replaces the 8× copy-pasted relay-selection block that previously lived
 * inside NostrClient:
 *
 *   if ($this->nostrDefaultRelay) {
 *       $relaySet = $this->getDefaultRelaySet();
 *   } else {
 *       $authorRelays = $this->getTopReputableRelaysForAuthor($ident);
 *       $relaySet = empty($authorRelays) ? $this->getDefaultRelaySet() : $this->createRelaySet($authorRelays);
 *   }
 */
class RelaySetFactory
{
    private ?RelaySet $defaultRelaySet = null;

    public function __construct(
        private readonly NostrRelayPool        $relayPool,
        private readonly RelayRegistry         $relayRegistry,
        private readonly UserRelayListService  $userRelayListService,
        private readonly ?string               $nostrDefaultRelay = null,
    ) {}

    /**
     * Build a RelaySet from an explicit list of relay URLs using the connection pool.
     */
    public function fromUrls(array $relayUrls): RelaySet
    {
        $relaySet = new RelaySet();
        foreach ($relayUrls as $url) {
            $relaySet->addRelay($this->relayPool->getRelay($url));
        }
        return $relaySet;
    }

    /**
     * Get the application-wide default RelaySet (lazily built, local relay first).
     */
    public function getDefault(): RelaySet
    {
        if ($this->defaultRelaySet === null) {
            $this->defaultRelaySet = $this->fromUrls($this->relayRegistry->getDefaultRelays());
        }
        return $this->defaultRelaySet;
    }

    /**
     * Build a RelaySet for a specific author.
     *
     * If a local relay is configured, it is used directly (fast path).
     * Otherwise, the author's top reputable relays are queried; if none are
     * found, the application default relay set is used as a fallback.
     *
     * This replaces the 8× inline relay-selection block from NostrClient.
     */
    public function forAuthor(string $pubkey): RelaySet
    {
        if ($this->nostrDefaultRelay) {
            return $this->getDefault();
        }

        $authorRelays = $this->userRelayListService->getTopRelaysForAuthor($pubkey);
        return empty($authorRelays)
            ? $this->getDefault()
            : $this->fromUrls($authorRelays);
    }

    /**
     * Build a RelaySet for an author, merging hint URLs (e.g. from naddr) with
     * the author's own relays. Falls back to content relays when both are empty.
     *
     * Used by "try author relays → fall back to content relays" pattern.
     */
    public function forAuthorWithFallback(string $pubkey, array $hintUrls = []): RelaySet
    {
        if (!empty($hintUrls)) {
            return $this->fromUrls($hintUrls);
        }
        $authorRelays = $this->userRelayListService->getTopRelaysForAuthor($pubkey);
        return empty($authorRelays)
            ? $this->fromUrls($this->relayRegistry->getContentRelays())
            : $this->fromUrls($authorRelays);
    }

    /**
     * Build a RelaySet for a RelayPurpose.
     */
    public function forPurpose(RelayPurpose $purpose): RelaySet
    {
        return $this->fromUrls($this->relayRegistry->getForPurpose($purpose));
    }

    /**
     * Build a relay list guaranteed to contain the local relay, then return a RelaySet.
     */
    public function withLocalRelay(array $relayUrls): RelaySet
    {
        return $this->fromUrls($this->relayPool->ensureLocalRelayInList($relayUrls));
    }
}

