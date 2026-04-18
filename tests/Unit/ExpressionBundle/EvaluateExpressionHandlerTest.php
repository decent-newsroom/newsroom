<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use App\Message\EvaluateExpressionMessage;
use App\MessageHandler\EvaluateExpressionHandler;
use App\Repository\EventRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Tests the EvaluateExpressionHandler for paths that don't require ExpressionService.
 * ExpressionService is final and can't be mocked, so we test only the
 * "expression not found" and "Mercure failure" paths via reflection.
 */
class EvaluateExpressionHandlerTest extends TestCase
{
    private EventRepository $eventRepository;
    private HubInterface $hub;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->hub = $this->createMock(HubInterface::class);
    }

    private function createHandlerWithoutExpressionService(): EvaluateExpressionHandler
    {
        // Create via reflection, only setting the properties needed for "not found" path
        $ref = new \ReflectionClass(EvaluateExpressionHandler::class);
        $handler = $ref->newInstanceWithoutConstructor();

        $ref->getProperty('eventRepository')->setValue($handler, $this->eventRepository);
        $ref->getProperty('hub')->setValue($handler, $this->hub);
        $ref->getProperty('logger')->setValue($handler, new NullLogger());
        // expressionService is not set — OK because "not found" path returns before using it

        return $handler;
    }

    public function testPublishesMercureWhenExpressionNotFound(): void
    {
        $this->eventRepository
            ->method('findByNaddr')
            ->willReturn(null);

        $this->hub
            ->expects($this->once())
            ->method('publish')
            ->with($this->isInstanceOf(Update::class));

        $handler = $this->createHandlerWithoutExpressionService();

        $message = new EvaluateExpressionMessage(
            kind: 30880,
            pubkey: str_repeat('aa', 32),
            identifier: 'test-dtag',
            userPubkey: str_repeat('bb', 32),
            cacheKey: 'feed_test123',
        );

        $handler($message);
    }

    public function testMercureFailureDoesNotThrow(): void
    {
        $this->eventRepository
            ->method('findByNaddr')
            ->willReturn(null);

        $this->hub
            ->method('publish')
            ->willThrowException(new \RuntimeException('Mercure down'));

        $handler = $this->createHandlerWithoutExpressionService();

        $message = new EvaluateExpressionMessage(
            kind: 30880,
            pubkey: str_repeat('aa', 32),
            identifier: 'test-dtag',
            userPubkey: str_repeat('bb', 32),
            cacheKey: 'feed_test123',
        );

        // Should not throw — handler catches Mercure errors gracefully
        $handler($message);
        $this->assertTrue(true);
    }

    public function testMessageDtoCarriesCorrectData(): void
    {
        $message = new EvaluateExpressionMessage(
            kind: 30880,
            pubkey: 'abc123',
            identifier: 'my-feed',
            userPubkey: 'def456',
            cacheKey: 'feed_xyz',
        );

        $this->assertSame(30880, $message->kind);
        $this->assertSame('abc123', $message->pubkey);
        $this->assertSame('my-feed', $message->identifier);
        $this->assertSame('def456', $message->userPubkey);
        $this->assertSame('feed_xyz', $message->cacheKey);
    }
}
