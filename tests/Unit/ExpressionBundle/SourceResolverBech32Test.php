<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use App\ExpressionBundle\Source\SourceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests bech32 decoding in SourceResolver via reflection on the private method.
 */
class SourceResolverBech32Test extends TestCase
{
    private function callDecodeBech32Input(array $inputRef): array
    {
        $resolver = (new \ReflectionClass(SourceResolver::class))
            ->newInstanceWithoutConstructor();

        $loggerProp = (new \ReflectionClass(SourceResolver::class))->getProperty('logger');
        $loggerProp->setValue($resolver, new NullLogger());

        $method = (new \ReflectionClass(SourceResolver::class))->getMethod('decodeBech32Input');
        return $method->invoke($resolver, $inputRef);
    }

    public function testPlainEventIdPassedThrough(): void
    {
        $eventId = str_repeat('ab', 32);
        $result = $this->callDecodeBech32Input(['e', $eventId]);
        $this->assertSame(['e', $eventId], $result);
    }

    public function testPlainAddressPassedThrough(): void
    {
        $address = '30023:' . str_repeat('ab', 32) . ':my-slug';
        $result = $this->callDecodeBech32Input(['a', $address]);
        $this->assertSame(['a', $address], $result);
    }

    public function testInvalidNeventFallsThrough(): void
    {
        $result = $this->callDecodeBech32Input(['a', 'nevent1invalidbech32']);
        $this->assertSame(['a', 'nevent1invalidbech32'], $result);
    }

    public function testInvalidNaddrFallsThrough(): void
    {
        $result = $this->callDecodeBech32Input(['e', 'naddr1invalidbech32']);
        $this->assertSame(['e', 'naddr1invalidbech32'], $result);
    }

    public function testNonBech32RefUnchanged(): void
    {
        $result = $this->callDecodeBech32Input(['e', 'just-a-regular-string']);
        $this->assertSame(['e', 'just-a-regular-string'], $result);
    }

    public function testEmptyRefUnchanged(): void
    {
        $result = $this->callDecodeBech32Input(['a', '']);
        $this->assertSame(['a', ''], $result);
    }

    /**
     * Test with the real nevent1 from the bug report — verifies end-to-end decode.
     */
    public function testRealNeventDecodesToEventId(): void
    {
        $nevent = 'nevent1qvzqqqqrpypzp4r4ee9nja6swyc0gtrlsc6xa7fksq8n4e6dtm8cpzfgpnwpjglfqy0hwumn8ghj7un9d3shjtnyv43k2mn5dejhwumjdahk6tnrdakj7qg3waehxw309ahx7um5wghxcctwvshszxmhwden5te0w35x2en0wfjhxapwdehhxarjxyhxxmmd9uq32amnwvaz7tmjv4kxz7fwv3sk6atn9e5k7tcqypvrpu9shq0fvpt2vyzh50anna8utt57dtnxdpne3raf6f5u5m4hcey6g3p';

        $result = $this->callDecodeBech32Input(['a', $nevent]);

        $this->assertSame('e', $result[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result[1]);
    }

    /**
     * Test with nostr: prefix on a real nevent.
     */
    public function testNostrPrefixWithRealNevent(): void
    {
        $nevent = 'nostr:nevent1qvzqqqqrpypzp4r4ee9nja6swyc0gtrlsc6xa7fksq8n4e6dtm8cpzfgpnwpjglfqy0hwumn8ghj7un9d3shjtnyv43k2mn5dejhwumjdahk6tnrdakj7qg3waehxw309ahx7um5wghxcctwvshszxmhwden5te0w35x2en0wfjhxapwdehhxarjxyhxxmmd9uq32amnwvaz7tmjv4kxz7fwv3sk6atn9e5k7tcqypvrpu9shq0fvpt2vyzh50anna8utt57dtnxdpne3raf6f5u5m4hcey6g3p';

        $result = $this->callDecodeBech32Input(['a', $nevent]);

        $this->assertSame('e', $result[0]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result[1]);
    }
}

