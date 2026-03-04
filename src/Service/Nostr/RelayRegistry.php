<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use App\Enum\RelayPurpose;
use Psr\Log\LoggerInterface;

/**
 * Single source of truth for all relay URLs and their purposes.
 *
 * Replaces the four scattered hardcoded constants (now removed):
 *   - NostrRelayPool::PUBLIC_RELAYS
 *   - NostrClient::REPUTABLE_RELAYS
 *   - AuthorRelayService::PROFILE_RELAYS (deleted)
 *   - AuthorRelayService::FALLBACK_RELAYS (deleted)
 *
 * LOCAL and PROJECT are the **same physical relay** (strfry) accessed via
 * different network paths. LOCAL is the internal Docker URL used by server
 * code (subscriptions, writes). PROJECT is the public wss:// hostname shown
 * to users in the UI and embedded in relay hints. Use getPublicUrl() when
 * you need the user-facing URL for the local relay.
 *
 * Configured via services.yaml parameters. The local relay URL comes from
 * the NOSTR_DEFAULT_RELAY env var.
 */
class RelayRegistry
{
    /** @var array<string, string[]> purpose => URLs */
    private array $relays = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?string $nostrDefaultRelay = null,
        array $profileRelays = [],
        array $contentRelays = [],
        array $projectRelays = [],
        array $signerRelays = [],
    ) {
        $this->relays[RelayPurpose::LOCAL->value] = $this->nostrDefaultRelay ? [$this->nostrDefaultRelay] : [];
        $this->relays[RelayPurpose::PROFILE->value] = $profileRelays;
        $this->relays[RelayPurpose::CONTENT->value] = $contentRelays;
        $this->relays[RelayPurpose::PROJECT->value] = $projectRelays;
        $this->relays[RelayPurpose::SIGNER->value] = $signerRelays;
        $this->relays[RelayPurpose::USER->value] = [];

        $this->logger->info('RelayRegistry initialized', [
            'local' => count($this->relays[RelayPurpose::LOCAL->value]),
            'profile' => count($this->relays[RelayPurpose::PROFILE->value]),
            'content' => count($this->relays[RelayPurpose::CONTENT->value]),
            'project' => count($this->relays[RelayPurpose::PROJECT->value]),
            'signer' => count($this->relays[RelayPurpose::SIGNER->value]),
        ]);
    }

    /** @return string[] */
    public function getForPurpose(RelayPurpose $purpose): array
    {
        return $this->relays[$purpose->value] ?? [];
    }

    /**
     * @param RelayPurpose[] $purposes
     * @return string[]
     */
    public function getForPurposes(array $purposes): array
    {
        $urls = [];
        foreach ($purposes as $purpose) {
            foreach ($this->getForPurpose($purpose) as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }
        return $urls;
    }

    /** @return array<string, string[]> */
    public function getAll(): array
    {
        return $this->relays;
    }

    /** @return string[] */
    public function getAllUrls(): array
    {
        $urls = [];
        foreach ($this->relays as $purposeRelays) {
            foreach ($purposeRelays as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }
        return $urls;
    }

    public function getLocalRelay(): ?string
    {
        return ($this->relays[RelayPurpose::LOCAL->value] ?? [])[0] ?? null;
    }

    /**
     * Get the project relay URL (public hostname for the same strfry instance as LOCAL).
     */
    public function getProjectRelay(): ?string
    {
        return ($this->relays[RelayPurpose::PROJECT->value] ?? [])[0] ?? null;
    }

    /**
     * Get the public (user-facing) URL for the local relay.
     *
     * LOCAL and PROJECT point to the same physical strfry instance. The server
     * uses LOCAL (internal Docker hostname) for subscriptions and writes. The UI
     * should show PROJECT (public wss:// hostname) so users/clients can connect.
     *
     * Returns the project relay URL if configured, otherwise falls back to the
     * local relay URL (which may be an internal address — better than nothing).
     */
    public function getPublicUrl(): ?string
    {
        return $this->getProjectRelay() ?? $this->getLocalRelay();
    }

    /** Default set for anonymous / general use: local -> project -> content. @return string[] */
    public function getDefaultRelays(): array
    {
        return $this->getForPurposes([RelayPurpose::LOCAL, RelayPurpose::PROJECT, RelayPurpose::CONTENT]);
    }

    /** Profile metadata + relay list discovery: local -> profile. @return string[] */
    public function getProfileRelays(): array
    {
        return $this->getForPurposes([RelayPurpose::LOCAL, RelayPurpose::PROFILE]);
    }

    /** Content discovery: local -> project -> content. @return string[] */
    public function getContentRelays(): array
    {
        return $this->getForPurposes([RelayPurpose::LOCAL, RelayPurpose::PROJECT, RelayPurpose::CONTENT]);
    }

    /** Publishing: local -> project. @return string[] */
    public function getPublishRelays(): array
    {
        return $this->getForPurposes([RelayPurpose::LOCAL, RelayPurpose::PROJECT]);
    }

    /** NIP-46 nostr-connect signing relays. @return string[] */
    public function getSignerRelays(): array
    {
        return $this->getForPurpose(RelayPurpose::SIGNER);
    }

    /** Fallback: local + project relay. @return string[] */
    public function getFallbackRelays(): array
    {
        $relays = [];
        $local = $this->getLocalRelay();
        if ($local) {
            $relays[] = $local;
        }
        foreach ($this->getForPurpose(RelayPurpose::PROJECT) as $url) {
            if (!in_array($url, $relays, true)) {
                $relays[] = $url;
            }
        }
        return $relays;
    }
}

