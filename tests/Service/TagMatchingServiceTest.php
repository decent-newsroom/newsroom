<?php

namespace App\Tests\Service;

use App\Service\TagMatchingService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TagMatchingServiceTest extends TestCase
{
    private TagMatchingService $service;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new TagMatchingService($this->logger);
    }

    public function testFindMatchingCategory_ExactMatch(): void
    {
        $rssCategories = ['artificial-intelligence', 'research'];
        $nzineCategories = [
            [
                'name' => 'AI & ML',
                'slug' => 'ai-ml',
                'tags' => ['artificial-intelligence', 'machine-learning', 'AI']
            ],
            [
                'name' => 'Blockchain',
                'slug' => 'blockchain',
                'tags' => ['crypto', 'blockchain']
            ]
        ];

        $result = $this->service->findMatchingCategory($rssCategories, $nzineCategories);

        $this->assertNotNull($result);
        $this->assertEquals('AI & ML', $result['name']);
        $this->assertEquals('ai-ml', $result['slug']);
    }

    public function testFindMatchingCategory_CaseInsensitive(): void
    {
        $rssCategories = ['ai', 'RESEARCH'];
        $nzineCategories = [
            [
                'name' => 'AI & ML',
                'slug' => 'ai-ml',
                'tags' => ['AI', 'MachineLearning']
            ]
        ];

        $result = $this->service->findMatchingCategory($rssCategories, $nzineCategories);

        $this->assertNotNull($result);
        $this->assertEquals('AI & ML', $result['name']);
    }

    public function testFindMatchingCategory_NoMatch(): void
    {
        $rssCategories = ['sports', 'entertainment'];
        $nzineCategories = [
            [
                'name' => 'AI & ML',
                'slug' => 'ai-ml',
                'tags' => ['AI', 'machine-learning']
            ]
        ];

        $result = $this->service->findMatchingCategory($rssCategories, $nzineCategories);

        $this->assertNull($result);
    }

    public function testFindMatchingCategory_FirstMatchWins(): void
    {
        $rssCategories = ['python'];
        $nzineCategories = [
            [
                'name' => 'AI & ML',
                'slug' => 'ai-ml',
                'tags' => ['python', 'AI']
            ],
            [
                'name' => 'Programming',
                'slug' => 'programming',
                'tags' => ['python', 'coding']
            ]
        ];

        $result = $this->service->findMatchingCategory($rssCategories, $nzineCategories);

        $this->assertNotNull($result);
        $this->assertEquals('AI & ML', $result['name']); // First category should win
    }

    public function testFindMatchingCategory_CommaSeparatedTags(): void
    {
        $rssCategories = ['blockchain'];
        $nzineCategories = [
            [
                'name' => 'Blockchain',
                'slug' => 'blockchain',
                'tags' => 'crypto,blockchain,bitcoin' // Comma-separated string
            ]
        ];

        $result = $this->service->findMatchingCategory($rssCategories, $nzineCategories);

        $this->assertNotNull($result);
        $this->assertEquals('Blockchain', $result['name']);
    }

    public function testExtractAllTags(): void
    {
        $nzineCategories = [
            [
                'name' => 'AI & ML',
                'slug' => 'ai-ml',
                'tags' => ['AI', 'machine-learning']
            ],
            [
                'name' => 'Blockchain',
                'slug' => 'blockchain',
                'tags' => ['crypto', 'blockchain', 'AI'] // Duplicate 'AI'
            ]
        ];

        $result = $this->service->extractAllTags($nzineCategories);

        $this->assertCount(4, $result); // Should have 4 unique tags
        $this->assertContains('AI', $result);
        $this->assertContains('machine-learning', $result);
        $this->assertContains('crypto', $result);
        $this->assertContains('blockchain', $result);
    }

    public function testExtractAllTags_WithCommaSeparated(): void
    {
        $nzineCategories = [
            [
                'name' => 'Tech',
                'slug' => 'tech',
                'tags' => 'ai,blockchain,coding' // Comma-separated
            ]
        ];

        $result = $this->service->extractAllTags($nzineCategories);

        $this->assertCount(3, $result);
        $this->assertContains('ai', $result);
        $this->assertContains('blockchain', $result);
        $this->assertContains('coding', $result);
    }
}

