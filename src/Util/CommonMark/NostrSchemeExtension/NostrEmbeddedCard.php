<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Node\Inline\AbstractInline;

/**
 * Class NostrEmbeddedCard
 * Represents an embedded HTML card for Nostr events
 */
class NostrEmbeddedCard extends AbstractInline
{
    private string $htmlContent;

    public function __construct(string $htmlContent)
    {
        parent::__construct();
        $this->htmlContent = $htmlContent;
    }

    public function getHtmlContent(): string
    {
        return $this->htmlContent;
    }
}
