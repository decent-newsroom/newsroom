<?php

declare(strict_types=1);

namespace App\Tests\Unit\ReadModel;

use App\Entity\Highlight;
use App\ReadModel\RedisView\RedisViewFactory;
use App\Service\Cache\RedisCacheService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RedisViewFactoryHighlightTest extends TestCase
{
    public function testHighlightViewUsesValidLowercaseNip84EventReference(): void
    {
        $eventId = str_repeat('a', 64);
        $highlight = (new Highlight())
            ->setEventId(str_repeat('b', 64))
            ->setPubkey(str_repeat('c', 64))
            ->setCreatedAt(123)
            ->setRawEvent([
                'tags' => [
                    ['E', str_repeat('d', 64)],
                    ['e', strtoupper($eventId)],
                ],
            ]);

        $view = $this->factory()->highlightToView($highlight);

        self::assertSame($eventId, $view->refs['event_ref']);
    }

    public function testHighlightViewIgnoresUppercaseEventTag(): void
    {
        $highlight = (new Highlight())
            ->setEventId(str_repeat('b', 64))
            ->setPubkey(str_repeat('c', 64))
            ->setCreatedAt(123)
            ->setRawEvent(['tags' => [['E', str_repeat('d', 64)]]]);

        $view = $this->factory()->highlightToView($highlight);

        self::assertNull($view->refs['event_ref']);
    }

    private function factory(): RedisViewFactory
    {
        return new RedisViewFactory(
            $this->createMock(RedisCacheService::class),
            new NullLogger(),
        );
    }
}
