<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\Note;
use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Resolves a single nostr reference (note, nevent, naddr) to a rich card
 * at render time using only local data (DB + Redis).
 *
 * Emitted by the Converter as a deferred embed placeholder; the
 * `resolve_nostr_embeds` Twig filter programmatically renders this
 * component for each placeholder found in the article HTML.
 */
#[AsTwigComponent('Molecules:NostrEmbed')]
final class NostrEmbed
{
    public string $bech = '';
    public string $type = '';  // note, nevent, naddr

    // ── Resolved data (set by mount) ────────────────────────────
    public bool $resolved = false;

    /** For note/nevent: the resolved event object */
    public ?object $event = null;
    /** Author metadata (stdClass with name, picture, etc.) */
    public ?object $authorMeta = null;

    /** For naddr longform: the resolved Article entity */
    public ?object $article = null;
    /** Authors metadata array for Card component */
    public array $authorsMetadata = [];

    /** Is this a longform article (kind 30023)? */
    public bool $isLongform = false;
    /** Is this a kind 20 picture? */
    public bool $isPicture = false;

    /** Fallback display info */
    public string $shortBech = '';
    public string $href = '';
    public string $label = '';

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly ArticleFactory $articleFactory,
        private readonly RedisCacheService $redisCacheService,
        private readonly LoggerInterface $logger,
    ) {}

    public function mount(string $bech, string $type): void
    {
        $this->bech = $bech;
        $this->type = $type;
        $this->shortBech = substr($bech, 0, 12) . '…' . substr($bech, -8);
        $this->label = match ($type) {
            'naddr' => 'article',
            'note' => 'note',
            default => 'event',
        };

        try {
            $decoded = new Bech32($bech);

            match ($decoded->type) {
                'note' => $this->resolveNote($decoded),
                'nevent' => $this->resolveNevent($decoded),
                'naddr' => $this->resolveNaddr($decoded),
                default => null,
            };
        } catch (\Throwable $e) {
            $this->logger->debug('NostrEmbed: failed to resolve', [
                'bech' => $bech,
                'error' => $e->getMessage(),
            ]);
        }

        // Set fallback href
        $this->href = match ($this->type) {
            'naddr' => '/article/' . $bech,
            default => '/e/' . $bech,
        };
    }

    private function resolveNote(Bech32 $decoded): void
    {
        /** @var Note $obj */
        $obj = $decoded->data;
        $event = $this->findEvent($obj->data);
        if (!$event) {
            return;
        }

        $this->event = $event;
        $this->resolved = true;
        $this->isPicture = ((int) $event->kind === 20);
        $this->authorMeta = $this->fetchMeta($event->pubkey);
    }

    private function resolveNevent(Bech32 $decoded): void
    {
        /** @var NEvent $obj */
        $obj = $decoded->data;
        $event = $this->findEvent($obj->id);
        if (!$event) {
            return;
        }

        $this->event = $event;
        $this->resolved = true;
        $this->isPicture = ((int) $event->kind === 20);
        $this->authorMeta = $this->fetchMeta($event->pubkey);
    }

    private function resolveNaddr(Bech32 $decoded): void
    {
        /** @var NAddr $obj */
        $obj = $decoded->data;
        $this->isLongform = ((int) $obj->kind === KindsEnum::LONGFORM->value);

        // For longform articles, try ArticleRepository first
        if ($this->isLongform) {
            try {
                $article = $this->articleRepository->findOneBy(
                    ['slug' => $obj->identifier, 'pubkey' => $obj->pubkey, 'kind' => KindsEnum::LONGFORM],
                    ['createdAt' => 'DESC']
                );
                if ($article) {
                    $this->article = $article;
                    $this->resolved = true;
                    $authorMeta = $this->fetchMeta($obj->pubkey);
                    $this->authorsMetadata = $authorMeta ? [$obj->pubkey => $authorMeta] : [];
                    return;
                }
            } catch (\Throwable) {
                // fall through
            }
        }

        // Generic event lookup
        try {
            $entity = $this->eventRepository->findByNaddr((int) $obj->kind, $obj->pubkey, $obj->identifier);
            if (!$entity) {
                return;
            }

            $event = new \stdClass();
            $event->id = $entity->getId();
            $event->kind = $entity->getKind();
            $event->pubkey = $entity->getPubkey();
            $event->content = $entity->getContent();
            $event->created_at = $entity->getCreatedAt();
            $event->tags = $entity->getTags();
            $event->sig = $entity->getSig();

            // Longform event from Event table — convert to Article for Card
            if ($this->isLongform) {
                try {
                    $this->article = $this->articleFactory->createFromLongFormContentEvent($event);
                    $this->resolved = true;
                    $authorMeta = $this->fetchMeta($event->pubkey);
                    $this->authorsMetadata = $authorMeta ? [$event->pubkey => $authorMeta] : [];
                    return;
                } catch (\Throwable) {
                    // fall through to generic card
                }
            }

            $this->event = $event;
            $this->resolved = true;
            $this->authorMeta = $this->fetchMeta($event->pubkey);
        } catch (\Throwable) {
            // skip
        }
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function findEvent(string $id): ?object
    {
        try {
            $entity = $this->eventRepository->findById($id);
            if (!$entity) {
                return null;
            }

            $obj = new \stdClass();
            $obj->id = $entity->getId();
            $obj->kind = $entity->getKind();
            $obj->pubkey = $entity->getPubkey();
            $obj->content = $entity->getContent();
            $obj->created_at = $entity->getCreatedAt();
            $obj->tags = $entity->getTags();
            $obj->sig = $entity->getSig();
            return $obj;
        } catch (\Throwable) {
            return null;
        }
    }

    private function fetchMeta(string $pubkey): ?object
    {
        try {
            $meta = $this->redisCacheService->getMetadata($pubkey);
            return $meta?->toStdClass();
        } catch (\Throwable) {
            return null;
        }
    }
}

