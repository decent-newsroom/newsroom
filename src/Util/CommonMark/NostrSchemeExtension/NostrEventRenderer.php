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

        if ($node->getType() === 'nevent' || $node->getType() === 'note') {
            // Construct the local link URL from the special part
            $url = '/e/' . $node->getSpecial();
        } else if ($node->getType() === 'naddr') {
            // dump($node);
            // Construct the local link URL from the special part
            $url = '/article/' .  $node->getSpecial();
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
