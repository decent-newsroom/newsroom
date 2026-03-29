<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;

class NostrEventRenderer implements NodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable|string|null
    {
        if (!($node instanceof NostrSchemeData)) {
            throw new \InvalidArgumentException('Incompatible inline node type: ' . get_class($node));
        }

        // Return the original nostr: URL as plain text so that
        // processNostrLinks() can batch-resolve and render a proper
        // placeholder card or rich embed for it.
        return 'nostr:' . $node->getSpecial();
    }
}
