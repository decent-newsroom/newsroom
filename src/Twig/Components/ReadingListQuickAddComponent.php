<?php

namespace App\Twig\Components;

use App\Dto\CategoryDraft;
use App\Enum\KindsEnum;
use App\Service\Nostr\NostrClient;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * A floating widget to quickly add articles to the reading list from anywhere
 */
#[AsLiveComponent]
final class ReadingListQuickAddComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $input = '';

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public string $success = '';

    #[LiveProp]
    public int $itemCount = 0;

    #[LiveProp(writable: true)]
    public bool $isExpanded = false;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {}

    public function mount(): void
    {
        $this->updateItemCount();
    }

    #[LiveListener('readingListUpdated')]
    public function refresh(): void
    {
        $this->updateItemCount();
        $this->success = 'Added to reading list!';
    }

    #[LiveAction]
    public function toggleExpanded(): void
    {
        $this->isExpanded = !$this->isExpanded;
    }

    #[LiveAction]
    public function addItem(): void
    {
        $this->error = '';
        $this->success = '';
        $raw = trim($this->input);

        if ($raw === '') {
            $this->error = 'Please enter an naddr or coordinate.';
            return;
        }

        // Try to parse as naddr first
        if (preg_match('/(naddr1[0-9a-zA-Z]+)/', $raw, $m)) {
            $this->addFromNaddr($m[1]);
            return;
        }

        // Try to parse as coordinate (kind:pubkey:slug)
        if (preg_match('/^(\d+):([0-9a-f]{64}):(.+)$/i', $raw, $m)) {
            $kind = (int)$m[1];
            $pubkey = $m[2];
            $slug = $m[3];
            $coordinate = "$kind:$pubkey:$slug";
            $this->addCoordinate($coordinate);
            return;
        }

        $this->error = 'Invalid format. Use naddr or coordinate (kind:pubkey:slug).';
    }

    private function addFromNaddr(string $naddr): void
    {
        try {
            $decoded = new Bech32($naddr);
            if ($decoded->type !== 'naddr') {
                $this->error = 'Invalid naddr type.';
                return;
            }

            /** @var NAddr $data */
            $data = $decoded->data;
            $slug = $data->identifier;
            $pubkey = $data->pubkey;
            $kind = $data->kind;
            $relays = $data->relays;

            if ($kind !== KindsEnum::LONGFORM->value) {
                $this->error = 'Not a long-form article (kind '.$kind.').';
                return;
            }

            $coordinate = $kind . ':' . $pubkey . ':' . $slug;

            // Attempt to fetch article so it exists locally
            try {
                $this->nostrClient->getLongFormFromNaddr($slug, $relays, $pubkey, $kind);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed fetching article from naddr', [
                    'error' => $e->getMessage(),
                    'naddr' => $naddr
                ]);
            }

            $this->addCoordinate($coordinate);
        } catch (\Throwable $e) {
            $this->error = 'Failed to decode naddr.';
            $this->logger->error('naddr decode failed', [
                'input' => $naddr,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function addCoordinate(string $coordinate): void
    {
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');

        if (!$draft instanceof CategoryDraft) {
            $draft = new CategoryDraft();
            $draft->title = 'My Reading List';
            $draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
        }

        if (in_array($coordinate, $draft->articles, true)) {
            $this->success = 'Already in reading list.';
            $this->input = '';
            return;
        }

        $draft->articles[] = $coordinate;
        $session->set('read_wizard', $draft);

        $this->success = 'Added to reading list!';
        $this->input = '';
        $this->updateItemCount();
    }

    private function updateItemCount(): void
    {
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');

        if ($draft instanceof CategoryDraft) {
            $this->itemCount = count($draft->articles);
        } else {
            $this->itemCount = 0;
        }
    }
}

