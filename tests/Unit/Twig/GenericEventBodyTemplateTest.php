<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class GenericEventBodyTemplateTest extends TestCase
{
    public function testAltTagIsVisibleAndEscapedWithoutBodyHtml(): void
    {
        $html = $this->render([
            ['alt', 'A useful <description>'],
            ['summary', 'Secondary summary'],
        ]);

        self::assertStringContainsString('A useful &lt;description&gt;', $html);
        self::assertStringNotContainsString('Secondary summary', $html);
        self::assertStringNotContainsString('<description>', $html);
    }

    public function testSummaryAndContentWarningRemainVisibleWhenContentIsEmpty(): void
    {
        $html = $this->render([
            ['content-warning', 'Sensitive topic'],
            ['summary', 'A summary from signed tags'],
        ]);

        self::assertStringContainsString('Sensitive topic', $html);
        self::assertStringContainsString('A summary from signed tags', $html);
    }

    /** @param list<list<string>> $tags */
    private function render(array $tags): string
    {
        $twig = new Environment(
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
            ['autoescape' => 'html', 'strict_variables' => true],
        );
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));

        return $twig->render('event/_generic_event_body.html.twig', [
            'event' => (object) ['kind' => 9999, 'content' => '', 'tags' => $tags],
        ]);
    }
}
