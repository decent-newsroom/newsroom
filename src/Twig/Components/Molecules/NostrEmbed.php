<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use App\Dto\EventWorkspace\EventPresentation;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\EventRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Log\LoggerInterface;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/** DB-only resolution of one reference; presentation never grants access. */
#[AsTwigComponent('Molecules:NostrEmbed')]
final class NostrEmbed
{
    public string $bech = '';
    public string $type = '';
    public string $shortBech = '';
    public string $href = '';
    public string $label = '';
    public bool $restricted = false;
    public bool $sensitive = false;
    public ?EventPresentation $presentation = null;

    public function __construct(
        private readonly EventRepository $eventRepository,
        private readonly ArticleRepository $articleRepository,
        private readonly EventPreviewAccessPolicy $access,
        private readonly EventPresentationFactory $presentations,
        private readonly LoggerInterface $logger,
    ) {}

    public function mount(string $bech, string $type, ?Event $resolvedEvent = null): void
    {
        $this->bech = $bech;
        $this->type = $type;
        $this->shortBech = substr($bech, 0, 12) . '…' . substr($bech, -8);
        $this->label = match ($type) {
            'naddr' => 'article',
            'note' => 'note',
            default => 'event',
        };
        $this->href = ($type === 'naddr' ? '/article/' : '/e/') . $bech;

        try {
            $decoded = new Bech32($bech);
            if ($decoded->type !== $type || !in_array($type, ['note', 'nevent', 'naddr'], true)) {
                $this->restricted = true;
                return;
            }

            if ($type === 'naddr') {
                $this->resolveAddress($decoded->data);
                return;
            }

            $id = $type === 'note' ? $decoded->data->data : $decoded->data->id;
            // Optional server-resolved exact target; identity and policy below
            // still apply. Address resolution never consumes this shortcut.
            $event = $resolvedEvent ?? $this->eventRepository->findById($id);
            if ($event !== null) {
                if (strtolower($event->getId()) !== strtolower($id)) {
                    $this->restricted = true;
                    return;
                }
                $this->presentEvent($event);
            }
        } catch (\Throwable $exception) {
            $this->presentation = null;
            $this->sensitive = false;
            $this->restricted = true;
            $this->logger->error('NostrEmbed: local resolution failed; denying preview', [
                'bech' => $bech,
                'exception' => $exception,
            ]);
        }
    }

    private function resolveAddress(NAddr $address): void
    {
        $kind = (int) $address->kind;
        $this->href = match ($kind) {
            KindsEnum::LONGFORM->value => '/article/' . $this->bech,
            KindsEnum::PUBLICATION_CONTENT->value => '/chapter/' . $this->bech,
            default => '/e/' . $this->bech,
        };
        if ($kind === KindsEnum::PUBLICATION_CONTENT->value) {
            $this->label = 'chapter';
        }

        $event = $this->eventRepository->findByNaddr($kind, $address->pubkey, $address->identifier);
        if ($event === null) {
            // Do not hand a known projection to the deferred API merely because
            // its signed event is absent: the projection may be restricted.
            if ($kind === KindsEnum::LONGFORM->value
                && $this->articleRepository->findOneBy([
                    'slug' => $address->identifier,
                    'pubkey' => $address->pubkey,
                    'kind' => KindsEnum::LONGFORM,
                ]) !== null) {
                $this->restricted = true;
            }
            return;
        }

        if ($event->getKind() !== $kind
            || strtolower($event->getPubkey()) !== strtolower($address->pubkey)
            || $this->firstIdentifier($event) !== $address->identifier) {
            $this->restricted = true;
            return;
        }
        $this->presentEvent($event);
    }

    private function presentEvent(Event $event): void
    {
        $kind = $event->getKind();
        $identifier = $kind >= 30000 && $kind <= 39999 ? $this->firstIdentifier($event) : null;
        if (($kind >= 30000 && $kind <= 39999 && $identifier === null)
            || (!$this->access->isReadable($event))) {
            $this->restricted = true;
            return;
        }

        $articleTags = [];
        if ($kind === KindsEnum::LONGFORM->value) {
            // Essayist exclusivity is not signed: require the matching exact
            // projection, not a conversion or another coordinate revision.
            $article = $this->articleRepository->findOneBy(['eventId' => $event->getId()]);
            if (!$article instanceof Article
                || strtolower((string) $article->getEventId()) !== strtolower($event->getId())
                || !$this->access->isArticleReadable($article, $event->getPubkey(), $identifier)) {
                $this->restricted = true;
                return;
            }
            $articleTags = $article->getRaw()['tags'];
        } elseif (!in_array($kind, EventPreviewAccessPolicy::PUBLIC_EMBED_KINDS, true)) {
            $this->restricted = true;
            return;
        }

        if (EventPresentation::hasSensitiveTagEvidence($event->getTags())
            || EventPresentation::hasSensitiveTagEvidence($articleTags)) {
            // No title, identity, raw warning reason, media or navigation link.
            $this->sensitive = true;
            return;
        }

        $this->presentation = $this->presentations->fromAuthorizedEvent($event);
    }

    /** First signed d wins; no d is the empty NIP-01 identifier. */
    private function firstIdentifier(Event $event): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === 'd') {
                return is_string($tag[1] ?? null) ? $tag[1] : null;
            }
        }
        return '';
    }
}
