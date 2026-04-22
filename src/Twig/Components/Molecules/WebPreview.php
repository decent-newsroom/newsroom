<?php

declare(strict_types=1);

namespace App\Twig\Components\Molecules;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Renders an opt-in link-card for an external web URL.
 *
 * Does NOT fetch remote content at render time — the comment page
 * shouldn't contact arbitrary third-party URLs on the reader's behalf
 * without consent. The card shows the target host and a "Load preview"
 * CTA; clicking it asks the Stimulus controller to hit
 * `/api/web-preview?url=…` and swap in the rich preview.
 *
 * Used on the single-event page to visualize NIP-22 comment `I` / `i`
 * tags (NIP-73 external identity with kind "web").
 */
#[AsTwigComponent]
final class WebPreview
{
    public string $url = '';
    public ?string $label = null;

    public function mount(string $url, ?string $label = null): void
    {
        $this->url = $url;
        $this->label = $label;
    }

    public function getHost(): string
    {
        $host = parse_url($this->url, PHP_URL_HOST);
        return is_string($host) ? $host : $this->url;
    }
}


