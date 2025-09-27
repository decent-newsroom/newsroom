<?php

namespace App\Util\CommonMark\ImagesExtension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;

class RawImageLinkParser implements InlineParserInterface
{
    public function getMatchDefinition(): InlineParserMatch
    {
        // Match URLs ending with an image extension
        return InlineParserMatch::regex('https?:\/\/[^\s]+?\.(?:jpg|jpeg|png|gif|webp)(?=\s|$)');
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        $match = $inlineContext->getFullMatch();

        // Create an inline Image element directly (not wrapped in a paragraph)
        $image = new Image($match, '', $match);
        $inlineContext->getContainer()->appendChild($image);

        // Advance the cursor to consume the matched part
        $cursor->advanceBy(strlen($match));

        return true;
    }
}
