<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Twig\NostrEmbedExtension;
use App\Twig\NostrEmbedRuntime;
use PHPUnit\Framework\TestCase;
use Twig\TwigFilter;

class NostrEmbedExtensionTest extends TestCase
{
    private NostrEmbedExtension $extension;

    protected function setUp(): void
    {
        $this->extension = new NostrEmbedExtension();
    }

    public function testRegistersResolveNostrEmbedsFilter(): void
    {
        $filters = $this->extension->getFilters();

        $this->assertCount(1, $filters);
        $this->assertInstanceOf(TwigFilter::class, $filters[0]);
        $this->assertSame('resolve_nostr_embeds', $filters[0]->getName());
    }

    public function testFilterCallablePointsToRuntime(): void
    {
        $filter = $this->extension->getFilters()[0];
        $callable = $filter->getCallable();

        $this->assertIsArray($callable);
        $this->assertSame(NostrEmbedRuntime::class, $callable[0]);
        $this->assertSame('resolveEmbeds', $callable[1]);
    }

    public function testFilterIsMarkedHtmlSafe(): void
    {
        $filter = $this->extension->getFilters()[0];

        $this->assertContains('html', $filter->getSafe(new \Twig\Node\Expression\ConstantExpression('', 0)));
    }
}

