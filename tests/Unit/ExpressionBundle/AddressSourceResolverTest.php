<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use App\Entity\Event;
use App\ExpressionBundle\Model\RuntimeContext;
use App\ExpressionBundle\Source\AddressSourceResolver;
use App\ExpressionBundle\Source\ExpressionSourceResolver;
use App\ExpressionBundle\Source\GenericEventResolver;
use App\ExpressionBundle\Source\ListSourceResolver;
use App\ExpressionBundle\Source\PubkeyListSourceResolver;
use App\ExpressionBundle\Source\SpellSourceResolver;
use App\Repository\EventRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AddressSourceResolverTest extends TestCase
{
    public function testFollowPackAddressUsesPubkeyListResolver(): void
    {
        $eventRepository = $this->createMock(EventRepository::class);
        $pubkeyListResolver = new PubkeyListSourceResolver($eventRepository, new NullLogger());

        $followPack = $this->makeEvent('pack', 39089, str_repeat('aa', 32));
        $followPack->setTags([
            ['d', 'news-bots'],
            ['p', str_repeat('11', 32)],
        ]);

        $eventRepository
            ->expects($this->once())
            ->method('findByNaddr')
            ->with(39089, str_repeat('aa', 32), 'news-bots')
            ->willReturn($followPack);

        $resolver = new AddressSourceResolver(
            $this->instanceWithoutConstructor(ExpressionSourceResolver::class),
            $this->instanceWithoutConstructor(SpellSourceResolver::class),
            $this->instanceWithoutConstructor(ListSourceResolver::class),
            $pubkeyListResolver,
            $this->instanceWithoutConstructor(GenericEventResolver::class),
            new NullLogger(),
        );

        $items = $resolver->resolve('39089:' . str_repeat('aa', 32) . ':news-bots', $this->runtimeContext());

        $this->assertCount(1, $items);
        $this->assertSame(str_repeat('11', 32), $items[0]->getPubkey());
    }

    public function testContactsEventUsesPubkeyListResolverExecuteEvent(): void
    {
        $eventRepository = $this->createMock(EventRepository::class);
        $pubkeyListResolver = new PubkeyListSourceResolver($eventRepository, new NullLogger());
        $contactsEvent = $this->makeEvent('contacts', 3, str_repeat('aa', 32));
        $contactsEvent->setTags([
            ['p', str_repeat('22', 32)],
        ]);

        $resolver = new AddressSourceResolver(
            $this->instanceWithoutConstructor(ExpressionSourceResolver::class),
            $this->instanceWithoutConstructor(SpellSourceResolver::class),
            $this->instanceWithoutConstructor(ListSourceResolver::class),
            $pubkeyListResolver,
            $this->instanceWithoutConstructor(GenericEventResolver::class),
            new NullLogger(),
        );

        $items = $resolver->resolveEvent($contactsEvent, $this->runtimeContext());

        $this->assertCount(1, $items);
        $this->assertSame(str_repeat('22', 32), $items[0]->getPubkey());
    }

    private function instanceWithoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
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

    private function makeEvent(string $id, int $kind, ?string $pubkey = null): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey($pubkey ?? str_repeat('aa', 32));
        $event->setCreatedAt(1_700_000_000);
        $event->setContent('');
        $event->setSig('');
        $event->setTags([['d', 'tag']]);

        return $event;
    }
}



