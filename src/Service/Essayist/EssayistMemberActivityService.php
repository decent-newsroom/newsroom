<?php

declare(strict_types=1);

namespace App\Service\Essayist;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\EventRepository;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrLinkParser;
use App\Util\NostrKeyUtil;
use nostriphant\NIP19\Bech32;

/**
 * Builds a recent activity feed for current Essayist members.
 *
 * Activity kinds:
 * - highlights (kind 9802)
 * - reposts (kind 16)
 * - comments (kind 1111)
 */
final class EssayistMemberActivityService
{
    private const LOOKBACK_SECONDS = 604800; // 7 days
    private const MAX_MEMBERS_TO_SCAN = 1000;
    private const FETCH_LIMIT = 200;

    public function __construct(
        private readonly UserEntityRepository $userRepository,
        private readonly EventRepository $eventRepository,
        private readonly NostrLinkParser $nostrLinkParser,
    ) {
    }

    /**
     * @return array<int, array{event: Event, type: string, highlight?: array<string, mixed>}>
     */
    public function getRecentActivity(int $limit = 60): array
    {
        $members = $this->userRepository->findByRoleWithQuery(
            RolesEnum::ESSAYIST_MEMBER->value,
            null,
            self::MAX_MEMBERS_TO_SCAN,
        );

        $memberPubkeys = [];
        foreach ($members as $member) {
            $npub = (string) ($member->getNpub() ?? '');
            if ($npub === '' || !NostrKeyUtil::isNpub($npub)) {
                continue;
            }

            try {
                $memberPubkeys[] = NostrKeyUtil::npubToHex($npub);
            } catch (\Throwable) {
                // Skip malformed npubs silently.
            }
        }

        $memberPubkeys = array_values(array_unique($memberPubkeys));
        if ($memberPubkeys === []) {
            return [];
        }

        $events = $this->eventRepository->findByFilter([
            'kinds' => [
                KindsEnum::HIGHLIGHTS->value,
                KindsEnum::GENERIC_REPOST->value,
                KindsEnum::COMMENTS->value,
            ],
            'authors' => $memberPubkeys,
            'since' => time() - self::LOOKBACK_SECONDS,
            'limit' => self::FETCH_LIMIT,
        ]);

        $activity = [];
        foreach ($events as $event) {
            $type = $this->detectActivityType($event);
            if ($type === null) {
                continue;
            }

            $item = [
                'event' => $event,
                'type' => $type,
            ];

            if ($type === 'highlight') {
                $item['highlight'] = $this->buildHighlightData($event);
            }

            $activity[] = $item;

            if (count($activity) >= $limit) {
                break;
            }
        }

        return $activity;
    }

    private function detectActivityType(Event $event): ?string
    {
        return match ($event->getKind()) {
            KindsEnum::HIGHLIGHTS->value => 'highlight',
            KindsEnum::GENERIC_REPOST->value => 'repost',
            KindsEnum::COMMENTS->value => 'comment',
            default => null,
        };
    }

    /**
     * Build a lightweight highlight view model compatible with existing highlight templates.
     *
     * @return array<string, mixed>
     */
    private function buildHighlightData(Event $event): array
    {
        $context = null;
        $sourceUrl = null;
        $url = null;
        $articleRef = null;
        $articleTitle = null;
        $relayHints = [];

        foreach ($event->getTags() as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            $key = (string) ($tag[0] ?? '');

            switch ($key) {
                case 'context':
                    $context = $tag[1] ?? null;
                    break;
                case 'a':
                case 'A':
                    if ($articleRef === null) {
                        $articleRef = $tag[1] ?? null;
                    }
                    if (isset($tag[2]) && is_string($tag[2]) && str_starts_with($tag[2], 'wss://')) {
                        $relayHints[] = $tag[2];
                    }
                    break;
                case 'title':
                    if ($articleTitle === null) {
                        $articleTitle = $tag[1] ?? null;
                    }
                    break;
                case 'r':
                    if ($sourceUrl === null) {
                        $sourceUrl = $tag[1] ?? null;
                    }
                    if ($url === null) {
                        $url = $tag[1] ?? null;
                    }
                    if (isset($tag[1]) && is_string($tag[1]) && str_starts_with($tag[1], 'wss://')) {
                        $relayHints[] = $tag[1];
                    }
                    break;
            }
        }

        $naddr = null;
        $preview = null;
        if (is_string($articleRef) && $articleRef !== '') {
            $naddr = $this->generateNaddr($articleRef, array_values(array_unique($relayHints)));
            if ($naddr !== null) {
                $preview = $this->createPreviewData($naddr);
            }
        }

        return [
            'content' => $event->getContent(),
            'context' => $context,
            'sourceUrl' => $sourceUrl,
            'url' => $url,
            'article_ref' => $articleRef,
            'article_title' => $articleTitle,
            'naddr' => $naddr,
            'preview' => $preview,
        ];
    }

    private function generateNaddr(string $coordinate, array $relayHints = []): ?string
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3) {
            return null;
        }

        try {
            $kind = (int) $parts[0];
            if ($kind < 30000 || $kind >= 40000) {
                return null;
            }

            $naddr = Bech32::naddr(
                kind: $kind,
                pubkey: $parts[1],
                identifier: $parts[2],
                relays: $relayHints,
            );

            return (string) $naddr;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function createPreviewData(string $naddr): ?array
    {
        try {
            $links = $this->nostrLinkParser->parseLinks('nostr:' . $naddr);
            if (!empty($links) && is_array($links[0])) {
                return $links[0];
            }
        } catch (\Throwable) {
            // Best-effort enrichment: fall back to link rendering when parsing fails.
        }

        return null;
    }
}


