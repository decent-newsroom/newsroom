<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

class NostrEventRenderer implements NodeRendererInterface
{

    public function render(Node $node, ChildNodeRendererInterface $childRenderer)
    {
        if (!($node instanceof NostrSchemeData)) {
            throw new \InvalidArgumentException('Incompatible inline node type: ' . get_class($node));
        }

        // Skip naddr links - they will be processed later by the batched converter
        // which can fetch events and render proper article/event cards
        if ($node->getType() === 'naddr') {
            // Return the original nostr: URL as plain text so it can be processed later
            return 'nostr:' . $node->getSpecial();
        }

        if ($node->getType() === 'nevent' || $node->getType() === 'note') {
            // Construct the local link URL from the special part
            $url = '/e/' . $node->getSpecial();
        }

        if (isset($url)) {
            // Create the anchor element
            return new HtmlElement('a', ['href' => $url], '@' . $this->labelFromKey($node->getSpecial()));
        }

        return false;

    }

    private function labelFromKey($key): string
    {
        $start = substr($key, 0, 8);
        $end = substr($key, -8);
        return $start . '…' . $end;
    }
}
