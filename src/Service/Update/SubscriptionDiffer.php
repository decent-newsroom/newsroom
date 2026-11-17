<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Service\EventWorkspace\EventPresentationFactory;

/**
 * @phpstan-type Member array{type: string, target: string, pin: ?string, position: int}
 * @phpstan-type Delta array{added: list<Member>, removed: list<Member>, reordered: bool, metadata: list<string>, content: bool}
 */
final readonly class SubscriptionDiffer
{
    public function __construct(private EventPresentationFactory $presentations) {}

    /**
     * @param array<array-key, mixed> $tags
     * @return list<Member>
     */
    public function members(int $kind, array $tags): array
    {
        $names = match ($kind) {
            3, 39089 => ['p'],
            30040 => ['a', 'e'],
            30003 => ['a', 'e', 'p'],
            30004, 30005, 30006 => ['a', 'e'],
            default => [],
        };
        $members = [];
        foreach ($this->presentations->references('', $kind, $tags) as $reference) {
            if (!in_array($reference->tagName, $names, true)) {
                continue;
            }
            // Invalid occurrences stay in the local snapshot, but do not
            // become resolvable links or claims about a valid member.
            if ($reference->target === null || $reference->status->value !== 'unresolved') {
                continue;
            }
            $members[] = [
                'type' => $reference->type->value,
                'target' => $reference->target,
                'pin' => $reference->versionEventId,
                'position' => $reference->position,
            ];
        }
        if (in_array($kind, [10015, 30015], true)) {
            foreach ($tags as $position => $tag) {
                if (is_array($tag) && ($tag[0] ?? null) === 't' && is_string($tag[1] ?? null)) {
                    $members[] = ['type' => 'topic', 'target' => strtolower($tag[1]), 'pin' => null, 'position' => $position];
                }
            }
        }
        return $members;
    }

    /**
     * @param array<array-key, mixed> $beforeTags
     * @param array<array-key, mixed> $afterTags
     * @return Delta
     */
    public function compare(int $kind, array $beforeTags, string $beforeHash, array $afterTags, string $afterHash): array
    {
        $before = $this->members($kind, $beforeTags);
        $after = $this->members($kind, $afterTags);
        $added = $this->subtract($after, $before);
        $removed = $this->subtract($before, $after);
        $metadata = [];
        foreach (['title', 'name', 'summary', 'description', 'image'] as $name) {
            $values = static fn (array $tags): array => array_values(array_filter(
                $tags,
                static fn (mixed $tag): bool => is_array($tag) && ($tag[0] ?? null) === $name,
            ));
            if ($values($beforeTags) !== $values($afterTags)) {
                $metadata[] = $name;
            }
        }
        return [
            'added' => $added,
            'removed' => $removed,
            'reordered' => $added === [] && $removed === []
                && array_map($this->identity(...), $before) !== array_map($this->identity(...), $after),
            'metadata' => $metadata,
            'content' => $beforeHash !== $afterHash,
        ];
    }

    /**
     * @param list<Member> $left
     * @param list<Member> $right
     * @return list<Member>
     */
    private function subtract(array $left, array $right): array
    {
        $counts = array_count_values(array_map($this->identity(...), $right));
        $difference = [];
        foreach ($left as $member) {
            $key = $this->identity($member);
            if (($counts[$key] ?? 0) > 0) {
                --$counts[$key];
            } else {
                $difference[] = $member;
            }
        }
        return $difference;
    }

    /** @param Member $member */
    private function identity(array $member): string
    {
        return json_encode([$member['type'], $member['target'], $member['pin']], JSON_THROW_ON_ERROR);
    }
}
