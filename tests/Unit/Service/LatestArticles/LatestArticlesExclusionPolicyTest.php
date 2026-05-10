<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\LatestArticles;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use App\Service\MutedPubkeysService;
use PHPUnit\Framework\TestCase;

class LatestArticlesExclusionPolicyTest extends TestCase
{
    private function createMutedService(array $pubkeys = []): MutedPubkeysService
    {
        $mock = $this->createMock(MutedPubkeysService::class);
        $mock->method('getMutedPubkeys')->willReturn($pubkeys);
        return $mock;
    }

    public function testExcludesByPubkeyDenylist(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(),
            excludedPubkeys: ['abc'],
        );

        $article = new Article();
        $article->setPubkey('abc');

        self::assertTrue($policy->shouldExclude($article));
    }

    public function testExcludesBotProfilesWhenEnabled(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(),
            excludedPubkeys: [],
            excludeBotProfiles: true,
        );

        $article = new Article();
        $article->setPubkey('author');

        $meta = new UserMetadata(bot: true);

        self::assertTrue($policy->shouldExclude($article, $meta));
    }

    public function testDoesNotExcludeNonBotProfile(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(),
            excludedPubkeys: [],
            excludeBotProfiles: true,
        );

        $article = new Article();
        $article->setPubkey('author');

        $meta = new UserMetadata(bot: false);

        self::assertFalse($policy->shouldExclude($article, $meta));
    }

    public function testExcludesReferralCodeSpamArticles(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(),
        );

        $article = new Article();
        $article->setPubkey('author');
        $article->setTitle('Skinrave Referral Code SKINRAVE2026 — 2026 Bonus Activation Guide');
        $article->setSummary('Complete walkthrough on how to claim the welcome bonus with step-by-step tips.');
        $article->setContent('Using referral code SKINRAVE2026 unlocks the full welcome bonus when signing up.');

        self::assertTrue($policy->shouldExclude($article));
    }

    public function testExcludesReferralCodeSpamCachedPayload(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(),
        );

        $payload = [
            'pubkey' => 'author',
            'title' => 'CSGORoll Referral Code ROLLBONUS2026 — 2026 Bonus Activation Guide',
            'summary' => 'Complete walkthrough on how to claim the welcome bonus with step-by-step tips.',
            'content' => 'Using referral code ROLLBONUS2026 unlocks the full welcome bonus when signing up.',
        ];

        self::assertTrue($policy->shouldExcludeArticleData($payload));
    }

    public function testGetAllExcludedPubkeysMergesConfigAndMuted(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(['muted1', 'muted2']),
            excludedPubkeys: ['config1', 'muted1'], // overlap should be deduped
        );

        $result = $policy->getAllExcludedPubkeys();

        self::assertCount(3, $result);
        self::assertContains('config1', $result);
        self::assertContains('muted1', $result);
        self::assertContains('muted2', $result);
    }

    public function testGetAllExcludedPubkeysReturnsEmptyWhenNoExclusions(): void
    {
        $policy = new LatestArticlesExclusionPolicy(
            mutedPubkeysService: $this->createMutedService(),
        );

        self::assertSame([], $policy->getAllExcludedPubkeys());
    }
}
