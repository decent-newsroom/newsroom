<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use nostriphant\NIP19\Bech32;

/** Render NIP-21 references in untrusted comment text without allowing comment HTML. */
final class CommentContentRenderer
{
    private const LINK_PATTERN = '~nostr:([a-z0-9]+)~i';

    /** @return list<string> */
    public static function profilePubkeys(string $content): array
    {
        preg_match_all(self::LINK_PATTERN, $content, $matches);
        $pubkeys = [];

        foreach ($matches[1] ?? [] as $identifier) {
            $profile = self::decodeProfile($identifier);
            if ($profile !== null) {
                $pubkeys[$profile['pubkey']] = true;
            }
        }

        return array_keys($pubkeys);
    }

    /** @param array<string, ProfileMetadata> $metadataByPubkey */
    public static function render(string $content, array $metadataByPubkey, string $platformBaseUrl): string
    {
        $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = preg_split(self::LINK_PATTERN, $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return self::escape($content);
        }

        $html = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $html .= self::escape($part);
                continue;
            }

            $profile = self::decodeProfile($part);
            if ($profile !== null) {
                $npub = $profile['npub'];
                $metadata = $metadataByPubkey[$profile['pubkey']] ?? null;
                $name = $metadata?->displayName ?: $metadata?->name;
                $label = $name ?: substr($npub, 0, 12) . '…' . substr($npub, -4);
                $href = rtrim($platformBaseUrl, '/') . '/p/' . $npub;
                $html .= '<a href="' . self::escape($href) . '" class="nostr-mention">@' . self::escape($label) . '</a>';
                continue;
            }

            try {
                $decoded = new Bech32(strtolower($part));
                if (in_array($decoded->type, ['note', 'nevent', 'naddr'], true)) {
                    $href = rtrim($platformBaseUrl, '/') . '/e/' . strtolower($part);
                    $html .= '<a href="' . self::escape($href) . '" class="nostr-link">' . self::wrapIdentifier($part) . '</a>';
                    continue;
                }
            } catch (\Throwable) {
                // Malformed identifiers remain plain text.
            }

            $html .= self::wrapIdentifier('nostr:' . $part);
        }

        return $html;
    }

    /** @return array{pubkey: string, npub: string}|null */
    private static function decodeProfile(string $identifier): ?array
    {
        try {
            $decoded = new Bech32(strtolower($identifier));
            if ($decoded->type === 'npub') {
                return ['pubkey' => $decoded(), 'npub' => strtolower($identifier)];
            }
            if ($decoded->type === 'nprofile') {
                $pubkey = $decoded->data->pubkey;
                return ['pubkey' => $pubkey, 'npub' => (string) Bech32::npub($pubkey)];
            }
        } catch (\Throwable) {
            // Malformed identifiers remain plain text.
        }

        return null;
    }

    private static function wrapIdentifier(string $identifier): string
    {
        return implode(
            '<wbr>',
            array_map(static fn (string $chunk): string => self::escape($chunk), str_split($identifier, 24)),
        );
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
