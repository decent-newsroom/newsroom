<?php

declare(strict_types=1);

namespace App\Tests\Unit\Api\Books;

use App\Api\Books\Controller\EventController;
use App\Api\Books\Dto\Nip01Filter;
use PHPUnit\Framework\TestCase;

final class EventControllerQueryInputTest extends TestCase
{
    public function testItAcceptsCommaSeparatedKindsWithoutSplittingTagValues(): void
    {
        $controller = (new \ReflectionClass(EventController::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(EventController::class, 'queryInput');

        /** @var array<string, mixed> $input */
        $input = $method->invoke($controller, [
            'limit' => '5',
            'kinds' => '30040,30041',
            '#T' => 'The Republic, Volume I',
        ]);
        $filter = Nip01Filter::fromArray($input);

        self::assertSame([30040, 30041], $filter->kinds);
        self::assertSame(['The Republic, Volume I'], $filter->tags['#T']);
    }
}
