<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LatestArticles;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use PHPUnit\Framework\TestCase;

class LatestArticlesExclusionPolicyTest extends TestCase
{
    public function testExcludesByPubkeyDenylist(): void
    {
        $policy = new LatestArticlesExclusionPolicy(excludedPubkeys: ['abc']);

        $article = new Article();
        $article->setPubkey('abc');

        self::assertTrue($policy->shouldExclude($article));
    }

    public function testExcludesBotProfilesWhenEnabled(): void
    {
        $policy = new LatestArticlesExclusionPolicy(excludedPubkeys: [], excludeBotProfiles: true);

        $article = new Article();
        $article->setPubkey('author');

        $meta = new UserMetadata(bot: true);

        self::assertTrue($policy->shouldExclude($article, $meta));
    }

    public function testDoesNotExcludeNonBotProfile(): void
    {
        $policy = new LatestArticlesExclusionPolicy(excludedPubkeys: [], excludeBotProfiles: true);

        $article = new Article();
        $article->setPubkey('author');

        $meta = new UserMetadata(bot: false);

        self::assertFalse($policy->shouldExclude($article, $meta));
    }
}
