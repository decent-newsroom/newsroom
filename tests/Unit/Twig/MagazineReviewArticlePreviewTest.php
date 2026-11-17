<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig;

use App\Service\Nostr\NostrNip19Service;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

final class MagazineReviewArticlePreviewTest extends KernelTestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testLongformCoordinateUsesSharedAddressPreview(): void
    {
        $coordinate = '30023:' . self::AUTHOR . ':book:edition';
        $html = $this->render($coordinate);
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'book:edition', 30023);

        self::assertStringContainsString('nostr-preview-fixture:naddr:' . $reference, $html);
        self::assertStringNotContainsString('article-coordinate-fixture', $html);
    }

    public function testOtherCoordinateKindsKeepTheirSpecialistComponent(): void
    {
        $coordinate = '30041:' . self::AUTHOR . ':chapter';
        $html = $this->render($coordinate);

        self::assertStringContainsString('article-coordinate-fixture:' . $coordinate, $html);
        self::assertStringNotContainsString('nostr-preview-fixture', $html);
    }

    public function testMalformedLongformCoordinateFailsClosed(): void
    {
        $html = $this->render('30023:not-a-pubkey:book');

        self::assertStringContainsString('Target preview restricted', $html);
        self::assertStringNotContainsString('nostr-preview-fixture', $html);
        self::assertStringNotContainsString('article-coordinate-fixture', $html);
    }

    public function testReviewPageRoutesCategoryCoordinatesThroughPreviewPartial(): void
    {
        self::bootKernel();
        $template = file_get_contents(
            self::getContainer()->getParameter('kernel.project_dir') . '/templates/magazine/magazine_review.html.twig',
        );

        self::assertIsString($template);
        self::assertStringContainsString(
            "{% include 'magazine/_review_article_preview.html.twig' with {coordinate: coordinate} only %}",
            $template,
        );
    }

    private function render(string $coordinate): string
    {
        self::bootKernel();
        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);

        return $twig->render('magazine/_review_article_preview.html.twig', ['coordinate' => $coordinate]);
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        return new MagazineReviewArticlePreviewTestKernel(
            $options['environment'] ?? 'test',
            $options['debug'] ?? true,
        );
    }
}

#[AsTwigComponent(name: 'Molecules:NostrEmbed', template: 'components/Atoms/Content.html.twig')]
final class MagazineReviewEmbedFixture
{
    public string $parsed = '';
    public string $rawContent = '';
    public bool $renderMedia = true;

    public function mount(string $bech, string $type): void
    {
        $this->parsed = 'nostr-preview-fixture:' . $type . ':' . $bech;
        $this->rawContent = $this->parsed;
    }
}

#[AsTwigComponent(name: 'Organisms:ArticleFromCoordinate', template: 'components/Atoms/Content.html.twig')]
final class MagazineReviewCoordinateFixture
{
    public string $parsed = '';
    public string $rawContent = '';
    public bool $renderMedia = true;

    public function mount(string $coordinate): void
    {
        $this->parsed = 'article-coordinate-fixture:' . $coordinate;
        $this->rawContent = $this->parsed;
    }
}

final class MagazineReviewArticlePreviewTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new TwigComponentBundle();
    }

    private function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test' => true,
            'secret' => 'magazine-review-preview-test',
            'property_info' => ['with_constructor_extractor' => true],
        ]);
        $container->import($this->getProjectDir() . '/config/packages/translation.yaml');
        $container->extension('twig', [
            'default_path' => '%kernel.project_dir%/templates',
            'strict_variables' => true,
        ]);
        $container->extension('twig_component', [
            'anonymous_template_directory' => 'components/',
            'defaults' => ['App\\Twig\\Components\\' => 'components/'],
        ]);
        $services = $container->services();
        $services->set(\App\Twig\Filters::class)->autowire()->autoconfigure();
        $services->set(MagazineReviewEmbedFixture::class)->autowire()->autoconfigure();
        $services->set(MagazineReviewCoordinateFixture::class)->autowire()->autoconfigure();
        $services->set(MagazineReviewTemplateExtension::class)->autowire()->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-magazine-review-preview-' . getmypid() . '/' . $this->getEnvironment();
    }
}

final class MagazineReviewTemplateExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('resolve_nostr_embeds', static fn (string $content): string => $content, ['is_safe' => ['html']]),
        ];
    }
}
