<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use PHPUnit\Framework\TestCase;

class BlockquoteBoundaryTest extends TestCase
{
    private object $converter;
    private \ReflectionMethod $normalizeBlockquoteBoundaries;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(\App\Util\CommonMark\Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        $this->normalizeBlockquoteBoundaries = $ref->getMethod('normalizeBlockquoteBoundaries');
    }

    public function testSingleLineQuoteTerminatesBeforePlainText(): void
    {
        $input = "> And we know that God causes all things to work together for good **to those who love God, to those who are called according to His purpose**. (Romans 8:28) {emphasis mine}\nDoes all hardship work for good? No.";

        $result = $this->normalizeBlockquoteBoundaries->invoke($this->converter, $input);

        $this->assertStringContainsString("{emphasis mine}\n\nDoes all hardship", $result);
    }

    public function testQuotedContinuationWithExplicitMarkersIsPreserved(): void
    {
        $input = "> First line\n> Second line";

        $result = $this->normalizeBlockquoteBoundaries->invoke($this->converter, $input);

        $this->assertSame($input, $result);
    }
}



