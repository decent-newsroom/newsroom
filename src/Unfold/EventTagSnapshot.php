<?php

declare(strict_types=1);

namespace App\Unfold;

final class EventTagSnapshot
{
    /** @return list<list<string>> */
    public static function validate(mixed $tags): array
    {
        if (!is_array($tags) || !array_is_list($tags)) {
            throw new \UnexpectedValueException('Invalid Nostr event tag snapshot.');
        }
        foreach ($tags as $tag) {
            if (!is_array($tag) || !array_is_list($tag)) {
                throw new \UnexpectedValueException('Invalid Nostr event tag snapshot.');
            }
            foreach ($tag as $value) {
                if (!is_string($value)) {
                    throw new \UnexpectedValueException('Invalid Nostr event tag snapshot.');
                }
            }
        }

        return $tags;
    }
}
