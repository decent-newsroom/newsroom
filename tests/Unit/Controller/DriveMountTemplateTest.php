<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class DriveMountTemplateTest extends TestCase
{
    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader([
                'reading-nook-layout.html.twig' => '<head>{% block stylesheets %}parent-style{% endblock %}</head><body>{% block body %}{% endblock %}</body>',
            ]),
            new FilesystemLoader(dirname(__DIR__, 3) . '/templates'),
        ]), ['strict_variables' => true, 'autoescape' => 'html']);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $parameters = []): string => $key));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $parameters = []): string => '/' . $route));

        return $twig;
    }

    public function testMountAndDirectoryUseTheSameNonCacheablePage(): void
    {
        foreach ([null, new \stdClass()] as $directory) {
            $html = $this->twig()->render('reader/reading_nook/drive.html.twig', [
                'mountTitle' => 'Drive',
                'description' => '',
                'directory' => $directory,
                'driveId' => str_repeat('a', 64),
                'mountPage' => ['entries' => [], 'isDrive' => $directory === null,
                    'hasPrevious' => false, 'hasNext' => false, 'page' => 1, 'occurrenceId' => null],
            ]);
            self::assertStringContainsString('parent-style', $html);
            self::assertStringContainsString('name="turbo-cache-control" content="no-cache"', $html);
        }
    }

    public function testMediaBodyAndMetadataAreEscapedAndNeverLoadRemoteMedia(): void
    {
        $factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        foreach ([20, 21, 22] as $kind) {
            $presentation = $factory->fromAuthorizedFields(
                str_repeat('a', 64), $kind, str_repeat('b', 64), 123,
                [['title', '<Media>'], ['image', 'https://remote.invalid/file']], 'ignored',
            );
            $html = $this->twig()->render('reader/reading_nook/drive_event.html.twig', [
                'driveId' => str_repeat('c', 64),
                'presentation' => $presentation,
                'mediaTextContent' => '<script>alert(1)</script>',
                'mediaMetadata' => ['url https://remote.invalid/file', 'alt <img src=x onerror=alert(1)>'],
            ]);
            self::assertStringContainsString('name="turbo-cache-control" content="no-cache"', $html);
            self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
            self::assertStringContainsString('alt &lt;img src=x onerror=alert(1)&gt;', $html);
            self::assertStringContainsString('url https://remote.invalid/file', $html);
            self::assertDoesNotMatchRegularExpression('/<(?:img|video|audio|source|script)\b/i', $html);
            self::assertStringNotContainsString('href="https://remote.invalid', $html);
            $preview = $this->twig()->render('components/Molecules/EventPreview.html.twig', ['presentation' => $presentation]);
            self::assertStringContainsString('&lt;Media&gt;', $preview);
            self::assertStringNotContainsString('https://remote.invalid/file', $preview);
            self::assertDoesNotMatchRegularExpression('/<(?:img|video|audio|source|script)\b/i', $preview);
        }
    }
}
