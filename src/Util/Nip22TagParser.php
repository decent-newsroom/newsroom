<?php

declare(strict_types=1);

namespace App\Util;

/**
 * NIP-22 comment tag parser.
 *
 * Extracts structured metadata from kind-1111 comment tags,
 * respecting the uppercase (root scope) / lowercase (parent scope) convention.
 */
final class Nip22TagParser
{
    /**
     * Parse NIP-22 tags from a single comment.
     *
     * @param array $tags  The event's tags array
     * @return array{
     *     rootKind: ?string,
     *     parentKind: ?string,
     *     rootPubkey: ?string,
     *     parentPubkeys: string[],
     *     parentEventId: ?string,
     *     rootEventId: ?string,
     *     rootAddress: ?string,
     *     parentAddress: ?string,
     * }
     */
    public static function parse(array $tags): array
    {
        $result = [
            'rootKind'       => null,
            'parentKind'     => null,
            'rootPubkey'     => null,
            'parentPubkeys'  => [],
            'parentEventId'  => null,
            'rootEventId'    => null,
            'rootAddress'    => null,
            'parentAddress'  => null,
        ];

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            $name  = $tag[0];
            $value = $tag[1];

            switch ($name) {
                case 'K':
                    $result['rootKind'] = (string) $value;
                    break;
                case 'k':
                    $result['parentKind'] = (string) $value;
                    break;
                case 'P':
                    $result['rootPubkey'] = (string) $value;
                    break;
                case 'p':
                    $result['parentPubkeys'][] = (string) $value;
                    break;
                case 'E':
                    $result['rootEventId'] = (string) $value;
                    break;
                case 'e':
                    // Last lowercase 'e' wins (direct parent)
                    $result['parentEventId'] = (string) $value;
                    break;
                case 'A':
                    $result['rootAddress'] = (string) $value;
                    break;
                case 'a':
                    $result['parentAddress'] = (string) $value;
                    break;
            }
        }

        return $result;
    }

    /**
     * Whether the comment is a reply to another comment (not a top-level comment).
     */
    public static function isReplyToComment(array $tags): bool
    {
        return self::parse($tags)['parentKind'] === '1111';
    }

    /**
     * Collect all pubkeys referenced in a comment's tags that need profile hydration.
     *
     * Returns the comment author's pubkey, all lowercase 'p' pubkeys,
     * and (for zaps) the uppercase 'P' pubkey.
     *
     * @param string|null $authorPubkey  The event's pubkey field
     * @param array       $tags          The event's tags
     * @param int|null    $kind          The event's kind
     * @return string[] Unique pubkeys
     */
    public static function collectPubkeys(?string $authorPubkey, array $tags, ?int $kind = null): array
    {
        $keys = [];

        if (!empty($authorPubkey)) {
            $keys[] = $authorPubkey;
        }

        foreach ($tags as $tag) {
            if (!is_array($tag) || count($tag) < 2) {
                continue;
            }

            // Lowercase 'p' — parent comment author (always collect)
            if ($tag[0] === 'p' && !empty($tag[1])) {
                $keys[] = (string) $tag[1];
            }

            // Uppercase 'P' — for zaps this is the sender
            if ($kind === 9735 && $tag[0] === 'P' && !empty($tag[1])) {
                $keys[] = (string) $tag[1];
            }
        }

        return array_values(array_unique($keys));
    }
}

