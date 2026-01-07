<?php

namespace App\Util;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;

class IndexableArticleChecker
{
    public static function isIndexable(Article $article): bool
    {
        // Don't index drafts - they're private working copies
        if ($article->getKind() === KindsEnum::LONGFORM_DRAFT->value) {
            return false;
        }

        // Don't index articles explicitly marked as do not index
        if ($article->getIndexStatus() === IndexStatusEnum::DO_NOT_INDEX) {
            return false;
        }

        return true;
    }
}
