<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use App\Entity\Event;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Source\PubkeyListSourceResolver;
use App\Repository\EventRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PubkeyListSourceResolverTest extends TestCase
{
    private EventRepository $eventRepository;
    private PubkeyListSourceResolver $resolver;

    protected function setUp(): void
    {
        $this->eventRepository = $this->createMock(EventRepository::class);
        $this->resolver = new PubkeyListSourceResolver($this->eventRepository, new NullLogger());
    }

    public function testResolveFollowPackReturnsUniquePubkeyItems(): void
    {
        $owner = str_repeat('aa', 32);
        $memberOne = str_repeat('11', 32);
        $memberTwo = str_repeat('22', 32);

        $followPack = $this->makeEvent('pack', 39089, $owner, [
            ['d', 'pack-a'],
            ['p', $memberOne],
            ['p', $memberTwo],
            ['p', $memberOne],
        ]);

        $this->eventRepository
            ->expects($this->once())
            ->method('findByNaddr')
            ->with(39089, $owner, 'pack-a')
            ->willReturn($followPack);

        $items = $this->resolver->resolve('39089:' . $owner . ':pack-a', $this->runtimeContext());

        $this->assertCount(2, $items);
        $this->assertSame([$memberOne, $memberTwo], array_map(fn($item) => $item->getPubkey(), $items));
    }

    public function testResolveKind3UsesLatestByPubkeyAndKind(): void
    {
        $owner = str_repeat('ab', 32);
        $member = str_repeat('cd', 32);
        $contacts = $this->makeEvent('contacts', 3, $owner, [['p', $member]]);

        $this->eventRepository
            ->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->with($owner, 3)
            ->willReturn($contacts);

        $this->eventRepository
            ->expects($this->never())
            ->method('findByNaddr');

        $items = $this->resolver->resolve('3:' . $owner . ':', $this->runtimeContext());

        $this->assertCount(1, $items);
        $this->assertSame($member, $items[0]->getPubkey());
    }

    private function runtimeContext(): RuntimeContext
    {
        return new RuntimeContext(
            mePubkey: str_repeat('ff', 32),
            contacts: [],
            interests: [],
            now: 1_700_000_000,
        );
    }

    private function makeEvent(string $id, int $kind, string $pubkey, array $tags): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($pubkey);
        $event->setCreatedAt(1_700_000_000);
        $event->setContent('');
        $event->setSig('');
        $event->setTags($tags);

        return $event;
    }
}

