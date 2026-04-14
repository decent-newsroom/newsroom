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
        // Unclosed block: fence line is removed, content left as-is
        // (not wrapped in $$ — any existing $$…$$ in the content is
        // handled later by extractMathPlaceholders).
        $input = "```latex\n\\frac{1}{2}\nSome more content";
        $result = $this->nostrNormalize($input);
        $this->assertStringContainsString('\\frac{1}{2}', $result);
        $this->assertStringContainsString('Some more content', $result);
        $this->assertStringNotContainsString('```', $result);
    }

    public function testUnclosedLatexBlockDoesNotEatSubsequentContent(): void
    {
        // This is the core bug fix: an unclosed ```latex block must NOT
        // consume everything to EOF.
        $input = "```latex\n\$\$\n\\frac{1}{2}\n\$\$\nAnd a matrix:\n\n\$\$x^2\$\$\nGreek letters: \$\\Psi\$\n\nMore prose here.";
        $result = $this->nostrNormalize($input);

        // The fence line should be removed
        $this->assertStringNotContainsString('```', $result);
        // Math blocks should survive
        $this->assertStringContainsString('$$', $result);
        $this->assertStringContainsString('\\frac{1}{2}', $result);
        // Prose between math blocks should survive
        $this->assertStringContainsString('And a matrix:', $result);
        $this->assertStringContainsString('Greek letters:', $result);
        $this->assertStringContainsString('More prose here.', $result);
    }

    public function testClosedBlockWithInternalDollarSignsStripsFence(): void
    {
        // A closed ```latex block whose content already has $$…$$ should
        // strip the fence and output content as-is (no double-wrapping).
        $input = "```latex\n\$\$\n\\frac{1}{2}\n\$\$\nAnd more:\n\$\$x^2\$\$\n```";
        $result = $this->nostrNormalize($input);
        $this->assertStringNotContainsString('```', $result);
        $this->assertStringContainsString('$$', $result);
        $this->assertStringContainsString('And more:', $result);
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

    // ===== Edge cases =====

    public function testEmptyDisplayMathNotExtracted(): void
    {
        // "Empty math: $$" — a lone $$ should not pair with a distant $$
        $input = "Empty math: \$\$\nJust delimiters: \$ \$\nThe price is \$10.50";
        $result = $this->extract($input);
        // The lone $$ should be left as-is (empty display math is skipped)
        $this->assertStringContainsString('$$', $result);
    }

    public function testCurrencyAmountsNotExtracted(): void
    {
        $input = "The price is \$10.50\nCurrency: \$19.99";
        $result = $this->extract($input);
        $this->assertEmpty($this->getPlaceholders());
        $this->assertStringContainsString('$10.50', $result);
        $this->assertStringContainsString('$19.99', $result);
    }

    public function testMultiBacktickInlineCodeProtected(): void
    {
        // Double-backtick inline code containing $$ should be protected
        $input = "Text ``\$\$amount\$\$`` more";
        $result = $this->extract($input);
        // The $$ inside double-backtick code should not be extracted
        $this->assertEmpty($this->getPlaceholders());
    }

    public function testUnclosedFencedCodeBlockProtected(): void
    {
        // An unclosed fenced code block should protect its content from
        // math extraction (mirrors CommonMark behavior: unclosed = to EOF)
        $input = "```python\nprint(\$x^2\$)\nmore code";
        $result = $this->extract($input);
        $this->assertEmpty($this->getPlaceholders());
    }

    public function testDisplayMathDoesNotSpanProse(): void
    {
        // A lone $$ followed by paragraphs of prose and a distant $$
        // should NOT be treated as one big display math block.
        $input = "Empty: \$\$\n\nSome prose paragraph.\n\nAnother paragraph.\n\nLast: \$\$x^2\$\$";
        $result = $this->extract($input);
        $placeholders = $this->getPlaceholders();

        // Only the properly delimited $$x^2$$ should be extracted
        $this->assertCount(1, $placeholders);
        $mathExpr = array_values($placeholders)[0];
        $this->assertSame('$$x^2$$', $mathExpr);
    }

    public function testDisplayMathMultilineWithLatex(): void
    {
        // A genuine multi-line display math block with LaTeX commands
        // should be extracted even if it spans multiple lines.
        $input = "\$\$\n\\begin{pmatrix}\n1 & 2 \\\\\\\\\n3 & 4\n\\end{pmatrix}\n\$\$";
        $result = $this->extract($input);
        $placeholders = $this->getPlaceholders();

        $this->assertCount(1, $placeholders);
        $mathExpr = array_values($placeholders)[0];
        $this->assertStringContainsString('\\begin{pmatrix}', $mathExpr);
    }

    // ===== Full test content (user-provided Nostr math article) =====

    public function testFullTestContentPipeline(): void
    {
        // Reconstruct the user's test content (key sections)
        $input = <<<'CONTENT'
# This is a test file for writing mathematical formulas in #NostrMarkup
This document demonstrates rendering formulas in TeX/LaTeX notation.
The general rule is:
- Inline math: `$...$`
- Display math: `$$...$$` (on its own line)
## TeX Examples
Inline equation: `$E=mc^2$`
Same equation in display mode: `$$E=mc^2$$`
Something more complex, inline: `$\sqrt{x^2+1}$` and `$\sqrt[3]{27}$`
Something complex, in display mode: `$$P \left( A=2 \,\middle|\, \dfrac{A^2}{B}>4 \right)$$`
Another example: `$$\prod_{i=1}^{n} x_i - 1$$`
Function example:
```latex
$$
f(x)=
\begin{cases}
\frac{1}{d_{ij}} & \quad \text{when } d_{ij} \leq 160 \\
0 & \quad \text{otherwise}
\end{cases}
$$
And a matrix:

$$
M =
\begin{bmatrix}
\frac{5}{6} & \frac{1}{6} & 0 \\[0.3em]
\frac{5}{6} & 0 & \frac{1}{6} \\[0.3em]
0 & \frac{5}{6} & \frac{1}{6}
\end{bmatrix}
$$
Greek letters are a snap: $\Psi$, $\psi$, $\Phi$, $\phi$.

Using the quadratic formula, the roots of $x^2-6x+4=0$ are:

$$x = \frac{6 \pm \sqrt{36-16}}{2}$$

$$x = \frac{6 \pm \sqrt{20}}{2}$$

$$x \approx 0.8 \text{ or } 5.2$$

Mixed Examples
LaTeX inline: $\frac{1}{2}$
LaTeX display: $$\sum_{i=1}^n x_i$$
Edge Cases
Empty math: $$
Just delimiters: $ $
Dollar signs in text: The price is $10.50
Currency: $19.99
Shell command: echo "Price: $100"
CSS with dollar signs: color: $primary-color
This document demonstrates that LaTeX is processed correctly with standard math delimiters.
CONTENT;

        // Phase 1: Nostr normalization
        $step1 = $this->nostrNormalize($input);

        // ```latex fence line should be removed
        $this->assertStringNotContainsString('```latex', $step1);

        // Backtick-wrapped math should be unwrapped
        $this->assertStringNotContainsString('`$E=mc^2$`', $step1);
        $this->assertStringContainsString('$E=mc^2$', $step1);

        // Prose between math blocks should survive
        $this->assertStringContainsString('And a matrix:', $step1);
        $this->assertStringContainsString('Greek letters are a snap:', $step1);
        $this->assertStringContainsString('Using the quadratic formula', $step1);

        // Edge case text should survive
        $this->assertStringContainsString('The price is $10.50', $step1);
        $this->assertStringContainsString('$19.99', $step1);
        $this->assertStringContainsString('echo "Price: $100"', $step1);

        // Phase 2: Extract math placeholders
        $step2 = $this->extract($step1);
        $placeholders = $this->getPlaceholders();

        // Should have extracted many math expressions
        $this->assertNotEmpty($placeholders);

        // Verify specific math expressions were captured
        $allMath = implode('|||', array_values($placeholders));
        $this->assertStringContainsString('E=mc^2', $allMath);
        $this->assertStringContainsString('\\frac', $allMath);
        $this->assertStringContainsString('\\sqrt', $allMath);
        $this->assertStringContainsString('\\begin{cases}', $allMath);
        $this->assertStringContainsString('\\begin{bmatrix}', $allMath);
        $this->assertStringContainsString('\\Psi', $allMath);

        // Currency should NOT be in the math placeholders
        $this->assertStringNotContainsString('10.50', $allMath);
        $this->assertStringNotContainsString('19.99', $allMath);

        // Prose text must survive in the extracted content (not eaten by math)
        $this->assertStringContainsString('And a matrix:', $step2);
        $this->assertStringContainsString('Greek letters are a snap:', $step2);
        $this->assertStringContainsString('The price is', $step2);
        $this->assertStringContainsString('This document demonstrates', $step2);
    }
}
