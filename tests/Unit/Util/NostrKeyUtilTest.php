<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util;

use App\Util\NostrKeyUtil;
use PHPUnit\Framework\TestCase;

class NostrKeyUtilTest extends TestCase
{
    public function testNpubToHexDecodesKnownNpub(): void
    {
        $npub = 'npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6';
        $hex = '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d';

        self::assertSame($hex, NostrKeyUtil::npubToHex($npub));
    }

    public function testHexToNpubEncodesKnownHex(): void
    {
        $npub = 'npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6';
        $hex = '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d';

        self::assertSame($npub, NostrKeyUtil::hexToNpub($hex));
    }

    public function testNpubToHexAcceptsNostrPrefixedNpubs(): void
    {
        $npub = 'nostr:npub180cvv07tjdrrgpa0j7j7tmnyl2yr6yr7l8j4s3evf6u64th6gkwsyjh6w6';
        $hex = '3bf0c63fcb93463407af97a5e5ee64fa883d107ef9e558472c4eb9aaaefa459d';

        self::assertSame($hex, NostrKeyUtil::npubToHex($npub));
    }
}


