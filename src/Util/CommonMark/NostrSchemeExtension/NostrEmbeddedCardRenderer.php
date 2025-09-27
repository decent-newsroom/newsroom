<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

class NostrEmbeddedCardRenderer implements NodeRendererInterface
{
    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        if (!($node instanceof NostrEmbeddedCard)) {
            throw new \InvalidArgumentException('Incompatible inline node type: ' . get_class($node));
        }

        // Return the raw HTML content for the embedded card
        return $node->getHtmlContent();
    }
}
