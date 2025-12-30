<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Article;
use BitWasp\Bech32\Exception\Bech32Exception;
use Exception;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Nip19\Nip19Helper;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class Filters extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('shortenNpub', [$this, 'shortenNpub']),
            new TwigFilter('linkify', [$this, 'linkify'], ['is_safe' => ['html']]),
            new TwigFilter('mentionify', [$this, 'mentionify'], ['is_safe' => ['html']]),
            new TwigFilter('nEncode', [$this, 'nEncode']),
            new TwigFilter('naddrEncode', [$this, 'naddrEncode']),
            new TwigFilter('toNpub', [$this, 'toNpub']),
            new TwigFilter('toHex', [$this, 'toHex']),
        ];
    }

    public function shortenNpub(string $npub): string
    {
        return substr($npub, 0, 8) . '…' . substr($npub, -4);
    }

    public function linkify(string $text): string
    {
        return preg_replace_callback(
            '#\b((https?://|www\.)[^\s<]+)#i',
            function ($matches) {
                $url = $matches[0];
                $href = str_starts_with($url, 'http') ? $url : 'https://' . $url;

                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                    htmlspecialchars($href, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                );
            },
            $text
        );
    }

    public function mentionify(string $text): string
    {
        return preg_replace_callback(
            '/@(?<npub>npub1[0-9a-z]{10,})/i',
            function ($matches) {
                $npub = $matches['npub'];
                $short = substr($npub, 0, 8) . '…' . substr($npub, -4);

                return sprintf(
                    '<a href="/p/%s" class="mention-link">@%s</a>',
                    htmlspecialchars($npub, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($short, ENT_QUOTES, 'UTF-8')
                );
            },
            $text
        );
    }

    public function nEncode(string $eventId): string
    {
        $nip19 = new Nip19Helper();
        try {
            return $nip19->encodeNote($eventId);
        } catch (Bech32Exception) {
            return $eventId; // Return original if encoding fails
        }
    }

    /**
     * @throws Bech32Exception
     * @throws Exception
     */
    public function naddrEncode(Article $article): string
    {
        $nip19 = new Nip19Helper();
        if ($article->getRaw() !== null) {
            $event = Event::fromVerified((object)$article->getRaw());
            if ($event === null) {
                return $nip19->encodeNote($article->getEventId());
            }
            return $nip19->encodeAddr($event, $article->getSlug(), $article->getKind()->value);
        } else {
            return $nip19->encodeNote($article->getEventId());
        }
    }

    public function toNpub(string $hexPubKey): string
    {
        $key = new Key();
        return $key->convertPublicKeyToBech32($hexPubKey);
    }

    /**
     * @throws Exception
     */
    public function toHex(string $npub): string
    {
        $key = new Key();
        return $key->convertToHex($npub);
    }
}
