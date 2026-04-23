<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dto;

use App\Dto\UserMetadata;
use PHPUnit\Framework\TestCase;

class UserMetadataTest extends TestCase
{
    public function testFromStdClassSplitsCommaSeparatedNip05String(): void
    {
        $raw = new \stdClass();
        $raw->nip05 = 'alice@example.com, bob@example.com';

        $metadata = UserMetadata::fromStdClass($raw);

        $this->assertSame(['alice@example.com', 'bob@example.com'], $metadata->nip05);
    }

    public function testFromStdClassNormalizesNip05ArrayValues(): void
    {
        $raw = new \stdClass();
        $raw->nip05 = ['alice@example.com, bob@example.com', 'bob@example.com', '  carol@example.com  '];

        $metadata = UserMetadata::fromStdClass($raw);

        $this->assertSame(['alice@example.com', 'bob@example.com', 'carol@example.com'], $metadata->nip05);
    }
}

