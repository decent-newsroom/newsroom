<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Model;

use App\Entity\Event;

/**
 * Evaluation unit wrapping an Event.
 *
 * Provides normalized scalar properties, tag selector access,
 * derived state (score), and canonical identity for deduplication.
 */
final class NormalizedItem
{
    private ?float $score = null;
    private ?int $publishedAt = null;
    private bool $publishedAtResolved = false;

    public function __construct(
        private readonly Event $event,
    ) {}

    public function getEvent(): Event
    {
        return $this->event;
    }

    public function getId(): string
    {
        return $this->event->getId();
    }

    public function getPubkey(): string
    {
        return $this->event->getPubkey();
    }

    public function getKind(): int
    {
        return $this->event->getKind();
    }

    public function getCreatedAt(): int
    {
        return $this->event->getCreatedAt();
    }

    public function getContent(): string
    {
        return $this->event->getContent();
    }

    public function getPublishedAt(): ?int
    {
        if (!$this->publishedAtResolved) {
            $this->publishedAtResolved = true;
            $values = $this->getTagValues('published_at');
            if (!empty($values) && ctype_digit($values[0])) {
                $this->publishedAt = (int) $values[0];
            }
        }
        return $this->publishedAt;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(float $score): void
    {
        $this->score = $score;
    }

    /** @return string[] */
    public function getTagValues(string $selector): array
    {
        $values = [];
        foreach ($this->event->getTags() as $tag) {
            if (isset($tag[0], $tag[1]) && $tag[0] === $selector) {
                $values[] = $tag[1];
            }
        }
        return $values;
    }

    public function getFirstTagValue(string $selector): ?string
    {
        $values = $this->getTagValues($selector);
        return $values[0] ?? null;
    }

    /**
     * Canonical identity for deduplication.
     * Parameterized replaceable events (30000-39999): a:{kind}:{pubkey}:{d}
     * Otherwise: e:{id}
     */
    public function getCanonicalId(): string
    {
        $kind = $this->getKind();
        if ($kind >= 30000 && $kind < 40000) {
            $dValues = $this->getTagValues('d');
            $d = $dValues[0] ?? '';
            return "a:{$kind}:{$this->getPubkey()}:{$d}";
        }
        return "e:{$this->getId()}";
    }

    /**
     * Get a normalized event property by name.
     */
    public function getProperty(string $name): int|string|float|null
    {
        return match ($name) {
            'id' => $this->getId(),
            'pubkey' => $this->getPubkey(),
            'kind' => $this->getKind(),
            'created_at' => $this->getCreatedAt(),
            'content' => $this->getContent(),
            default => null,
        };
    }

    /**
     * Get derived runner state by name (empty namespace).
     * Currently only "score" is defined.
     */
    public function getDerived(string $name): int|string|float|null
    {
        return match ($name) {
            'score' => $this->getScore(),
            default => null,
        };
    }
}

