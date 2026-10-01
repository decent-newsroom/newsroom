<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Config;

/** Serializable, owner-scoped publication setup state before its root event is published. */
final readonly class PublicationDraft
{
    public const ROOT_KIND = 30040;

    /** @var list<string> */
    public array $tags;

    /**
     * @param list<string> $tags
     */
    private function __construct(
        public string $ownerPubkey,
        public string $dtag,
        public string $title,
        public string $summary,
        public ?string $imageUrl,
        public ?string $language,
        array $tags,
        public string $theme,
        public int $currentStep,
    ) {
        $this->tags = $tags;
    }

    /**
     * @param array<mixed> $tags
     */
    public static function create(
        string $ownerPubkey,
        string $dtag,
        string $title = '',
        string $summary = '',
        ?string $imageUrl = null,
        ?string $language = null,
        array $tags = [],
        string $theme = 'default',
        int $currentStep = 1,
    ): self {
        return new self(
            self::normalizeOwnerPubkey($ownerPubkey),
            self::normalizeDtag($dtag),
            self::normalizeText($title, 200),
            self::normalizeText($summary, 2000),
            self::normalizeImageUrl($imageUrl),
            self::normalizeLanguage($language),
            self::normalizeTags($tags),
            self::normalizeTheme($theme),
            self::normalizeCurrentStep($currentStep),
        );
    }

    /**
     * Reconstitutes a draft read from scalar/list-only storage such as a Redis hash.
     *
     * @param array<mixed> $data
     */
    public static function reconstitute(array $data): self
    {
        foreach (['owner_pubkey', 'dtag', 'title', 'summary', 'image_url', 'language', 'tags', 'theme', 'current_step'] as $field) {
            if (!array_key_exists($field, $data)) {
                throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
            }
        }

        if (!is_string($data['owner_pubkey'])
            || !is_string($data['dtag'])
            || !is_string($data['title'])
            || !is_string($data['summary'])
            || !is_null($data['image_url']) && !is_string($data['image_url'])
            || !is_null($data['language']) && !is_string($data['language'])
            || !is_array($data['tags'])
            || !is_string($data['theme'])
            || !is_int($data['current_step'])) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return self::create(
            $data['owner_pubkey'],
            $data['dtag'],
            $data['title'],
            $data['summary'],
            $data['image_url'],
            $data['language'],
            $data['tags'],
            $data['theme'],
            $data['current_step'],
        );
    }

    /** @return array{owner_pubkey: string, dtag: string, title: string, summary: string, image_url: ?string, language: ?string, tags: list<string>, theme: string, current_step: int} */
    public function toArray(): array
    {
        return [
            'owner_pubkey' => $this->ownerPubkey,
            'dtag' => $this->dtag,
            'title' => $this->title,
            'summary' => $this->summary,
            'image_url' => $this->imageUrl,
            'language' => $this->language,
            'tags' => $this->tags,
            'theme' => $this->theme,
            'current_step' => $this->currentStep,
        ];
    }

    /** The storage key used before the publication root event exists. */
    public function provisionalKey(): string
    {
        return $this->ownerPubkey . ':' . $this->dtag;
    }

    /** The storage key used once the publication root event has been established. */
    public function canonicalKey(): string
    {
        return self::ROOT_KIND . ':' . $this->provisionalKey();
    }

    public function rootCoordinate(): string
    {
        return $this->canonicalKey();
    }

    /**
     * Returns normalized, matching storage keys for an atomic provisional-to-canonical migration.
     *
     * @return array{provisional: string, canonical: string}
     */
    public static function migrationKeys(string $provisionalKey, string $canonicalKey): array
    {
        [$provisionalOwner, $provisionalDtag] = self::parseProvisionalKey($provisionalKey);
        [$canonicalOwner, $canonicalDtag] = self::parseCanonicalKey($canonicalKey);
        if ($provisionalOwner !== $canonicalOwner || $provisionalDtag !== $canonicalDtag) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft_migration');
        }

        return [
            'provisional' => $provisionalOwner . ':' . $provisionalDtag,
            'canonical' => self::ROOT_KIND . ':' . $canonicalOwner . ':' . $canonicalDtag,
        ];
    }

    /** @return array{0: string, 1: string} */
    public static function parseProvisionalKey(string $key): array
    {
        $key = trim($key);
        if (preg_match('/^([a-fA-F0-9]{64}):(.*)$/Ds', $key, $matches) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return [self::normalizeOwnerPubkey($matches[1]), self::normalizeDtag($matches[2])];
    }

    /** @return array{0: string, 1: string} */
    public static function parseCanonicalKey(string $key): array
    {
        $key = trim($key);
        if (preg_match('/^30040:([a-fA-F0-9]{64}):(.*)$/Ds', $key, $matches) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return [self::normalizeOwnerPubkey($matches[1]), self::normalizeDtag($matches[2])];
    }

    private static function normalizeOwnerPubkey(string $ownerPubkey): string
    {
        $ownerPubkey = trim($ownerPubkey);
        if (preg_match('/^[a-fA-F0-9]{64}$/D', $ownerPubkey) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return strtolower($ownerPubkey);
    }

    private static function normalizeDtag(string $dtag): string
    {
        $dtag = trim($dtag);
        if ($dtag === '' || strlen($dtag) > 429 || self::hasControlCharacters($dtag)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return $dtag;
    }

    private static function normalizeText(string $value, int $maxLength): string
    {
        $value = trim($value);
        if (strlen($value) > $maxLength || self::hasControlCharacters($value)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return $value;
    }

    private static function normalizeImageUrl(?string $imageUrl): ?string
    {
        if ($imageUrl === null) {
            return null;
        }

        $imageUrl = trim($imageUrl);
        if ($imageUrl === '' || strlen($imageUrl) > 2048 || self::hasControlCharacters($imageUrl)
            || filter_var($imageUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }
        $parts = parse_url($imageUrl);
        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($parts['host']) || $parts['host'] === ''
            || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return $imageUrl;
    }

    private static function normalizeLanguage(?string $language): ?string
    {
        if ($language === null) {
            return null;
        }

        $language = strtolower(trim($language));
        if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/D', $language) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return $language;
    }

    /**
     * @param array<mixed> $tags
     * @return list<string>
     */
    private static function normalizeTags(array $tags): array
    {
        if (!array_is_list($tags) || count($tags) > 20) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        $normalized = [];
        foreach ($tags as $tag) {
            if (!is_string($tag)) {
                throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
            }
            $tag = trim($tag);
            if ($tag === '' || strlen($tag) > 100 || self::hasControlCharacters($tag)) {
                throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
            }
            if (!in_array($tag, $normalized, true)) {
                $normalized[] = $tag;
            }
        }

        return $normalized;
    }

    private static function normalizeTheme(string $theme): string
    {
        $theme = trim($theme);
        if ($theme === '' || strlen($theme) > 255 || preg_match('/^[a-zA-Z0-9_-]+$/D', $theme) !== 1) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return $theme;
    }

    private static function normalizeCurrentStep(int $currentStep): int
    {
        if ($currentStep < 1 || $currentStep > 100) {
            throw new \InvalidArgumentException('unfold_setup.invalid_publication_draft');
        }

        return $currentStep;
    }

    private static function hasControlCharacters(string $value): bool
    {
        return preg_match('/[\x00-\x1F\x7F]/', $value) === 1;
    }
}
