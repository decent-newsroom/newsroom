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
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ReadingListQuickInputComponent
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $input = '';

    #[LiveProp]
    public string $error = '';

    #[LiveProp]
    public string $success = '';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
    ) {}

    #[LiveAction]
    public function addMultiple(): void
    {
        $this->error = '';
        $this->success = '';
        $raw = trim($this->input);

        if ($raw === '') {
            $this->error = 'Please enter at least one naddr or coordinate.';
            return;
        }

        // Split by newlines and process each line
        $lines = array_filter(array_map('trim', explode("\n", $raw)));
        $added = 0;
        $skipped = 0;
        $errors = [];

        foreach ($lines as $line) {
            $result = $this->processLine($line);
            if ($result['success']) {
                $added++;
            } elseif ($result['skipped']) {
                $skipped++;
            } else {
                $errors[] = $result['error'];
            }
        }

        if ($added > 0) {
            $this->success = "Added $added article" . ($added > 1 ? 's' : '') . " to reading list.";
            if ($skipped > 0) {
                $this->success .= " ($skipped already in list)";
            }
            $this->input = '';
        }

        if (!empty($errors)) {
            $this->error = implode('; ', array_slice($errors, 0, 3));
            if (count($errors) > 3) {
                $this->error .= ' (and ' . (count($errors) - 3) . ' more errors)';
            }
        }

        if ($added > 0 || $skipped > 0) {
            // Trigger update for other components
            $this->dispatchBrowserEvent('readingListUpdated');
        }
    }

    private function processLine(string $line): array
    {
        // Try to parse as naddr first
        if (preg_match('/(naddr1[0-9a-zA-Z]+)/', $line, $m)) {
            return $this->addFromNaddr($m[1]);
        }

        // Try to parse as coordinate (kind:pubkey:slug)
        if (preg_match('/^(\d+):([0-9a-f]{64}):(.+)$/i', $line, $m)) {
            $kind = (int)$m[1];
            $pubkey = $m[2];
            $slug = $m[3];
            $coordinate = "$kind:$pubkey:$slug";
            return $this->addCoordinate($coordinate);
        }

        return ['success' => false, 'skipped' => false, 'error' => "Invalid format: $line"];
    }

    private function addFromNaddr(string $naddr): array
    {
        try {
            $decoded = new Bech32($naddr);
            if ($decoded->type !== 'naddr') {
                return ['success' => false, 'skipped' => false, 'error' => 'Invalid naddr type'];
            }

            /** @var NAddr $data */
            $data = $decoded->data;
            $slug = $data->identifier;
            $pubkey = $data->pubkey;
            $kind = $data->kind;
            $relays = $data->relays;

            if ($kind !== KindsEnum::LONGFORM->value) {
                return ['success' => false, 'skipped' => false, 'error' => "Not a long-form article (kind $kind)"];
            }

            if (!$slug) {
                return ['success' => false, 'skipped' => false, 'error' => 'Missing identifier'];
            }

            $coordinate = $kind . ':' . $pubkey . ':' . $slug;

            // Attempt to fetch article so it exists locally (best-effort)
            try {
                $this->nostrClient->getLongFormFromNaddr($slug, $relays, $pubkey, $kind);
            } catch (\Throwable $e) {
                $this->logger->warning('Failed fetching article from naddr', [
                    'error' => $e->getMessage(),
                    'naddr' => $naddr
                ]);
            }

            return $this->addCoordinate($coordinate);
        } catch (\Throwable $e) {
            $this->logger->error('naddr decode failed', [
                'input' => $naddr,
                'error' => $e->getMessage()
            ]);
            return ['success' => false, 'skipped' => false, 'error' => 'Failed to decode naddr'];
        }
    }

    private function addCoordinate(string $coordinate): array
    {
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');

        if (!$draft instanceof CategoryDraft) {
            $draft = new CategoryDraft();
            $draft->title = 'My Reading List';
            $draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
        }

        if (in_array($coordinate, $draft->articles, true)) {
            return ['success' => false, 'skipped' => true, 'error' => ''];
        }

        $draft->articles[] = $coordinate;
        $session->set('read_wizard', $draft);

        return ['success' => true, 'skipped' => false, 'error' => ''];
    }
}
