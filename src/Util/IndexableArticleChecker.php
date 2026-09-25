<?php

namespace App\Util;

use App\Entity\Article;
use App\Enum\IndexStatusEnum;
use App\Enum\KindsEnum;
use App\Service\MutedPubkeysService;

class IndexableArticleChecker
{
    public function __construct(private readonly MutedPubkeysService $mutedPubkeysService)
    {
    }

    public function isIndexable(Article $article): bool
    {
        // Drafts are private working copies.
        if ($article->getKind() === KindsEnum::LONGFORM_DRAFT) {
            return false;
        }

        if ($article->getIndexStatus() === IndexStatusEnum::DO_NOT_INDEX) {
            return false;
        }

        return !$this->isMutedAuthor($article);
    }

    public function isMutedAuthor(Article $article): bool
    {
        $pubkey = strtolower(trim($article->getPubkey() ?? ''));

        return $pubkey !== '' && in_array($pubkey, $this->mutedPubkeysService->getMutedPubkeys(), true);
    }
}
