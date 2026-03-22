<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Graph;

use App\Entity\Event;
use App\Service\Graph\CurrentVersionResolver;
use App\Service\Graph\EventIngestionListener;
use App\Service\Graph\ParsedReferenceDto;
use App\Service\Graph\ReferenceParserService;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class EventIngestionListenerTest extends TestCase
{
    private Connection $connection;
    private ReferenceParserService $referenceParser;
    private CurrentVersionResolver $currentVersionResolver;
    private LoggerInterface $logger;
    private EventIngestionListener $listener;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->referenceParser = $this->createMock(ReferenceParserService::class);
        $this->currentVersionResolver = $this->createMock(CurrentVersionResolver::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->listener = new EventIngestionListener(
            $this->connection,
            $this->referenceParser,
            $this->currentVersionResolver,
            $this->logger,
        );
    }

    public function testProcessEventCallsParserAndResolver(): void
    {
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn('abc123');
        $event->method('getKind')->willReturn(30040);
        $event->method('getPubkey')->willReturn('deadbeef');
        $event->method('getDTag')->willReturn('my-magazine');
        $event->method('getCreatedAt')->willReturn(1700000000);
        $event->method('getTags')->willReturn([['a', '30023:deadbeef:article']]);

        $ref = new ParsedReferenceDto(
            sourceEventId: 'abc123',
            tagName: 'a',
            targetRefType: 'coordinate',
            targetKind: 30023,
            targetPubkey: 'deadbeef',
            targetDTag: 'article',
            targetCoord: '30023:deadbeef:article',
            relation: 'contains',
            marker: null,
            position: 0,
            isStructural: true,
            isResolvable: true,
        );

        $this->referenceParser->expects($this->once())
            ->method('parseReferences')
            ->with($event)
            ->willReturn([$ref]);

        $this->connection->expects($this->atLeastOnce())
            ->method('executeStatement');

        $this->currentVersionResolver->expects($this->once())
            ->method('updateIfCurrent')
            ->with('abc123', 30040, 'deadbeef', 'my-magazine', 1700000000);

        $this->listener->processEvent($event);
    }

    public function testProcessEventWithNoRefsSkipsReferenceInsert(): void
    {
        $event = $this->createMock(Event::class);
        $event->method('getId')->willReturn('abc123');
        $event->method('getKind')->willReturn(1); // regular event, no refs
        $event->method('getPubkey')->willReturn('deadbeef');
        $event->method('getDTag')->willReturn(null);
        $event->method('getCreatedAt')->willReturn(1700000000);
        $event->method('getTags')->willReturn([]);

        $this->referenceParser->expects($this->once())
            ->method('parseReferences')
            ->willReturn([]);

        // No executeStatement calls for reference insertion
        $this->connection->expects($this->never())
            ->method('executeStatement');

        $this->currentVersionResolver->expects($this->once())
            ->method('updateIfCurrent');

        $this->listener->processEvent($event);
    }

    public function testProcessRawEventExtractsDTag(): void
    {
        $raw = (object) [
            'id' => 'event123',
            'kind' => 30040,
            'pubkey' => 'aabbccdd',
            'created_at' => 1700000000,
            'tags' => [
                ['d', 'my-slug'],
                ['a', '30023:aabbccdd:article'],
            ],
        ];

        $ref = new ParsedReferenceDto(
            sourceEventId: 'event123',
            tagName: 'a',
            targetRefType: 'coordinate',
            targetKind: 30023,
            targetPubkey: 'aabbccdd',
            targetDTag: 'article',
            targetCoord: '30023:aabbccdd:article',
            relation: 'contains',
            marker: null,
            position: 0,
            isStructural: true,
            isResolvable: true,
        );

        $this->referenceParser->expects($this->once())
            ->method('parseFromTagsArray')
            ->with('event123', 30040, $raw->tags)
            ->willReturn([$ref]);

        $this->currentVersionResolver->expects($this->once())
            ->method('updateIfCurrent')
            ->with('event123', 30040, 'aabbccdd', 'my-slug', 1700000000);

        $this->listener->processRawEvent($raw);
    }

    public function testProcessRawEventWithEmptyIdIsSkipped(): void
    {
        $raw = (object) ['id' => '', 'kind' => 1, 'pubkey' => 'aabb', 'created_at' => 0, 'tags' => []];

        $this->referenceParser->expects($this->never())->method('parseFromTagsArray');
        $this->currentVersionResolver->expects($this->never())->method('updateIfCurrent');

        $this->listener->processRawEvent($raw);
    }

    public function testProcessRawEventDTagDefaultsToEmptyForParameterizedReplaceable(): void
    {
        $raw = (object) [
            'id' => 'event456',
            'kind' => 30023,
            'pubkey' => 'aabb',
            'created_at' => 1700000000,
            'tags' => [], // no d tag
        ];

        $this->referenceParser->expects($this->once())
            ->method('parseFromTagsArray')
            ->willReturn([]);

        $this->currentVersionResolver->expects($this->once())
            ->method('updateIfCurrent')
            ->with('event456', 30023, 'aabb', '', 1700000000);

        $this->listener->processRawEvent($raw);
    }
}

