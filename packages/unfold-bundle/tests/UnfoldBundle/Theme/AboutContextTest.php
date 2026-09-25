<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Theme;

use DecentNewsroom\UnfoldBundle\Config\SiteConfig;
use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use DecentNewsroom\UnfoldBundle\Contract\CommentProviderInterface;
use DecentNewsroom\UnfoldBundle\Contract\MarkdownConverterInterface;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadata;
use DecentNewsroom\UnfoldBundle\Contract\ProfileMetadataProviderInterface;
use DecentNewsroom\UnfoldBundle\Theme\ContextBuilder;
use DecentNewsroom\UnfoldBundle\Theme\HandlebarsRenderer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class AboutContextTest extends TestCase
{
    public function testPeopleSectionsDeduplicateIndependentlyAndRenderProfileFallbacks(): void
    {
        $first = str_repeat('a', 64);
        $second = str_repeat('b', 64);
        $third = str_repeat('c', 64);
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata());
        $profiles->expects(self::once())
            ->method('getMultipleMetadata')
            ->with([$first, $second, $third])
            ->willReturn([
                $first => new ProfileMetadata(displayName: 'First Person', picture: 'https://images.example/first.png'),
                $second => new ProfileMetadata(name: 'Second Person'),
            ]);

        $builder = $this->builder($profiles, $this->createMock(MarkdownConverterInterface::class));
        $site = new SiteConfig('30040:' . $first . ':root', 'Magazine', 'A magazine description.', null, [], $first, authorPubkeys: [$first, $first]);
        $category = new CategoryData('culture', 'Culture', '30040:' . $first . ':culture', authorPubkeys: [$second, $first]);
        $context = $builder->buildAboutContext($site, [$category], null, [$second, $third, $second]);

        self::assertSame([$first, $second], array_column($context['about']['magazine_people'], 'pubkey'));
        self::assertSame([$second, $third], array_column($context['about']['featured_writers'], 'pubkey'));
        self::assertSame('First Person', $context['about']['magazine_people'][0]['name']);
        self::assertSame('https://images.example/first.png', $context['about']['magazine_people'][0]['picture']);
        self::assertSame(substr($third, 0, 8) . '…', $context['about']['featured_writers'][1]['name']);
        self::assertSame('https://dn.example/p/' . $third, $context['about']['featured_writers'][1]['url']);
        self::assertSame('A magazine description.', $context['about']['intro_text']);

        $renderer = new HandlebarsRenderer(new NullLogger(), dirname(__DIR__, 3) . '/Resources/themes', sys_get_temp_dir() . '/unfold-about-test-' . uniqid('', true));
        $html = $renderer->render('about', $context);
        self::assertStringContainsString('A magazine description.', $html);
        self::assertStringContainsString('First Person', $html);
        self::assertStringContainsString('href="/about"', $html);
    }

    public function testArticleRevisionAtSameCoordinateGetsFreshRenderedHtml(): void
    {
        $owner = str_repeat('d', 64);
        $converter = $this->createMock(MarkdownConverterInterface::class);
        $converter->expects(self::exactly(2))
            ->method('convertToHTML')
            ->willReturnCallback(static fn(string $markdown): string => '<p>' . $markdown . '</p>');
        $profiles = $this->createMock(ProfileMetadataProviderInterface::class);
        $profiles->method('getMetadata')->willReturn(new ProfileMetadata());
        $builder = $this->builder($profiles, $converter);
        $site = new SiteConfig('30040:' . $owner . ':root', 'Magazine', '', null, [], $owner);
        $first = new PostData('about', 'About article', '', 'First revision', null, 1, $owner, '30023:' . $owner . ':about');
        $second = new PostData('about', 'About article', '', 'Second revision', null, 2, $owner, '30023:' . $owner . ':about');

        self::assertSame('<p>First revision</p>', $builder->buildAboutContext($site, [], $first, [])['about']['article_html']);
        self::assertSame('<p>Second revision</p>', $builder->buildAboutContext($site, [], $second, [])['about']['article_html']);
    }

    private function builder(ProfileMetadataProviderInterface $profiles, MarkdownConverterInterface $converter): ContextBuilder
    {
        $comments = $this->createMock(CommentProviderInterface::class);
        return new ContextBuilder($converter, new ArrayAdapter(), $profiles, $comments, platformBaseUrl: 'https://dn.example');
    }
}
