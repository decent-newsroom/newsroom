<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Article;
use App\Entity\Event as AppEvent;
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
    public function naddrEncode(Article|AppEvent $entity): string
    {
        $nip19 = new Nip19Helper();

        // Handle App\Entity\Event (e.g., magazines, lists)
        if ($entity instanceof AppEvent) {
            $slug = $entity->getSlug();
            if ($slug === null) {
                return $nip19->encodeNote($entity->getEventId() ?? $entity->getId());
            }

            // Create a swentel Event for encoding
            $event = new Event();
            $event->setId($entity->getEventId() ?? $entity->getId());
            $event->setPublicKey($entity->getPubkey());
            $event->setKind($entity->getKind());

            return $nip19->encodeAddr($event, $slug, $entity->getKind());
        }

        // Handle Article entity
        if ($entity->getRaw() !== null) {
            $event = Event::fromVerified((object)$entity->getRaw());
            if ($event === null) {
                return $nip19->encodeNote($entity->getEventId());
            }
            return $nip19->encodeAddr($event, $entity->getSlug(), $entity->getKind()->value);
        } else {
            return $nip19->encodeNote($entity->getEventId());
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
