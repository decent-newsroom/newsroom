<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Service\Nostr\RelayPublishResult;
use PHPUnit\Framework\TestCase;

final class RelayPublishResultTest extends TestCase
{
    public function testInterpretsTypedAndArrayResults(): void
    {
        self::assertTrue(RelayPublishResult::isSuccessful(new RelayPublishResult(true)));
        self::assertFalse(RelayPublishResult::isSuccessful(new RelayPublishResult(false)));
        self::assertTrue(RelayPublishResult::isSuccessful(['ok' => true]));
        self::assertFalse(RelayPublishResult::isSuccessful(['ok' => false]));
    }
}
