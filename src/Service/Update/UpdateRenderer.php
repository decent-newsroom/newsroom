<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\Event;
use App\Enum\KindsEnum;
use Psr\Log\LoggerInterface;
use App\Service\Nostr\NostrNip19Service;

/**
 * Turns a notified {@see Event} into display-safe title / summary / URL for a
 * {@see \App\Entity\Update} row. The feed authorizes these fields before display;
 * Mercure carries a metadata-free activity signal instead.
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
            $title = $this->extractTag($event, 'alt');
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
        return '/subscriptions';
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
            $nip19 = new NostrNip19Service();
            return $nip19->encodeAddr($event->getPubkey(), $dTag, $event->getKind());
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
            $nip19 = new NostrNip19Service();
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

    private function extractTag(Event $event, string $name): ?string
    {
        /** @var array<array-key, mixed> $tags */
        $tags = $event->getTags();
        foreach ($tags as $tag) {
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
