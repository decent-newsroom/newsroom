<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use PHPUnit\Framework\TestCase;
use Symfony\Bridge\Twig\Extension\TranslationExtension;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Yaml\Yaml;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class BookshelfLandingTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $files = new FilesystemLoader($root . '/templates');
        $files->addPath($root . '/templates/bundles/BookshelfBundle', 'Bookshelf');
        $this->twig = new Environment(new ChainLoader([
            new ArrayLoader([
                '@Bookshelf/bookshelf-layout.html.twig' => '<html><head><title>{% block title %}{% endblock %}</title>{% block ogtags %}{% endblock %}</head><body>{% block body %}{% endblock %}</body></html>',
            ]), $files,
        ]), ['strict_variables' => true]);
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $translator->addResource('yaml', $root . '/vendor/decent-newsroom/bookshelf-bundle/src/Resources/translations/messages.en.yaml', 'en');
        $translator->addResource('yaml', $root . '/translations/messages.en.yaml', 'en');
        $this->twig->addExtension(new TranslationExtension($translator));
        $path = static function (string $route, array $parameters = []): string {
            $base = match ($route) {
                'bookshelf' => '/bookshelf',
                'bookshelf_read' => '/bookshelf/' . $parameters['id'],
                'bookshelf_featured' => '/bookshelf/featured',
                'bookshelf_directory_publish' => '/api/bookshelf/directory',
                default => throw new \LogicException('Unexpected route: ' . $route),
            };
            unset($parameters['id']);

            return $base . ($parameters === [] ? '' : '?' . http_build_query($parameters));
        };
        $this->twig->addFunction(new TwigFunction('path', $path));
        $this->twig->addFunction(new TwigFunction('url', static fn (string $route): string => 'https://example.test' . $path($route)));
        $this->twig->addFunction(new TwigFunction('asset', static fn (string $path): string => '/assets/' . $path));
        $this->twig->addFunction(new TwigFunction('ux_icon', static fn (): string => ''));
        $this->twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf'));
    }

    public function testPromoLeadsWithOneHeadingAndBothDownloadDestinationsWithoutGlobalList(): void
    {
        $html = $this->render();
        $crawler = new Crawler($html);
        self::assertCount(1, $crawler->filter('h1'));
        self::assertSame('Bookshelf for Android', $crawler->filter('h1')->text());
        self::assertSame('no-cache', $crawler->filter('meta[name="turbo-cache-control"]')->attr('content'));
        self::assertCount(1, $crawler->filter('a[href="https://zapstore.dev/apps/eu.decentnewsroom.bookshelf"]'));
        self::assertCount(1, $crawler->filter('a[href="https://github.com/decent-newsroom/bookshelf-app/releases"]'));
        self::assertCount(0, $crawler->filter('.bookshelf-featured, .bookshelf-stash'));
        self::assertStringNotContainsString('Organisms:BookList', $html);
        self::assertLessThan(strpos($html, 'bookshelf-web-heading'), strpos($html, 'bookshelf-app-title'));
        self::assertSame('/bookshelf#bookshelf-web-heading', $crawler->filter('form')->attr('action'));
        $metadata = json_decode($crawler->filter('script[type="application/ld+json"]')->text(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('MobileApplication', $metadata['@type']);
        self::assertSame('Android', $metadata['operatingSystem']);
    }

    public function testCuratedPreviewIsBetweenPromoAndSearchAndLinksUseExactIds(): void
    {
        $book = (new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()))->fromAuthorizedFields(
            str_repeat('a', 64), 30040, str_repeat('b', 64), 100,
            [['d', 'book'], ['title', '<script>Book</script>'], ['image', 'javascript:alert(1)']], '',
        );
        $html = $this->render(['featured' => $this->selection([
            ['status' => 'available', 'book' => $book], ['status' => 'missing', 'book' => null],
        ])]);
        $crawler = new Crawler($html);
        self::assertCount(2, $crawler->filter('.bookshelf-featured__entry'));
        self::assertSame('<script>Book</script>', $crawler->filter('.bookshelf-featured__entry h3')->text());
        self::assertSame('/bookshelf/' . str_repeat('a', 64), $crawler->filter('.bookshelf-featured__entry a')->attr('href'));
        self::assertCount(0, $crawler->filter('.bookshelf-featured__entry img, .bookshelf-featured__entry script'));
        self::assertStringContainsString('not currently available', $crawler->filter('.bookshelf-featured__entry')->last()->text());
        self::assertLessThan(strpos($html, 'bookshelf-featured-heading'), strpos($html, 'bookshelf-app-title'));
        self::assertLessThan(strpos($html, 'bookshelf-web-heading'), strpos($html, 'bookshelf-featured-heading'));
        self::assertSame('/bookshelf/featured?eventId=' . str_repeat('c', 64), $crawler->selectLink('Explore the full selection')->attr('href'));
    }

    public function testSearchResultsKeepAuthenticatedSaveControlsAndQueryLinks(): void
    {
        $coordinate = '30040:' . str_repeat('b', 64) . ':book';
        $html = $this->render([
            'app' => ['user' => true], 'query' => 'book', 'searched' => true,
            'directoryCoordinates' => [$coordinate],
            'books' => [[
                'id' => str_repeat('a', 64), 'coordinate' => $coordinate,
                'coverImage' => null, 'title' => 'Found book', 'type' => 'book',
                'authors' => ['A. Writer'], 'summary' => 'A story', 'chapterCount' => 3,
                'language' => 'en', 'releaseDate' => null, 'version' => null, 'topics' => ['fiction'],
            ]],
        ]);
        $crawler = new Crawler($html);
        self::assertSame('ui--bookshelf-directory', $crawler->filter('.bookshelf-web')->attr('data-controller'));
        self::assertSame('true', $crawler->filter('.bookshelf-directory-action')->attr('aria-pressed'));
        self::assertSame($coordinate, $crawler->filter('.bookshelf-directory-action')->attr('data-ui--bookshelf-directory-coordinate-param'));
        self::assertSame('/bookshelf/' . str_repeat('a', 64) . '?q=book', $crawler->filter('.bookshelf-book h3 a')->attr('href'));
        self::assertSame('book', $crawler->filter('input[name="q"]')->attr('value'));
    }

    public function testUnavailableCurationDoesNotHideDownloadsOrWorkingSearch(): void
    {
        $selection = $this->selection([]);
        $selection['state'] = 'failed';
        $crawler = new Crawler($this->render(['featured' => $selection, 'searched' => true, 'query' => 'book']));
        self::assertCount(1, $crawler->filter('.bookshelf-featured [role="status"]'));
        self::assertCount(1, $crawler->filter('.bookshelf-app__badge'));
        self::assertCount(1, $crawler->filter('.bookshelf-web form'));
        self::assertCount(1, $crawler->filter('.bookshelf-web .bookshelf-empty'));
    }

    public function testFullDirectoryPaginationKeepsExactSourceRevision(): void
    {
        $selection = $this->selection([]);
        $selection['page'] = 2;
        $selection['hasPrevious'] = true;
        $selection['hasNext'] = true;
        $crawler = new Crawler($this->twig->render('bookshelf/featured.html.twig', ['featured' => $selection]));
        self::assertSame('no-cache', $crawler->filter('meta[name="turbo-cache-control"]')->attr('content'));
        self::assertSame('/bookshelf/featured?eventId=' . str_repeat('c', 64) . '&page=1', $crawler->filter('a[rel="prev"]')->attr('href'));
        self::assertSame('/bookshelf/featured?eventId=' . str_repeat('c', 64) . '&page=3', $crawler->filter('a[rel="next"]')->attr('href'));
    }

    public function testPromotionalCopyExistsInAllEnabledLocales(): void
    {
        $root = dirname(__DIR__, 3);
        $english = Yaml::parseFile($root . '/translations/messages.en.yaml')['bookshelf']['promo'];
        foreach (['en', 'de', 'es', 'fr', 'it', 'sl'] as $locale) {
            $copy = Yaml::parseFile($root . '/translations/messages.' . $locale . '.yaml')['bookshelf']['promo'];
            self::assertSame(array_keys($english), array_keys($copy), $locale);
            foreach ($copy as $text) {
                self::assertIsString($text);
                self::assertNotSame('', trim($text));
            }
        }
    }

    private function selection(array $entries): array
    {
        return ['state' => 'available', 'eventId' => str_repeat('c', 64), 'entries' => $entries, 'page' => 1, 'hasPrevious' => false, 'hasNext' => false];
    }

    private function render(array $overrides = []): string
    {
        return $this->twig->render('@Bookshelf/pages/bookshelf.html.twig', $overrides + [
            'app' => ['user' => null], 'query' => '', 'searched' => false, 'available' => true, 'queryTooShort' => false,
            'books' => [], 'directoryTags' => [], 'directoryCoordinates' => [], 'directoryIdentifier' => 'my-book-collection',
            'featured' => ['state' => 'unconfigured'],
        ]);
    }
}
