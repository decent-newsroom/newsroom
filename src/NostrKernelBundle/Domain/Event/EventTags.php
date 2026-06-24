<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Domain\Event;

final readonly class EventTags
{
    /**
     * @param list<list<mixed>> $rawTags
     * @param list<EventTag> $tags
     */
    private function __construct(
        private array $rawTags,
        private array $tags,
    ) {
    }

    /**
     * @param list<mixed> $rawTags
     */
    public static function fromRaw(array $rawTags): self
    {
        $normalizedRaw = [];
        $tags = [];

        foreach ($rawTags as $rawTag) {
            if (!\is_array($rawTag)) {
                continue;
            }

            $raw = \array_values($rawTag);
            $name = $raw[0] ?? null;

            if (!\is_string($name) || $name === '') {
                continue;
            }

            $normalizedRaw[] = $raw;
            $tags[] = new EventTag($name, \array_slice($raw, 1), $raw);
        }

        return new self($normalizedRaw, $tags);
    }

    public function firstValue(string $name): ?string
    {
        foreach ($this->tags as $tag) {
            if ($tag->name() !== $name) {
                continue;
            }

            return $tag->firstValue();
        }

        return null;
    }

    /**
     * @return list<list<mixed>>
     */
    public function allByName(string $name): array
    {
        $result = [];

        foreach ($this->tags as $tag) {
            if ($tag->name() === $name) {
                $result[] = $tag->raw();
            }
        }

        return $result;
    }

    public function dTag(): ?string
    {
        $value = $this->firstValue('d');

        return $value === null || $value === '' ? null : $value;
    }

    /**
     * @return list<list<mixed>>
     */
    public function raw(): array
    {
        return $this->rawTags;
    }
}

