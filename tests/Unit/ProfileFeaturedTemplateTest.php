<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class ProfileFeaturedTemplateTest extends TestCase
{
    public function testFeaturedSidebarUsesCurrentTargetsAndEscapesTitles(): void
    {
        $twig = $this->twig();
        $html = $twig->render('profile/_featured.html.twig', ['featuredRows' => [
            ['type' => 'magazine', 'item' => ['indexEventId' => 'mag-id', 'title' => '<script>secret</script>']],
            ['type' => 'reading_list', 'item' => ['indexEventId' => 'list-id', 'title' => 'Reading']],
            ['type' => 'follow_pack', 'item' => ['pubkey' => 'author', 'slug' => 'people:writing', 'title' => 'Writers']],
        ]]);
        self::assertStringContainsString('profile.featured_in', $html);
        self::assertStringContainsString('/bookshelf_index?eventId=mag-id', $html);
        self::assertStringContainsString('/bookshelf_index?eventId=list-id', $html);
        self::assertStringContainsString('/follow_pack_view?npub=npub-author&amp;dtag=people%3Awriting', $html);
        self::assertStringContainsString('&lt;script&gt;secret&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertSame(3, substr_count($html, 'data-turbo-frame="_top"'));
    }

    public function testMissingOrEmptyFeaturedDataHidesThePanel(): void
    {
        self::assertSame('', $this->twig()->render('profile/_featured.html.twig'));
        self::assertSame('', $this->twig()->render('profile/_featured.html.twig', ['featuredRows' => []]));
    }

    /** @dataProvider streamRequests */
    public function testStreamFragmentsUpdateTheAsideWithoutDuplicatingTheInitialPanel(bool $fragment): void
    {
        $request = Request::create('/p/author/stream?page=2');
        if ($fragment) {
            $request->headers->set('Turbo-Frame', 'profile-stream-content');
        }
        $html = $this->twig()->render('profile/_stream.html.twig', [
            'app' => ['request' => $request],
            'routePrefix' => 'author-',
            'useVanity' => false,
            'profileId' => 'author',
            'featuredRows' => [['type' => 'magazine', 'item' => ['indexEventId' => 'mag-id', 'title' => 'Featured']]],
        ]);
        self::assertStringContainsString('id="profile-stream-content"', $html);
        self::assertSame($fragment ? 1 : 0, substr_count($html, '<turbo-stream action="update" target="profile-featured-content">'));
        if ($fragment) {
            self::assertStringContainsString('<template>', $html);
            self::assertStringContainsString('Featured</a>', $html);
        }
    }

    public static function streamRequests(): iterable
    {
        yield 'initial full page' => [false];
        yield 'refresh or pagination fragment' => [true];
    }

    private function twig(): Environment
    {
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['content_stream/_items.html.twig' => '', 'content_stream/_pagination.html.twig' => '']),
            new FilesystemLoader(dirname(__DIR__, 2) . '/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key): string => $key));
        $twig->addFilter(new TwigFilter('hex_to_npub', static fn (string $key): string => 'npub-' . $key));
        $twig->addFunction(new TwigFunction('path', static fn (string $route, array $params): string => '/' . $route . '?' . http_build_query($params)));
        return $twig;
    }
}
