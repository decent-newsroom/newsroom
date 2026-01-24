<?php

namespace App\Twig\Components;

use App\Dto\CategoryDraft;
use App\Enum\KindsEnum;
use App\Service\Nostr\NostrClient;
use App\Service\ReadingListWorkflowService;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class ReadingListDraftComponent
{
    use DefaultActionTrait;

    public ?CategoryDraft $draft = null;

    #[LiveProp(writable: true)]
    public string $naddrInput = '';

    #[LiveProp]
    public string $naddrError = '';

    #[LiveProp]
    public string $naddrSuccess = '';

    #[LiveProp(writable: true)]
    public bool $editingMeta = false;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly NostrClient $nostrClient,
        private readonly LoggerInterface $logger,
        private readonly ReadingListWorkflowService $workflowService,
    ) {}

    public function mount(): void
    {
        $this->reloadFromSession();
    }

    #[LiveListener('readingListUpdated')]
    public function refresh(): void
    {
        $this->reloadFromSession();
    }

    #[LiveAction]
    public function toggleEditMeta(): void
    {
        $this->editingMeta = !$this->editingMeta;
    }

    #[LiveAction]
    public function updateMeta(string $title = '', string $summary = ''): void
    {
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');
        if (!$draft instanceof CategoryDraft) {
            $draft = new CategoryDraft();
        }
        $draft->title = $title ?: 'My Reading List';
        $draft->summary = $summary;

        // Update workflow state
        $this->workflowService->updateMetadata($draft);

        $session->set('read_wizard', $draft);
        $this->draft = $draft;
        $this->editingMeta = false;
    }

    #[LiveAction]
    public function remove(string $coordinate): void
    {
        $session = $this->requestStack->getSession();
        $draft = $session->get('read_wizard');
        if ($draft instanceof CategoryDraft) {
            $draft->articles = array_values(array_filter($draft->articles, fn($c) => $c !== $coordinate));
            $session->set('read_wizard', $draft);
            $this->draft = $draft;
        }
    }

    #[LiveAction]
    public function clearAll(): void
    {
        $session = $this->requestStack->getSession();
        $session->remove('read_wizard');
        $this->draft = new CategoryDraft();
        $this->draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
    }

    #[LiveAction]
    public function addNaddr(): void
    {
        $this->naddrError = '';
        $this->naddrSuccess = '';
        $raw = trim($this->naddrInput);
        if ($raw === '') {
            $this->naddrError = 'Empty input.';
            return;
        }

        // Extract naddr (accept nostr:naddr1... or raw naddr1...)
        if (preg_match('/(naddr1[0-9a-zA-Z]+)/', $raw, $m) !== 1) {
            $this->naddrError = 'No naddr found.';
            return;
        }
        $naddr = $m[1];

        try {
            $decoded = new Bech32($naddr);
            if ($decoded->type !== 'naddr') {
                $this->naddrError = 'Invalid naddr type.';
                return;
            }
            /** @var NAddr $data */
            $data = $decoded->data;
            $slug = $data->identifier;
            $pubkey = $data->pubkey;
            $kind = $data->kind;
            $relays = $data->relays;

            if ($kind !== KindsEnum::LONGFORM->value) {
                $this->naddrError = 'Not a long-form article (kind '.$kind.').';
                return;
            }
            if (!$slug) {
                $this->naddrError = 'Missing identifier (slug).';
                return;
            }

            $coordinate = $kind . ':' . $pubkey . ':' . $slug;

            $session = $this->requestStack->getSession();
            $draft = $session->get('read_wizard');
            if (!$draft instanceof CategoryDraft) {
                $draft = new CategoryDraft();
            }
            if (!in_array($coordinate, $draft->articles, true)) {
                // Attempt to fetch article so it exists locally (best-effort)
                try {
                    $this->nostrClient->getLongFormFromNaddr($slug, $relays, $pubkey, $kind);
                } catch (\Throwable $e) {
                    // Non-fatal; still add coordinate
                    $this->logger->warning('Failed fetching article from naddr', [
                        'error' => $e->getMessage(),
                        'naddr' => $naddr
                    ]);
                }
                $draft->articles[] = $coordinate;

                // Update workflow state
                $this->workflowService->addArticles($draft);

                $session->set('read_wizard', $draft);
                $this->draft = $draft;
                $this->naddrSuccess = 'Added article: ' . $coordinate;
                $this->dispatchBrowserEvent('readingListUpdated');
            } else {
                $this->naddrSuccess = 'Article already in list.';
            }
            $this->naddrInput = '';
        } catch (\Throwable $e) {
            $this->naddrError = 'Decode failed.';
            $this->logger->error('naddr decode failed', [
                'input' => $raw,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function reloadFromSession(): void
    {
        $session = $this->requestStack->getSession();
        $data = $session->get('read_wizard');
        if ($data instanceof CategoryDraft) {
            $this->draft = $data;
            if (!$this->draft->slug) {
                $this->draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
                $session->set('read_wizard', $this->draft);
            }
            return;
        }

        $this->draft = new CategoryDraft();
        $this->draft->slug = substr(bin2hex(random_bytes(6)), 0, 8);
    }
}
