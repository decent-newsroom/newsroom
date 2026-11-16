<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class EventWorkspaceInventoryCacheTest extends TestCase
{
    /** @dataProvider inventoryTemplates */
    public function testInventoryHeadDisablesTurboSnapshotCaching(string $template): void
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader([
                'reading-nook-layout.html.twig' => '{% block stylesheets %}parent-styles{% endblock %}',
                'newsroom-layout.html.twig' => '{% block stylesheets %}parent-styles{% endblock %}',
            ]),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]));
        $twig->addFilter(new TwigFilter('trans', static fn ($value) => $value));
        $twig->addFilter(new TwigFilter('toNpub', static fn ($value) => $value));
        $twig->addFunction(new TwigFunction('path', static fn () => '/'));
        $twig->addFunction(new TwigFunction('ux_icon', static fn () => ''));

        $head = $twig->load($template)->renderBlock('stylesheets');
        self::assertStringContainsString('parent-styles', $head);
        self::assertStringContainsString('<meta name="turbo-cache-control" content="no-cache">', $head);
    }

    public static function inventoryTemplates(): iterable
    {
        yield ['reader/reading_nook/index.html.twig'];
        yield ['my_content/index.html.twig'];
    }
}
