<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use swentel\nostr\Key\Key;

/**
 * Class NostrMentionParser
 * Looks for links that look like Markdown links in the format `[label](url)`,
 * but have npub1XXXX instead of a URL
 * @package App\Util\CommonMark
 */
class NostrMentionParser implements InlineParserInterface
{

    public function getMatchDefinition(): InlineParserMatch
    {
        // Define a match for a markdown link-like structure with "npub" links
        return InlineParserMatch::regex('\[([^\]]+)\]\(npub1[0-9a-zA-Z]+\)');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        // Get the match and extract relevant parts
        $matches = $inlineContext->getMatches();

        // The entire match is like "[label](npubXXXX)", now we need to extract "label" and "npubXXXX"
        $fullMatch = $matches[0];  // Full matched string like "[label](npubXXXX)"
        $label = $matches[1];      // This is the text between the square brackets: "label"

        // Extract "npub" part from fullMatch
        $npubLink = substr($fullMatch, strpos($fullMatch, 'npub1'), -1);  // e.g., "npubXXXX"
        $npubPart = substr($npubLink, 5);  // Extract the part after "npub1", i.e., "XXXX"

        $key = new Key();
        $hex = $key->convertToHex($npubLink);

        // Create a new inline node for the custom link
        $inlineContext->getContainer()->appendChild(new NostrMentionLink($label, $hex));

        // Advance the cursor to consume the matched part (important!)
        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}