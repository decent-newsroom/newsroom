<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Tests\Unit;

use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use DecentNewsroom\ExpressionBundle\Model\ArrayEvent;
use DecentNewsroom\ExpressionBundle\Model\RuntimeContext;
use DecentNewsroom\ExpressionBundle\Source\AddressSourceResolver;
use DecentNewsroom\ExpressionBundle\Source\ExpressionSourceResolver;
use DecentNewsroom\ExpressionBundle\Source\GenericEventResolver;
use DecentNewsroom\ExpressionBundle\Source\ListSourceResolver;
use DecentNewsroom\ExpressionBundle\Source\PubkeyListSourceResolver;
use DecentNewsroom\ExpressionBundle\Source\SpellSourceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class AddressSourceResolverTest extends TestCase
{
    public function testFollowPackAddressUsesPubkeyListResolver(): void
    {
        $eventRepository = $this->createMock(EventStoreInterface::class);
        $pubkeyListResolver = new PubkeyListSourceResolver($eventRepository, new NullLogger());

        $followPack = $this->makeEvent('pack', 39089, str_repeat('aa', 32), [
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
        $eventRepository = $this->createMock(EventStoreInterface::class);
        $pubkeyListResolver = new PubkeyListSourceResolver($eventRepository, new NullLogger());
        $contactsEvent = $this->makeEvent('contacts', 3, str_repeat('aa', 32), [
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

    private function makeEvent(string $id, int $kind, ?string $pubkey = null, array $tags = [['d', 'tag']]): ArrayEvent
    {
        return new ArrayEvent(
            id: $id,
            kind: $kind,
            pubkey: $pubkey ?? str_repeat('aa', 32),
            content: '',
            createdAt: 1_700_000_000,
            tags: $tags,
        );
    }
}
