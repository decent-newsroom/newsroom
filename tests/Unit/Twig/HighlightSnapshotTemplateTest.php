<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class HighlightSnapshotTemplateTest extends KernelTestCase
{
    public function testHighlightSurfacesDoNotRestoreViewerDependentTurboSnapshots(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        foreach ([
            'pages/highlights.html.twig' => [],
            'pages/article.html.twig' => [],
            'chapter/show.html.twig' => [],
            'magazine/chapter.html.twig' => [],
            'magazine/read.html.twig' => [],
            'magazine/wiki.html.twig' => [],
            'magazine/magazine-front-article.html.twig' => [],
            'event/index.html.twig' => ['event' => ['kind' => 9802]],
            'event/index.html.twig#reply' => ['event' => ['kind' => 1], 'hasHighlightOp' => true],
        ] as $template => $context) {
            $head = $twig->load(explode('#', $template)[0])->renderBlock('stylesheets', $context);
            self::assertStringContainsString('<meta name="turbo-cache-control" content="no-cache">', $head);
        }

        $ordinaryEvent = $twig->load('event/index.html.twig')->renderBlock('stylesheets', ['event' => ['kind' => 1]]);
        self::assertStringNotContainsString('turbo-cache-control', $ordinaryEvent);
    }
}
