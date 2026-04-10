<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the math rendering pipeline in Converter:
 *   - normalizeNostrMathMarkup()  (Phase 1: Nostr conventions)
 *   - extractMathPlaceholders()   (Phase 2: placeholder extraction)
 *   - restoreMathPlaceholders()   (Phase 4: placeholder restoration)
 *
 * Private methods are tested via Reflection on a constructor-less instance.
 */
class MathRenderingTest extends TestCase
{
    private object $converter;
    private \ReflectionMethod $normalizeNostrMath;
    private \ReflectionMethod $extractPlaceholders;
    private \ReflectionMethod $restorePlaceholders;
    private \ReflectionProperty $mathPlaceholdersProp;

    protected function setUp(): void
    {
        $ref = new \ReflectionClass(\App\Util\CommonMark\Converter::class);
        $this->converter = $ref->newInstanceWithoutConstructor();

        $this->normalizeNostrMath = $ref->getMethod('normalizeNostrMathMarkup');
        $this->normalizeNostrMath->setAccessible(true);

        $this->extractPlaceholders = $ref->getMethod('extractMathPlaceholders');
        $this->extractPlaceholders->setAccessible(true);

        $this->restorePlaceholders = $ref->getMethod('restoreMathPlaceholders');
        $this->restorePlaceholders->setAccessible(true);

        $this->mathPlaceholdersProp = $ref->getProperty('mathPlaceholders');
        $this->mathPlaceholdersProp->setAccessible(true);
    }

    private function nostrNormalize(string $input): string
    {
        return $this->normalizeNostrMath->invoke($this->converter, $input);
    }

    private function extract(string $input): string
    {
        // Reset placeholders before each extraction
        $this->mathPlaceholdersProp->setValue($this->converter, []);
        return $this->extractPlaceholders->invoke($this->converter, $input);
    }

    private function getPlaceholders(): array
    {
        return $this->mathPlaceholdersProp->getValue($this->converter);
    }

    private function restore(string $html): string
    {
        return $this->restorePlaceholders->invoke($this->converter, $html);
    }

    // ===== Phase 1: normalizeNostrMathMarkup =====

    public function testUnwrapBacktickInlineMathWithCaret(): void
    {
        $this->assertSame('$E=mc^2$', $this->nostrNormalize('`$E=mc^2$`'));
    }

    public function testUnwrapBacktickInlineMathWithLatexCommand(): void
    {
        $this->assertSame('$\\sqrt{x^2+1}$', $this->nostrNormalize('`$\\sqrt{x^2+1}$`'));
    }

    public function testUnwrapBacktickInlineMathWithEquals(): void
    {
        $this->assertSame('$E=mc^2$', $this->nostrNormalize('`$E=mc^2$`'));
    }

    public function testDoNotUnwrapShellVariable(): void
    {
        $this->assertSame('`$HOME`', $this->nostrNormalize('`$HOME`'));
    }

    public function testDoNotUnwrapCssVariable(): void
    {
        $this->assertSame('`$primary-color`', $this->nostrNormalize('`$primary-color`'));
    }

    public function testUnwrapBacktickDisplayMath(): void
    {
        $this->assertSame('$$E=mc^2$$', $this->nostrNormalize('`$$E=mc^2$$`'));
    }

    public function testLatexFencedCodeBlockClosed(): void
    {
        $input = "Before\n```latex\n\\frac{1}{2}\n```\nAfter";
        $result = $this->nostrNormalize($input);
        $this->assertStringContainsString('$$', $result);
        $this->assertStringContainsString('\\frac{1}{2}', $result);
        $this->assertStringNotContainsString('```', $result);
        $this->assertStringContainsString('Before', $result);
        $this->assertStringContainsString('After', $result);
    }

    public function testMathFencedCodeBlockClosed(): void
    {
        $input = "```math\nx^2+1\n```";
        $result = $this->nostrNormalize($input);
        $this->assertStringContainsString('$$', $result);
        $this->assertStringContainsString('x^2+1', $result);
    }

    public function testTexFencedCodeBlockClosed(): void
    {
        $input = "```tex\n\\alpha + \\beta\n```";
        $result = $this->nostrNormalize($input);
        $this->assertStringContainsString('$$', $result);
        $this->assertStringContainsString('\\alpha + \\beta', $result);
    }

    public function testLatexFencedCodeBlockUnclosed(): void
    {
        $input = "```latex\n\\frac{1}{2}\nSome more content";
        $result = $this->nostrNormalize($input);
        $this->assertStringContainsString('$$', $result);
        $this->assertStringContainsString('\\frac{1}{2}', $result);
        $this->assertStringNotContainsString('```', $result);
    }

    public function testLatexBlockAlreadyWrappedInDollarSigns(): void
    {
        $input = "```latex\n\$\$\\frac{1}{2}\$\$\n```";
        $result = $this->nostrNormalize($input);
        // Should not double-wrap in $$
        $this->assertStringNotContainsString('$$$$', $result);
        $this->assertStringContainsString('$$\\frac{1}{2}$$', $result);
    }

    // ===== Phase 2: extractMathPlaceholders =====

    public function testDisplayMathExtracted(): void
    {
        $result = $this->extract('Text $$\\frac{1}{2}$$ more');
        $placeholders = $this->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $this->assertStringContainsString('MATHPH', $result);
        $this->assertStringNotContainsString('$$', $result);

        $mathExpr = array_values($placeholders)[0];
        $this->assertSame('$$\\frac{1}{2}$$', $mathExpr);
    }

    public function testInlineMathExtracted(): void
    {
        $result = $this->extract('Text $E=mc^2$ more');
        $placeholders = $this->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $this->assertStringContainsString('MATHPH', $result);
        $this->assertStringNotContainsString('$', $result);

        $mathExpr = array_values($placeholders)[0];
        $this->assertSame('\\(E=mc^2\\)', $mathExpr);
    }

    public function testCurrencyNotExtracted(): void
    {
        $result = $this->extract('The price is $10.50 today');
        $this->assertSame('The price is $10.50 today', $result);
        $this->assertEmpty($this->getPlaceholders());
    }

    public function testLatexInlineDelimiterExtracted(): void
    {
        $result = $this->extract('Text \\(x^2\\) more');
        $placeholders = $this->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $mathExpr = array_values($placeholders)[0];
        $this->assertSame('\\(x^2\\)', $mathExpr);
    }

    public function testLatexDisplayDelimiterExtracted(): void
    {
        $result = $this->extract("Text\n\\[x^2\\]\nmore");
        $placeholders = $this->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $mathExpr = array_values($placeholders)[0];
        $this->assertSame('\\[x^2\\]', $mathExpr);
    }

    public function testCodeBlocksProtected(): void
    {
        $result = $this->extract("```\n\$x^2\$\n```");
        // Math inside fenced code block should not be extracted
        $this->assertEmpty($this->getPlaceholders());
    }

    public function testInlineCodeProtected(): void
    {
        $result = $this->extract('Use `$x^2$` for math');
        // Math inside inline code should not be extracted
        $this->assertEmpty($this->getPlaceholders());
    }

    public function testMatrixWithDoubleBackslash(): void
    {
        $input = '$$\\begin{pmatrix}1&2\\\\3&4\\end{pmatrix}$$';
        $result = $this->extract($input);
        $placeholders = $this->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $mathExpr = array_values($placeholders)[0];
        // The \\ must survive — it's a matrix row separator
        $this->assertStringContainsString('\\\\', $mathExpr);
    }

    public function testMultipleMathExpressions(): void
    {
        $input = 'Inline $x^2$ and display $$y^2$$ and more $z^2$.';
        $result = $this->extract($input);
        $placeholders = $this->getPlaceholders();

        // Should have 3 placeholders
        $this->assertCount(3, $placeholders);
        $this->assertStringNotContainsString('$', $result);
    }

    public function testNoMathContentUnchanged(): void
    {
        $input = 'This is a normal article about technology.';
        $this->assertSame($input, $this->extract($input));
        $this->assertEmpty($this->getPlaceholders());
    }

    // ===== Phase 4: restoreMathPlaceholders =====

    public function testRestorePlaceholders(): void
    {
        $this->mathPlaceholdersProp->setValue($this->converter, [
            'MATHPH0XEND' => '\\(E=mc^2\\)',
            'MATHPH1XEND' => '$$\\frac{1}{2}$$',
        ]);

        $html = '<p>Text MATHPH0XEND and display:</p><p>MATHPH1XEND</p>';
        $result = $this->restore($html);

        $this->assertSame('<p>Text \\(E=mc^2\\) and display:</p><p>$$\\frac{1}{2}$$</p>', $result);
    }

    public function testRestoreEmptyPlaceholders(): void
    {
        $this->mathPlaceholdersProp->setValue($this->converter, []);
        $html = '<p>No math here</p>';
        $this->assertSame($html, $this->restore($html));
    }

    // ===== Full pipeline (Phase 1 → Phase 2 → Phase 4) =====

    public function testFullPipelineBacktickInlineMath(): void
    {
        $input = 'Formula: `$E=mc^2$`';
        $step1 = $this->nostrNormalize($input);
        $this->assertSame('Formula: $E=mc^2$', $step1);

        $step2 = $this->extract($step1);
        $this->assertStringContainsString('MATHPH', $step2);

        $placeholders = $this->getPlaceholders();
        $mathExpr = array_values($placeholders)[0];
        $this->assertSame('\\(E=mc^2\\)', $mathExpr);

        // Simulate CommonMark wrapping in <p>
        $html = '<p>' . $step2 . '</p>';
        $result = $this->restore($html);
        $this->assertSame('<p>Formula: \\(E=mc^2\\)</p>', $result);
    }

    public function testFullPipelineLatexCodeBlock(): void
    {
        $input = "```latex\n\\frac{1}{2}\n```";
        $step1 = $this->nostrNormalize($input);
        $this->assertStringContainsString('$$', $step1);

        $step2 = $this->extract($step1);
        $this->assertStringContainsString('MATHPH', $step2);

        $placeholders = $this->getPlaceholders();
        $mathExpr = array_values($placeholders)[0];
        $this->assertStringContainsString('\\frac{1}{2}', $mathExpr);
    }

    public function testFullPipelineGreekLetters(): void
    {
        $input = 'Greek: $\\Psi$, $\\psi$, $\\Phi$, $\\phi$.';
        $step2 = $this->extract($input);
        $placeholders = $this->getPlaceholders();

        $this->assertCount(4, $placeholders);

        foreach ($placeholders as $token => $math) {
            $this->assertStringStartsWith('\\(', $math);
            $this->assertStringEndsWith('\\)', $math);
        }
    }
}

