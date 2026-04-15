<?php

declare(strict_types=1);

namespace App\ExpressionBundle\Parser;

use App\Entity\Event;
use App\ExpressionBundle\Exception\InvalidArgumentException;
use App\ExpressionBundle\Model\Pipeline;

/**
 * Parses a kind:30880 event's tags array into a Pipeline.
 *
 * Walks the tags in order, splits at each ["op", ...] tag.
 * Each segment becomes a Stage via StageParser.
 */
final class ExpressionParser
{
    public function __construct(
        private readonly StageParser $stageParser,
    ) {}

    public function parse(Event $event): Pipeline
    {
        $tags = $event->getTags();

        // 1. Extract d-tag
        $dTag = null;
        foreach ($tags as $tag) {
            if (($tag[0] ?? '') === 'd' && isset($tag[1])) {
                if ($dTag !== null) {
                    throw new InvalidArgumentException('Expression must have exactly one d tag');
                }
                $dTag = $tag[1];
            }
        }
        if ($dTag === null) {
            throw new InvalidArgumentException('Expression must have exactly one d tag');
        }

        // 2. Split tags at op boundaries
        $segments = [];
        $currentOp = null;
        $currentTags = [];

        foreach ($tags as $tag) {
            $tagType = $tag[0] ?? '';

            // Skip non-stage tags
            if ($tagType === 'd' || $tagType === 'alt' || $tagType === 'expiration') {
                continue;
            }

            if ($tagType === 'op') {
                // Save previous segment
                if ($currentOp !== null) {
                    $segments[] = ['op' => $currentOp, 'tags' => $currentTags];
                }
                $currentOp = $tag[1] ?? null;
                if ($currentOp === null) {
                    throw new InvalidArgumentException('op tag must have an operation name');
                }
                $currentTags = [];

                // NIP-EX: sort/slice parameters are inline in the op tag.
                // e.g. ["op","sort","tag","published_at","desc"] or ["op","slice","0","20"]
                // Synthesize a stage-local tag from the extra elements so StageParser
                // can handle them uniformly.
                if (count($tag) > 2) {
                    $currentTags[] = array_merge([$currentOp], array_slice($tag, 2));
                }
            } else {
                $currentTags[] = $tag;
            }
        }

        // Save last segment
        if ($currentOp !== null) {
            $segments[] = ['op' => $currentOp, 'tags' => $currentTags];
        }

        // 3. Validate: at least one op
        if (empty($segments)) {
            throw new InvalidArgumentException('Expression must have at least one op tag');
        }

        // 4. Parse each segment into a Stage
        $stages = [];
        foreach ($segments as $i => $segment) {
            $stages[] = $this->stageParser->parse(
                $segment['op'],
                $segment['tags'],
                $i === 0,
            );
        }

        return new Pipeline($dTag, $stages);
    }
}

