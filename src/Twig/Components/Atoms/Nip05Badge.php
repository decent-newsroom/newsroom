<?php

namespace App\Twig\Components\Atoms;

use App\Service\Nostr\Nip05VerificationService;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class Nip05Badge
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $nip05;

    #[LiveProp]
    public string $npub;

    private bool $valid = false;
    private bool $verified = false;
    private array $relays = [];
    private ?string $displayIdentifier = null;

    public function __construct(
        private readonly Nip05VerificationService $nip05Service,
        private readonly LoggerInterface $logger
    ) {
    }

    public function mount($nip05, $npub): void
    {
        $this->nip05 = trim((string) $nip05);
        $this->npub  = trim((string) $npub);

        if ('' === $this->nip05 || '' === $this->npub) {
            return;
        }

        // Structural validation: must look like local@domain.tld
        // Bech32 Nostr strings (npub1…, nprofile1…, nevent1…, etc.) and other
        // non-conforming values are silently dropped here.
        if (!$this->hasValidStructure($this->nip05)) {
            $this->logger->debug('Ignoring malformed NIP-05 value', ['value' => $this->nip05]);
            return;
        }

        $this->valid = true;

        $key = new Key();
        try {
            $result = $this->nip05Service->verify($this->nip05, $key->convertToHex($this->npub));
            $this->verified = $result['verified'];
            $this->relays   = $result['relays'];
        } catch (\Exception $e) {
            $this->logger->error('Error verifying NIP-05 identifier', ['exception' => $e, 'nip05' => $this->nip05, 'npub' => $this->npub]);
            $this->verified = false;
            $this->relays   = [];
        }

        if ($this->verified) {
            $this->displayIdentifier = $this->nip05Service->formatForDisplay($this->nip05);
        }
    }

    /**
     * Returns true only when the value has the shape expected of a NIP-05
     * identifier: <local-part>@<domain> where the domain contains at least
     * one dot and neither part is empty.
     *
     * Bech32 Nostr strings (npub1…, nprofile1…, nevent1…, note1…, naddr1…)
     * and any other value that lacks this structure return false.
     */
    private function hasValidStructure(string $value): bool
    {
        if (!str_contains($value, '@')) {
            return false;
        }

        [$local, $domain] = explode('@', $value, 2);

        if ($local === '' || $domain === '') {
            return false;
        }

        // Local part: printable ASCII, no whitespace (same rules as NIP-05 spec)
        if (!preg_match('/^[a-zA-Z0-9._\-]+$/', $local)) {
            return false;
        }

        // Domain must look like a real domain (at least one dot, no spaces)
        if (!str_contains($domain, '.') || preg_match('/\s/', $domain)) {
            return false;
        }

        return true;
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function isVerified(): bool
    {
        return $this->verified;
    }

    public function getDisplayIdentifier(): ?string
    {
        return $this->displayIdentifier;
    }

    public function getRelays(): array
    {
        return $this->relays;
    }

    public function hasRelays(): bool
    {
        return !empty($this->relays);
    }

    /**
     * Returns a display-friendly version of the raw nip05 value,
     * used when the identifier could not be verified.
     */
    public function getFormattedNip05(): string
    {
        return $this->nip05Service->formatForDisplay($this->nip05);
    }
}

