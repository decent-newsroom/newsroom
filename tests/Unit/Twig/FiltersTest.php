<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\Filters;
use PHPUnit\Framework\TestCase;

class FiltersTest extends TestCase
{
    public function testMentionifyConvertsAtPrefixedNpub(): void
    {
        $filters = new Filters();
        $npub = 'npub1h6sntuq7ucvzhpsc4j42tmha2fclyt2u3v5zwsflaz22wgq9rsmqfl3tt5';

        $result = $filters->mentionify('@' . $npub);

        self::assertStringContainsString(sprintf('href="/p/%s"', $npub), $result);
        self::assertStringContainsString('>@npub1h6s…3tt5</a>', $result);
    }

    public function testMentionifyConvertsNostrPrefixedNpub(): void
    {
        $filters = new Filters();
        $npub = 'npub1de3ks5m7pxs9f6wedn3947un3vr7fkhar777y9jszma2x9gfl6ysha93ql';

        $result = $filters->mentionify('nostr:' . $npub);

        self::assertStringContainsString(sprintf('href="/p/%s"', $npub), $result);
        self::assertStringContainsString('>nostr:npub1de3…93ql</a>', $result);
    }

    public function testMentionifyConvertsMultipleNostrPrefixedMentionsInSentence(): void
    {
        $filters = new Filters();

        $input = 'nostr:npub1h6sntuq7ucvzhpsc4j42tmha2fclyt2u3v5zwsflaz22wgq9rsmqfl3tt5 nostr:npub1de3ks5m7pxs9f6wedn3947un3vr7fkhar777y9jszma2x9gfl6ysha93ql nostr:npub1s07s0h5mwcenfnyagme8shp9trnv964lulgvdmppgenuhtk9p4rsueuk63';
        $result = $filters->mentionify($input);

        self::assertSame(3, substr_count($result, 'class="mention-link"'));
        self::assertStringContainsString('href="/p/npub1h6sntuq7ucvzhpsc4j42tmha2fclyt2u3v5zwsflaz22wgq9rsmqfl3tt5"', $result);
        self::assertStringContainsString('href="/p/npub1de3ks5m7pxs9f6wedn3947un3vr7fkhar777y9jszma2x9gfl6ysha93ql"', $result);
        self::assertStringContainsString('href="/p/npub1s07s0h5mwcenfnyagme8shp9trnv964lulgvdmppgenuhtk9p4rsueuk63"', $result);
    }
}

