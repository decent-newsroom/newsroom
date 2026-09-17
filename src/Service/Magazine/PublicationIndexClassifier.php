<?php

declare(strict_types=1);

namespace App\Service\Magazine;

use App\Enum\KindsEnum;

final class PublicationIndexClassifier
{
    /**
     * A 30040 index is a bookshelf publication when it is either a library
     * card without relationships or an index that points to 30041 chapters.
     *
     * @param array<int, mixed> $tags
     */
    public function isBook(array $tags): bool
    {
        $hasRelationship = false;

        foreach ($tags as $tag) {
            if (!is_array($tag) || !isset($tag[0], $tag[1]) || !is_string($tag[0])) {
                continue;
            }

            if ($tag[0] === 'a') {
                $hasRelationship = true;
                if (!is_string($tag[1])) {
                    continue;
                }

                $parts = explode(':', $tag[1], 3);
                if (count($parts) === 3 && (int) $parts[0] === KindsEnum::PUBLICATION_CONTENT->value) {
                    return true;
                }
            } elseif ($tag[0] === 'e' || $tag[0] === 'E') {
                $hasRelationship = true;
            }
        }

        return !$hasRelationship;
    }
}
