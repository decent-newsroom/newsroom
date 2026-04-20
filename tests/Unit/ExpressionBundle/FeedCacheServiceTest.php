<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use App\Entity\Event;
use App\ExpressionBundle\Model\NormalizedItem;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Service\FeedCacheService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

class FeedCacheServiceTest extends TestCase
{
    private ArrayAdapter $cachePool;
    private FeedCacheService $service;

    protected function setUp(): void
    {
        $this->cachePool = new ArrayAdapter();
        $this->service = new FeedCacheService($this->cachePool, new NullLogger(), 300);
    }

    public function testGetReturnsNullOnCacheMiss(): void
    {
        $this->assertNull($this->service->get('nonexistent_key'));
    }

    public function testGetReturnsCachedArray(): void
    {
        $items = [new NormalizedItem($this->makeEvent('id1', 30023))];
        $this->service->getOrSet('test_key', fn() => $items);

        $result = $this->service->get('test_key');
        $this->assertNotNull($result);
        $this->assertCount(1, $result);
        $this->assertSame('id1', $result[0]->getId());
    }

    public function testGetOrSetReturnsCachedOnSecondCall(): void
    {
        $callCount = 0;
        $factory = function () use (&$callCount) {
            $callCount++;
            return [new NormalizedItem($this->makeEvent('id1', 30023))];
        };

        $this->service->getOrSet('key', $factory);
        $this->service->getOrSet('key', $factory);

        $this->assertSame(1, $callCount, 'Factory should only be called once');
    }

    public function testBuildKeyDeterministic(): void
    {
        $expression = $this->makeEvent('expr1', 30880);
        $ctx = new RuntimeContext('pubkey1', ['c1', 'c2'], ['t1'], time());

        $key1 = $this->service->buildKey($expression, $ctx);
        $key2 = $this->service->buildKey($expression, $ctx);

        $this->assertSame($key1, $key2);
        $this->assertStringStartsWith('feed_', $key1);
    }

    public function testBuildKeyDiffersPerUser(): void
    {
        $expression = $this->makeEvent('expr1', 30880);
        $ctx1 = new RuntimeContext('pubkey1', [], [], time());
        $ctx2 = new RuntimeContext('pubkey2', [], [], time());

        $this->assertNotSame(
            $this->service->buildKey($expression, $ctx1),
            $this->service->buildKey($expression, $ctx2),
        );
    }

    public function testBuildKeyDiffersPerContacts(): void
    {
        $expression = $this->makeEvent('expr1', 30880);
        $ctx1 = new RuntimeContext('pubkey1', ['c1'], [], time());
        $ctx2 = new RuntimeContext('pubkey1', ['c1', 'c2'], [], time());

        $this->assertNotSame(
            $this->service->buildKey($expression, $ctx1),
            $this->service->buildKey($expression, $ctx2),
        );
    }

    public function testBuildKeyDiffersWhenExpressionRepublished(): void
    {
        // Simulate edit: same kind/pubkey/d-tag but newer created_at
        $original = $this->makeEvent('expr1', 30880);
        $original->setCreatedAt(1_700_000_000);

        $edited = $this->makeEvent('expr1', 30880);
        $edited->setCreatedAt(1_700_000_500);

        $ctx = new RuntimeContext('pubkey1', ['c1'], ['t1'], time());

        $this->assertNotSame(
            $this->service->buildKey($original, $ctx),
            $this->service->buildKey($edited, $ctx),
            'Republishing an expression (new created_at) must yield a new cache key'
        );
    }

    private function makeEvent(string $id, int $kind): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey(str_repeat('aa', 32));
        $event->setKind($kind);
        $event->setContent('');
        $event->setCreatedAt(time());
        $event->setTags([['d', 'test-dtag']]);
        $event->setSig('');
        return $event;
    }
}

