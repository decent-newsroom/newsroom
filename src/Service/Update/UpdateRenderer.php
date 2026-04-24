<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use swentel\nostr\Event\Event as NostrEvent;
use swentel\nostr\Nip19\Nip19Helper;

/**
 * Turns a notified {@see Event} into display-safe title / summary / URL for a
 * {@see \App\Entity\Update} row and its Mercure toast payload.
 *
 * Keeps only the fields that are safe to render in a cross-user toast — no
 * raw event body, no profile data beyond the author pubkey.
 */
class UpdateRenderer
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{title: ?string, summary: ?string, url: string}
     */
    public function render(Event $event): array
    {
        $title = $this->extractTag($event, 'title') ?? $this->extractTag($event, 'name');
        $summary = $this->extractTag($event, 'summary');
        if ($title === null || $title === '') {
            $title = $this->extractTag($event, 'alt') ?? $this->fallbackTitle($event);
        }

        return [
            'title' => $title !== null ? $this->truncate($title, 500) : null,
            'summary' => $summary !== null ? $this->truncate($summary, 1000) : null,
            'url' => $this->buildUrl($event),
        ];
    }

    private function buildUrl(Event $event): string
    {
        $naddr = $this->encodeNaddr($event);
        if ($naddr !== null) {
            return '/e/' . $naddr;
        }

        $note = $this->encodeNote($event);
        if ($note !== null) {
            return '/e/' . $note;
        }

        // Last-resort fallback when event id cannot be encoded.
        return '/updates';
    }

    private function encodeNaddr(Event $event): ?string
    {
        if ($event->getKind() < 30000 || $event->getKind() > 39999) {
            return null;
        }
        $dTag = $event->getDTag();
        if ($dTag === null || $dTag === '') {
            return null;
        }
        try {
            $nip19 = new Nip19Helper();
            $nostr = new NostrEvent();
            $nostr->setId($event->getId());
            $nostr->setPublicKey($event->getPubkey());
            $nostr->setKind($event->getKind());
            return $nip19->encodeAddr($nostr, $dTag, $event->getKind());
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to encode update naddr', [
                'event_id' => $event->getId(),
                'kind' => $event->getKind(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function encodeNote(Event $event): ?string
    {
        try {
            $nip19 = new Nip19Helper();
            return $nip19->encodeNote($event->getId());
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to encode update note id', [
                'event_id' => $event->getId(),
                'kind' => $event->getKind(),
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function fallbackTitle(Event $event): string
    {
        return match ($event->getKind()) {
            KindsEnum::LONGFORM->value => 'New article',
            KindsEnum::PUBLICATION_INDEX->value => 'New publication',
            default => 'New event',
        };
    }

    private function extractTag(Event $event, string $name): ?string
    {
        foreach ($event->getTags() as $tag) {
            if (is_array($tag) && ($tag[0] ?? null) === $name && isset($tag[1]) && is_string($tag[1])) {
                $value = trim($tag[1]);
                if ($value !== '') {
                    return $value;
                }
            }
        }
        return null;
    }

    private function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max - 1) . '…';
    }
}

