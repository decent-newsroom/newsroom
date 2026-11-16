<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventBodyFormat;
use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Util\Nip10TagParser;

/**
 * Adapts an already-validated, already-authorized event without I/O.
 *
 * The caller must enforce deletion, suppression, viewer/member scope, mutes,
 * and private-data rules before passing source data here. This factory neither
 * verifies signatures nor grants access. References are unverified occurrences;
 * each target must be separately authorized before resolution is exposed in
 * rows, counts, fragments, caches, or actions.
 */
final readonly class EventPresentationFactory
{
    private const EXCERPT_LENGTH = 220;
    private const EXCERPT_SCAN_BYTES = 4096;

    public function __construct(
        private RecordIdentityService $identities,
        private NostrNip19Service $nip19,
    ) {}

    public function fromAuthorizedEvent(Event $event): EventPresentation
    {
        return $this->fromAuthorizedFields(
            eventId: $event->getId(),
            kind: $event->getKind(),
            pubkey: $event->getPubkey(),
            createdAt: $event->getCreatedAt(),
            tags: $event->getTags(),
            content: $event->getContent(),
        );
    }

    /**
     * Metadata-only snapshot for an authorized target. No body, media, outgoing
     * relationships, or signed raw tags cross the fragment boundary.
     */
    public function fromAuthorizedTargetMetadata(Event $event): EventPresentation
    {
        $metadata = $this->fromAuthorizedFields(
            $event->getId(), $event->getKind(), $event->getPubkey(),
            $event->getCreatedAt(), $event->getTags(), '',
        );

        return new EventPresentation(
            eventId: $metadata->eventId,
            kind: $metadata->kind,
            authorPubkey: $metadata->authorPubkey,
            authorNpub: $metadata->authorNpub,
            coordinate: $metadata->coordinate,
            recordUid: $metadata->recordUid,
            createdAt: $metadata->createdAt,
            publishedAt: null,
            title: $metadata->isSensitive ? null : ($metadata->title === null ? null : mb_substr($metadata->title, 0, 220)),
            description: null,
            descriptionOnReveal: null,
            imageUrl: null,
            hasContentWarning: $metadata->hasContentWarning,
            contentWarningReason: null,
            isSensitive: $metadata->isSensitive,
            bodyFormat: $metadata->bodyFormat,
            textContent: null,
            autoUpdate: null,
            references: [],
            rawTags: [],
        );
    }

    /**
     * Accepts fields from a validated, authorized raw signed event, including
     * a relay result that has not been persisted. This is not a validation API.
     *
     * @param array<array-key, mixed> $tags Decoded JSON-compatible values.
     */
    public function fromAuthorizedFields(
        string $eventId,
        int $kind,
        string $pubkey,
        int $createdAt,
        array $tags,
        string $content,
    ): EventPresentation {
        [$coordinate, $recordUid] = $this->eventIdentity($eventId, $kind, $pubkey, $tags);
        $format = $this->bodyFormat($kind);
        $textContent = $format === EventBodyFormat::Opaque ? null : $content;
        $description = $this->firstUsefulTag($tags, 'summary')
            // NIP-57 reserves this tag for a JSON-encoded signed zap request.
            ?? ($kind === KindsEnum::ZAP_RECEIPT->value ? null : $this->firstUsefulTag($tags, 'description'))
            ?? $this->firstUsefulTag($tags, 'alt')
            ?? ($textContent === null ? null : $this->excerpt($textContent));

        // The presentation derives warning flags and gates its description from
        // the signed tags, including when constructed without this factory.
        return new EventPresentation(
            eventId: $eventId,
            kind: $kind,
            authorPubkey: $pubkey,
            authorNpub: $this->authorNpub($pubkey),
            coordinate: $coordinate,
            recordUid: $recordUid,
            createdAt: $createdAt,
            publishedAt: $this->publishedAt($tags),
            title: $this->firstUsefulTag($tags, 'title')
                ?? $this->firstUsefulTag($tags, 'name')
                ?? $this->firstUsefulTag($tags, 'd'),
            description: $description,
            descriptionOnReveal: null,
            imageUrl: $this->firstUsefulTag($tags, 'image'),
            hasContentWarning: false,
            contentWarningReason: null,
            isSensitive: false,
            bodyFormat: $format,
            textContent: $textContent,
            autoUpdate: $kind === KindsEnum::PUBLICATION_INDEX->value ? $this->autoUpdate($tags) : null,
            references: $this->references($eventId, $kind, $tags),
            rawTags: $tags,
        );
    }

    /**
     * @param array<array-key, mixed> $tags
     * @return array{?string, ?string} Coordinate and graph record UID.
     */
    private function eventIdentity(string $eventId, int $kind, string $pubkey, array $tags): array
    {
        if ($this->identities->isParameterizedReplaceable($kind)) {
            if (!$this->isHex32($pubkey)) {
                return [null, null];
            }

            $identifier = '';
            foreach ($tags as $tag) {
                if (!is_array($tag) || ($tag[0] ?? null) !== 'd') {
                    continue;
                }
                if (!is_string($tag[1] ?? null)) {
                    return [null, null];
                }
                $identifier = $tag[1];
                break;
            }

            return [
                $this->identities->deriveCoordinate($kind, $pubkey, $identifier),
                $this->identities->deriveRecordUid($kind, $pubkey, $identifier),
            ];
        }

        if ($this->identities->isReplaceable($kind)) {
            if (!$this->isHex32($pubkey)) {
                return [null, null];
            }

            return [
                $this->identities->deriveReplaceableCoordinate($kind, $pubkey),
                $this->identities->deriveRecordUid($kind, $pubkey),
            ];
        }

        return $this->isHex32($eventId)
            ? [null, $this->identities->deriveRecordUid($kind, $pubkey, eventId: strtolower($eventId))]
            : [null, null];
    }

    private function authorNpub(string $pubkey): ?string
    {
        if (!$this->isHex32($pubkey)) {
            return null;
        }

        return $this->nip19->encodeNpub($pubkey);
    }

    private function bodyFormat(int $kind): EventBodyFormat
    {
        return match ($kind) {
            KindsEnum::TEXT_NOTE->value,
            KindsEnum::CHANNEL_MESSAGE->value,
            KindsEnum::COMMENTS->value,
            KindsEnum::HIGHLIGHTS->value => EventBodyFormat::PlainText,
            KindsEnum::LONGFORM->value,
            KindsEnum::LONGFORM_DRAFT->value,
            KindsEnum::CUSTOM_NIP->value => EventBodyFormat::Markdown,
            KindsEnum::PUBLICATION_CONTENT->value,
            KindsEnum::WIKI->value => EventBodyFormat::AsciiDocOrText,
            default => EventBodyFormat::Opaque,
        };
    }

    /** @param array<array-key, mixed> $tags */
    private function firstUsefulTag(array $tags, string $name): ?string
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== $name || !is_string($tag[1] ?? null)) {
                continue;
            }
            $value = trim($tag[1]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $tags */
    private function publishedAt(array $tags): ?int
    {
        foreach ($tags as $tag) {
            if (!is_array($tag) || ($tag[0] ?? null) !== 'published_at' || !is_string($tag[1] ?? null)) {
                continue;
            }
            $value = filter_var($tag[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value !== false) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<array-key, mixed> $tags */
    private function autoUpdate(array $tags): ?string
    {
        $value = $this->firstUsefulTag($tags, 'auto-update');

        return in_array($value, ['yes', 'ask', 'no'], true) ? $value : null;
    }

    /**
     * A bounded, untrusted text excerpt. It does not parse HTML or convert markup.
     */
    private function excerpt(string $content): ?string
    {
        $prefix = $this->excerptPrefix($content);
        if ($prefix === null || $prefix === '') {
            return null;
        }

        $text = preg_replace('/[\p{Z}\s\x00-\x1f\x7f]+/u', ' ', $prefix);
        if ($text === null || ($text = trim($text)) === '') {
            return null;
        }

        if (mb_strlen($text, 'UTF-8') > self::EXCERPT_LENGTH) {
            return rtrim(mb_substr($text, 0, self::EXCERPT_LENGTH - 1, 'UTF-8')) . '…';
        }

        return $text;
    }

    /**
     * Read at most the scan limit, plus up to three bytes to verify a code point
     * split by that limit. Invalid scanned text does not produce an excerpt.
     */
    private function excerptPrefix(string $content): ?string
    {
        $prefix = substr($content, 0, self::EXCERPT_SCAN_BYTES);
        if (mb_check_encoding($prefix, 'UTF-8')) {
            return $prefix;
        }

        if (strlen($content) <= self::EXCERPT_SCAN_BYTES
            || preg_match('/[\xC2-\xF4][\x80-\xBF]{0,2}\z/', $prefix, $tail, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        [$partial, $offset] = $tail[0];
        $lead = ord($partial[0]);
        $width = match (true) {
            $lead <= 0xDF => 2,
            $lead <= 0xEF => 3,
            default => 4,
        };
        $codePoint = substr($content, $offset, $width);
        if (strlen($partial) >= $width || strlen($codePoint) !== $width || !mb_check_encoding($codePoint, 'UTF-8')) {
            return null;
        }

        $prefix = substr($prefix, 0, $offset);

        return mb_check_encoding($prefix, 'UTF-8') ? $prefix : null;
    }

    /**
     * @param array<array-key, mixed> $tags
     * @return list<EventReference>
     */
    private function references(string $eventId, int $kind, array $tags): array
    {
        $references = [];
        $position = 0;
        foreach ($tags as $tag) {
            if (is_array($tag) && is_string($tag[0] ?? null)) {
                $name = $tag[0];
                $type = $this->referenceType($kind, $name);
                if ($type !== null) {
                    $references[] = $this->reference($eventId, $kind, $position, $name, $type, $tag);
                }
            }
            ++$position;
        }

        return $references;
    }

    private function referenceType(int $kind, string $name): ?EventReferenceType
    {
        return match (true) {
            $name === 'a' => EventReferenceType::Address,
            $name === 'e' => EventReferenceType::EventId,
            $name === 'p' => EventReferenceType::Profile,
            $kind === KindsEnum::COMMENTS->value && $name === 'A' => EventReferenceType::Address,
            $kind === KindsEnum::COMMENTS->value && $name === 'E' => EventReferenceType::EventId,
            $kind === KindsEnum::COMMENTS->value && $name === 'P' => EventReferenceType::Profile,
            $kind === KindsEnum::PUBLICATION_INDEX->value && $name === 'E' => EventReferenceType::EventId,
            in_array($kind, [30043, 30044], true) && $name === 'A' => EventReferenceType::Address,
            default => null,
        };
    }

    /** @param array<array-key, mixed> $tag */
    private function reference(
        string $eventId,
        int $kind,
        int $position,
        string $name,
        EventReferenceType $type,
        array $tag,
    ): EventReference {
        $rawValue = is_string($tag[1] ?? null) ? $tag[1] : null;
        [$target, $targetKind, $targetPubkey, $targetIdentifier] = $this->referenceTarget($type, $rawValue);
        $status = $target === null ? EventReferenceStatus::Invalid : EventReferenceStatus::Unresolved;
        $versionEventId = null;

        // NKBIP-01 and NKBIP-04 define this slot for index/directory a tags,
        // unlike NIP-10, NIP-22 and the NKBIP traceback/symlink shapes.
        if ($name === 'a' && in_array($kind, [30040, 30045], true) && array_key_exists(3, $tag)) {
            if (!is_string($tag[3])) {
                $status = EventReferenceStatus::Invalid;
            } elseif ($tag[3] !== '') {
                if ($this->isHex32($tag[3])) {
                    $versionEventId = strtolower($tag[3]);
                } elseif ($status !== EventReferenceStatus::Invalid) {
                    $status = EventReferenceStatus::AmbiguousVersion;
                }
            }
        }

        $role = $this->referenceRole($kind, $name);
        $relayHint = is_string($tag[2] ?? null) && $tag[2] !== '' ? $tag[2] : null;
        if ($kind === KindsEnum::TEXT_NOTE->value && $name === 'e') {
            $auxiliaries = Nip10TagParser::parseReferenceAuxiliaries($tag);
            $role = match ($auxiliaries['marker']) {
                'root' => EventReferenceRole::Root,
                'reply' => EventReferenceRole::Parent,
                default => EventReferenceRole::Reference,
            };
            $relayHint = $auxiliaries['relays'][0] ?? null;
            // Only a recognized marker in slot 2 permits a relay in slot 3.
            if ($auxiliaries['marker'] === null && $relayHint !== ($tag[2] ?? null)) {
                $relayHint = null;
            }
        } elseif ($kind === KindsEnum::REPORT->value && in_array($name, ['e', 'p'], true)) {
            // A report type is not a relay. A WebSocket scheme prefix may
            // instead be an untrusted hint on a supporting reference.
            if ($relayHint !== null && !Nip10TagParser::isWebSocketSchemeCandidate($relayHint)) {
                $relayHint = null;
            }
        }

        return new EventReference(
            sourceEventId: $eventId,
            position: $position,
            tagName: $name,
            type: $type,
            rawValue: $rawValue,
            target: $target,
            targetKind: $targetKind,
            targetPubkey: $targetPubkey,
            targetIdentifier: $targetIdentifier,
            role: $role,
            status: $status,
            relayHint: $relayHint,
            versionEventId: $versionEventId,
        );
    }

    /** @return array{?string, ?int, ?string, ?string} */
    private function referenceTarget(EventReferenceType $type, ?string $rawValue): array
    {
        if ($rawValue === null) {
            return [null, null, null, null];
        }

        if ($type !== EventReferenceType::Address) {
            if (!$this->isHex32($rawValue)) {
                return [null, null, null, null];
            }
            $target = strtolower($rawValue);

            return [$target, null, $type === EventReferenceType::Profile ? $target : null, null];
        }

        $parts = explode(':', $rawValue, 3);
        if (count($parts) !== 3 || $parts[0] === '' || !ctype_digit($parts[0]) || !$this->isHex32($parts[1])) {
            return [null, null, null, null];
        }
        $targetKind = (int) $parts[0];
        $targetPubkey = strtolower($parts[1]);
        $identifier = $parts[2];

        if ($this->identities->isParameterizedReplaceable($targetKind)) {
            $coordinate = $this->identities->deriveCoordinate($targetKind, $targetPubkey, $identifier);
        } elseif ($this->identities->isReplaceable($targetKind) && $identifier === '') {
            // A wire a-tag has a trailing colon; the graph's stable coordinate does not.
            $coordinate = $this->identities->deriveReplaceableCoordinate($targetKind, $targetPubkey);
        } else {
            return [null, null, null, null];
        }

        return [$coordinate, $targetKind, $targetPubkey, $identifier];
    }

    private function referenceRole(int $kind, string $name): EventReferenceRole
    {
        if ($kind === KindsEnum::FOLLOWS->value && $name === 'p') {
            return EventReferenceRole::Follow;
        }
        if (in_array($kind, [KindsEnum::BOOKMARKS->value, KindsEnum::BOOKMARK_SETS->value], true)
            && in_array($name, ['a', 'e', 'p'], true)) {
            return EventReferenceRole::Bookmark;
        }
        if ($kind === KindsEnum::FOLLOW_PACK->value && $name === 'p') {
            return EventReferenceRole::FollowPackMember;
        }
        if (($kind === 30040 && $name === 'a')
            || ($kind === KindsEnum::DRIVE->value && $name === 'a')
            || ($kind === KindsEnum::DIRECTORY->value && in_array($name, ['a', 'e'], true))) {
            return EventReferenceRole::Content;
        }
        if ($kind === KindsEnum::COMMENTS->value) {
            if (in_array($name, ['A', 'E', 'P'], true)) {
                return EventReferenceRole::Root;
            }
            if (in_array($name, ['a', 'e'], true)) {
                return EventReferenceRole::Parent;
            }
        }
        return EventReferenceRole::Reference;
    }

    private function isHex32(string $value): bool
    {
        return strlen($value) === 64 && ctype_xdigit($value);
    }
}
