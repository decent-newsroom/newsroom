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
        $this->nip05 = $nip05;
        $this->npub = $npub;
        // Only verify if both nip05 and pubkey are provided
        if ($this->nip05 && $this->npub) {
            $key = new Key();
            try {
                $result = $this->nip05Service->verify($this->nip05, $key->convertToHex($this->npub));
                $this->verified = $result['verified'];
                $this->relays = $result['relays'];
            } catch (\Exception $e) {
                $this->logger->error('Error verifying NIP-05 identifier', ['exception' => $e, 'nip05' => $this->nip05, 'npub' => $this->npub]);
                $this->verified = false;
                $this->relays = [];
            }

            if ($this->verified) {
                $this->displayIdentifier = $this->nip05Service->formatForDisplay($this->nip05);
            }
        }
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
}

