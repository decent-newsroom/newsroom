<?php

declare(strict_types=1);

namespace App\Dto\EventWorkspace;

/**
 * Snapshot of an already-authorized event; this object does not enforce access.
 *
 * All display strings and textContent are untrusted text, not safe HTML.
 * Escape them at the output boundary and use a separate, sanitized body renderer.
 * Opaque bodies are deliberately not exposed through textContent. For sensitive
 * events the default description is null; descriptionOnReveal, textContent,
 * imageUrl, and raw tags must be gated until the reader chooses to reveal them.
 * Callers must also apply their policy to titles and other sensitive metadata.
 * A missing title is null; callers can localize a fallback using kind and eventId.
 * Explicit warning fields and signed tag evidence are combined fail-closed, so
 * a content warning always implies sensitivity even for direct construction.
 * The public constructor accepts already-adapted, source-kind-appropriate
 * display fields. Use EventPresentationFactory for validated, authorized raw
 * signed event fields.
 */
final readonly class EventPresentation
{
    /** @var array<array-key, mixed> */
    private array $rawTags;

    /** @var list<EventReference> */
    public array $references;

    public ?string $description;
    public ?string $descriptionOnReveal;
    public bool $hasContentWarning;
    public ?string $contentWarningReason;
    public bool $isSensitive;

    /**
     * @param array<array-key, mixed> $references
     * @param array<array-key, mixed> $rawTags Decoded JSON-compatible values.
     */
    public function __construct(
        public string $eventId,
        public int $kind,
        public string $authorPubkey,
        public ?string $authorNpub,
        public ?string $coordinate,
        public ?string $recordUid,
        public int $createdAt,
        public ?int $publishedAt,
        public ?string $title,
        ?string $description,
        ?string $descriptionOnReveal,
        public ?string $imageUrl,
        bool $hasContentWarning,
        ?string $contentWarningReason,
        bool $isSensitive,
        public EventBodyFormat $bodyFormat,
        public ?string $textContent,
        public ?string $autoUpdate,
        array $references,
        array $rawTags,
    ) {
        if (!array_is_list($references)) {
            throw new \InvalidArgumentException('References must be an ordered list.');
        }
        $validatedReferences = [];
        foreach ($references as $reference) {
            if (!$reference instanceof EventReference) {
                throw new \InvalidArgumentException('References must be immutable event occurrences.');
            }
            $validatedReferences[] = $reference;
        }
        $this->references = $validatedReferences;
        $this->rawTags = self::copyJsonValue($rawTags);

        [$tagHasWarning, $tagReason, $tagIsSensitive] = self::sensitivityFromTags($this->rawTags);
        $this->hasContentWarning = $hasContentWarning || $tagHasWarning || $contentWarningReason !== null;
        $this->contentWarningReason = $contentWarningReason ?? $tagReason;
        $this->isSensitive = $isSensitive || $this->hasContentWarning || $tagIsSensitive;
        $this->description = $this->isSensitive ? null : $description;
        $this->descriptionOnReveal = $this->isSensitive ? ($descriptionOnReveal ?? $description) : $descriptionOnReveal;
    }

    /**
     * Includes Event::isNSFW's explicit warning, label and hashtag cases,
     * plus NIP-32 qualified NSFW labels and the NIP-36 content-warning label
     * namespace. It is not a complete moderation policy.
     *
     * @param array<array-key, mixed> $tags
     * @return array{bool, ?string, bool}
     */
    private static function sensitivityFromTags(array $tags): array
    {
        $hasWarning = false;
        $reason = null;
        $isSensitive = false;
        foreach ($tags as $tag) {
            if (!is_array($tag)) {
                continue;
            }
            if (($tag[0] ?? null) === 'content-warning') {
                if (!$hasWarning && is_string($tag[1] ?? null)) {
                    $reason = trim($tag[1]);
                    $reason = $reason === '' ? null : $reason;
                }
                $hasWarning = true;
                $isSensitive = true;
                continue;
            }
            if (($tag[0] ?? null) === 'L' && is_string($tag[1] ?? null)
                && in_array(strtolower($tag[1]), ['nsfw', 'content-warning'], true)) {
                $isSensitive = true;
            }
            if (($tag[0] ?? null) === 'l' && is_string($tag[2] ?? null)
                && in_array(strtolower($tag[2]), ['nsfw', 'content-warning'], true)) {
                $isSensitive = true;
            }
            if (($tag[0] ?? null) === 't' && is_string($tag[1] ?? null)
                && in_array(strtolower($tag[1]), ['nsfw', 'adult', 'explicit', '18+', 'nsfl'], true)) {
                $isSensitive = true;
            }
        }

        return [$hasWarning, $reason, $isSensitive];
    }

    /**
     * Returns the original tags, including malformed entries, in source order.
     *
     * @return array<array-key, mixed>
     */
    public function getRawTags(): array
    {
        return self::copyJsonValue($this->rawTags);
    }

    /**
     * Tag-only sensitivity check for callers that need to gate metadata without
     * building a full body presentation.
     *
     * @param array<array-key, mixed> $tags
     */
    public static function hasSensitiveTagEvidence(array $tags): bool
    {
        return self::sensitivityFromTags($tags)[2];
    }

    /**
     * JSON-decoded tags normally contain arrays and scalars. Copy stdClass too,
     * so even a malformed JSON object cannot mutate the snapshot by reference.
     */
    private static function copyJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = self::copyJsonValue($item);
            }

            return $copy;
        }

        if ($value instanceof \stdClass) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $key => $item) {
                $copy->{$key} = self::copyJsonValue($item);
            }

            return $copy;
        }

        if (is_object($value) || is_resource($value)) {
            throw new \InvalidArgumentException('Event tags must contain decoded JSON values.');
        }

        return $value;
    }
}
