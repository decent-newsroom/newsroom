<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\AuthorContentType;
use App\Enum\KindBundles;
use App\Enum\KindsEnum;
use PHPUnit\Framework\TestCase;

class KindBundlesTest extends TestCase
{
    public function testUserContextContainsExpectedKinds(): void
    {
        $expected = [0, 3, 5, 10000, 10001, 10002, 10003, 10015, 10020, 10063, 30015, 39089];
        $this->assertSame($expected, KindBundles::USER_CONTEXT);
    }

    public function testArticleSocialContainsExpectedKinds(): void
    {
        $expected = [7, 1111, 1985, 9734, 9735, 9802];
        $this->assertSame($expected, KindBundles::ARTICLE_SOCIAL);
    }

    public function testAuthorContentContainsExpectedKinds(): void
    {
        $this->assertContains(KindsEnum::LONGFORM->value, KindBundles::AUTHOR_CONTENT);
        $this->assertContains(KindsEnum::IMAGE->value, KindBundles::AUTHOR_CONTENT);
        $this->assertContains(KindsEnum::HIGHLIGHTS->value, KindBundles::AUTHOR_CONTENT);
        $this->assertContains(KindsEnum::PLAYLIST->value, KindBundles::AUTHOR_CONTENT);
    }

    public function testGroupByKindGroupsCorrectly(): void
    {
        $events = [
            (object) ['kind' => 0, 'created_at' => 100, 'id' => 'a'],
            (object) ['kind' => 3, 'created_at' => 200, 'id' => 'b'],
            (object) ['kind' => 0, 'created_at' => 300, 'id' => 'c'],
            (object) ['kind' => 3, 'created_at' => 100, 'id' => 'd'],
        ];

        $grouped = KindBundles::groupByKind($events);

        $this->assertCount(2, $grouped);
        $this->assertCount(2, $grouped[0]);
        $this->assertCount(2, $grouped[3]);

        // Newest first within each group
        $this->assertSame('c', $grouped[0][0]->id);
        $this->assertSame('a', $grouped[0][1]->id);
        $this->assertSame('b', $grouped[3][0]->id);
        $this->assertSame('d', $grouped[3][1]->id);
    }

    public function testLatestByKindReturnsNewest(): void
    {
        $events = [
            (object) ['kind' => 0, 'created_at' => 100, 'id' => 'old'],
            (object) ['kind' => 0, 'created_at' => 300, 'id' => 'new'],
            (object) ['kind' => 3, 'created_at' => 200, 'id' => 'follows'],
        ];

        $latest = KindBundles::latestByKind($events);

        $this->assertCount(2, $latest);
        $this->assertSame('new', $latest[0]->id);
        $this->assertSame('follows', $latest[3]->id);
    }

    public function testLatestByKindEmptyInput(): void
    {
        $this->assertSame([], KindBundles::latestByKind([]));
    }

    public function testCategorizeArticleSocial(): void
    {
        $events = [
            (object) ['kind' => 7, 'id' => 'reaction1'],
            (object) ['kind' => 1111, 'id' => 'comment1'],
            (object) ['kind' => 1111, 'id' => 'comment2'],
            (object) ['kind' => 1985, 'id' => 'label1'],
            (object) ['kind' => 9734, 'id' => 'zapreq1'],
            (object) ['kind' => 9735, 'id' => 'zap1'],
            (object) ['kind' => 9802, 'id' => 'highlight1'],
            (object) ['kind' => 99999, 'id' => 'unknown'],
        ];

        $result = KindBundles::categorizeArticleSocial($events);

        $this->assertCount(1, $result['reactions']);
        $this->assertCount(2, $result['comments']);
        $this->assertCount(1, $result['labels']);
        $this->assertCount(1, $result['zap_requests']);
        $this->assertCount(1, $result['zaps']);
        $this->assertCount(1, $result['highlights']);
    }

    public function testCategorizeArticleSocialEmpty(): void
    {
        $result = KindBundles::categorizeArticleSocial([]);

        $this->assertSame([], $result['reactions']);
        $this->assertSame([], $result['comments']);
        $this->assertSame([], $result['labels']);
        $this->assertSame([], $result['zap_requests']);
        $this->assertSame([], $result['zaps']);
        $this->assertSame([], $result['highlights']);
    }
}

