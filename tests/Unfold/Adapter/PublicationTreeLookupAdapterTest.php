<?php

declare(strict_types=1);

namespace App\Tests\Unfold\Adapter;

use App\Service\Graph\GraphLookupService;
use App\Unfold\PublicationTreeLookupAdapter;
use PHPUnit\Framework\TestCase;

final class PublicationTreeLookupAdapterTest extends TestCase
{
    public function testChildrenPreserveGraphOrderAndReturnDtos(): void
    {
        $graph = $this->createMock(GraphLookupService::class);
        $graph->expects(self::once())
            ->method('resolveChildren')
            ->with('30040:abcdef:main')
            ->willReturn([
                ['coord' => '30040:abcdef:first', 'current_event_id' => 'first'],
                ['coord' => '30040:abcdef:second', 'current_event_id' => 'second'],
            ]);
        $graph->expects(self::once())
            ->method('fetchEventRows')
            ->with(['first', 'second'])
            ->willReturn([
                'first' => [
                    'id' => 'first',
                    'pubkey' => 'abcdef',
                    'kind' => 30040,
                    'content' => '',
                    'tags' => json_encode([['d', 'first']]),
                    'created_at' => 1,
                    'sig' => 'sig1',
                ],
                'second' => [
                    'id' => 'second',
                    'pubkey' => 'abcdef',
                    'kind' => 30040,
                    'content' => '',
                    'tags' => json_encode([['d', 'second']]),
                    'created_at' => 2,
                    'sig' => 'sig2',
                ],
            ]);

        $events = (new PublicationTreeLookupAdapter($graph))->findChildren('30040:ABCDEF:main');

        self::assertSame(['first', 'second'], array_map(
            static fn($event): string => $event->id,
            $events,
        ));
    }
}
