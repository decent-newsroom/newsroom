<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\NostrExtension;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

class NostrExtensionTest extends TestCase
{
    private NostrExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new NostrExtension();
    }

    public function testRegistersNostrKeyFilters(): void
    {
        $filters = $this->extension->getFilters();

        $this->assertCount(2, $filters);
        $this->assertContainsOnlyInstancesOf(TwigFilter::class, $filters);
        $this->assertSame(['npub_to_hex', 'hex_to_npub'], array_map(
            static fn (TwigFilter $filter): string => $filter->getName(),
            $filters,
        ));
    }

    public function testNpubToHexReturnsEmptyStringForNull(): void
    {
        $this->assertSame('', $this->extension->npubToHex(null));
    }

    public function testNpubToHexReturnsEmptyStringForInvalidValue(): void
    {
        $this->assertSame('', $this->extension->npubToHex('not-an-npub'));
    }

    public function testHexToNpubReturnsEmptyStringForNull(): void
    {
        $this->assertSame('', $this->extension->hexToNpub(null));
    }

    public function testHexToNpubReturnsEmptyStringForInvalidValue(): void
    {
        $this->assertSame('', $this->extension->hexToNpub('not-hex'));
    }
}

