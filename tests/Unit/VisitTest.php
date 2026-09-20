<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\Visit;
use PHPUnit\Framework\TestCase;

final class VisitTest extends TestCase
{
    public function testTruncatesRequestValuesToTheirDatabaseColumnLengths(): void
    {
        $visit = new Visit(
            str_repeat('r', 256),
            str_repeat('s', 256),
            str_repeat('f', 2049),
            str_repeat('d', 256),
            str_repeat('u', 513),
        );

        self::assertSame(str_repeat('r', 255), $visit->getRoute());
        self::assertSame(str_repeat('s', 255), $visit->getSessionId());
        self::assertSame(str_repeat('f', 2048), $visit->getReferer());
        self::assertSame(str_repeat('d', 255), $visit->getSubdomain());
        self::assertSame(str_repeat('u', 512), $visit->getUserAgent());
    }

    public function testSettersTruncateValuesToTheirDatabaseColumnLengths(): void
    {
        $visit = new Visit('/');

        $visit
            ->setRoute(str_repeat('r', 256))
            ->setSessionId(str_repeat('s', 256))
            ->setReferer(str_repeat('f', 2049))
            ->setSubdomain(str_repeat('d', 256))
            ->setUserAgent(str_repeat('u', 513));

        self::assertSame(255, mb_strlen($visit->getRoute()));
        self::assertSame(255, mb_strlen((string) $visit->getSessionId()));
        self::assertSame(2048, mb_strlen((string) $visit->getReferer()));
        self::assertSame(255, mb_strlen((string) $visit->getSubdomain()));
        self::assertSame(512, mb_strlen((string) $visit->getUserAgent()));
    }
}
