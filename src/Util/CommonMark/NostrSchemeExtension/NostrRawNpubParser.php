<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\Cache\RedisCacheService;
use App\Util\NostrKeyUtil;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

/**
 * Class NostrRawNpubParser
 * Looks for raw nostr mentions formatted as npub1XXXX
 */
readonly class NostrRawNpubParser implements InlineParserInterface
{

    public function __construct(
        private RedisCacheService $redisCacheService,
        private NostrKeyUtil $nostrKeyUtil,
    )
    {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('npub1[0-9a-zA-Z]+');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        // Get the match and extract relevant parts
        $fullMatch = $inlineContext->getFullMatch();

        $meta = $this->redisCacheService->getMetadata($this->nostrKeyUtil->npubToHex($fullMatch));

        // Use shortened npub as default name, from first and last 8 characters of the fullMatch
        $name =  substr($fullMatch, 0, 8) . '...' . substr($fullMatch, -8);
        if (!empty($meta->display_name)) {
            // If we have a name, use it
            $name = $meta->name;
        } else if (!empty($meta->name)) {
            // If we have a name, use it
            $name = $meta->name;
        }

        // Create a new inline node for the custom link
        $inlineContext->getContainer()->appendChild(new NostrMentionLink($name, $fullMatch));

        // Advance the cursor to consume the matched part (important!)
        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
