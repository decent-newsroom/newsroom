<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Dto\EventWorkspace\EventBodyFormat;
use App\Dto\EventWorkspace\EventPresentation;
use App\Dto\EventWorkspace\EventReference;
use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Dto\EventWorkspace\EventReferenceType;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Twig\Components\Molecules\EventPreview;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\TwigComponent\Test\InteractsWithTwigComponents;
use Symfony\UX\TwigComponent\TwigComponentBundle;
use Twig\Environment;

final class EventPreviewTest extends KernelTestCase
{
    use InteractsWithTwigComponents;

    private const EVENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testRegisteredComponentRendersTagOnlyPresentation(): void
    {
        $presentation = $this->factory()->fromAuthorizedFields(
            self::EVENT_ID,
            30023,
            self::AUTHOR,
            123,
            [
                ['title', 'Title from tags'],
                ['summary', 'Summary from tags'],
                ['image', 'https://example.test/never-load.png'],
            ],
            '',
        );

        $component = $this->mountTwigComponent('Molecules:EventPreview', ['presentation' => $presentation]);
        self::assertInstanceOf(EventPreview::class, $component);
        self::assertSame($presentation, $component->presentation);

        $rendered = $this->renderTwigComponent('Molecules:EventPreview', ['presentation' => $presentation]);
        $crawler = $rendered->crawler();
        self::assertSame('Title from tags', $crawler->filter('.event-preview__title')->text());
        self::assertSame('Summary from tags', $crawler->filter('.event-preview__description')->text());
        self::assertSame('Kind 30023', $crawler->filter('.event-preview__kind')->text());
        self::assertSame(self::EVENT_ID, $crawler->filter('.event-preview__id')->text());
        self::assertSame($presentation->authorNpub, $crawler->filter('.event-preview__author')->text());
        foreach (['title', 'description', 'id', 'author'] as $field) {
            self::assertSame(1, $crawler->filter('.event-preview__' . $field . '.break-word')->count());
        }
        self::assertStringNotContainsString('https://example.test/never-load.png', $rendered->toString());
        self::assertSame(0, $crawler->filter('img, video, audio, picture, source, iframe')->count());
    }

    public function testApiEventPreviewAdapterKeepsEmbedWrapperAroundSharedPreview(): void
    {
        $presentation = $this->factory()->fromAuthorizedFields(
            self::EVENT_ID,
            65001,
            self::AUTHOR,
            123,
            [['title', 'Unknown-kind title'], ['summary', 'Unknown-kind summary']],
            'opaque body',
        );

        $twig = self::getContainer()->get('twig');
        self::assertInstanceOf(Environment::class, $twig);
        $html = $twig->render('event_workspace/_api_event_preview.html.twig', [
            'presentation' => $presentation,
        ]);
        $crawler = new Crawler($html);

        self::assertSame(1, $crawler->filter('.nostr-event-preview.p-3 .event-preview')->count());
        self::assertSame('Unknown-kind title', $crawler->filter('.event-preview__title')->text());
        self::assertSame('Unknown-kind summary', $crawler->filter('.event-preview__description')->text());
        self::assertSame('Kind 65001', $crawler->filter('.event-preview__kind')->text());
        self::assertStringNotContainsString('opaque body', $html);
    }

    /**
     * @param list<list<string>> $safeTags
     * @dataProvider zapReceiptFallbacks
     */
    public function testZapReceiptNeverRendersItsEmbeddedRequest(array $safeTags, ?string $expectedDescription): void
    {
        $request = '{"kind":9734,"tags":[["content-warning","zap-warning-secret"]],"content":"zap-request-secret"}';
        $presentation = $this->factory()->fromAuthorizedFields(
            self::EVENT_ID,
            9735,
            self::AUTHOR,
            123,
            array_merge([['title', 'Zap receipt'], ['description', $request]], $safeTags),
            'zap-receipt-body-secret',
        );
        $rendered = $this->renderTwigComponent('Molecules:EventPreview', ['presentation' => $presentation]);
        $html = $rendered->toString();
        $crawler = $rendered->crawler();

        self::assertStringNotContainsString($request, $html);
        self::assertStringNotContainsString(htmlspecialchars($request, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        self::assertStringNotContainsString('zap-request-secret', $html);
        self::assertStringNotContainsString('zap-warning-secret', $html);
        self::assertStringNotContainsString('zap-receipt-body-secret', $html);
        self::assertSame('Kind 9735', $crawler->filter('.event-preview__kind')->text());
        self::assertSame('Zap receipt', $crawler->filter('.event-preview__title')->text());
        self::assertSame(0, $crawler->filter('.event-preview__sensitive')->count());

        $description = $crawler->filter('.event-preview__description');
        if ($expectedDescription === null) {
            self::assertSame(0, $description->count());
        } else {
            self::assertSame(1, $description->count());
            self::assertSame($expectedDescription, $description->text());
        }
    }

    /** @return array<string, array{list<list<string>>, ?string}> */
    public static function zapReceiptFallbacks(): array
    {
        return [
            'summary takes precedence' => [[['alt', 'Safe alternate'], ['summary', 'Safe summary']], 'Safe summary'],
            'alt when summary is absent' => [[['alt', 'Safe alternate']], 'Safe alternate'],
            'literal zero alt' => [[['alt', '0']], '0'],
            'no human-readable fallback' => [[], null],
        ];
    }

    /** @dataProvider qualifiedNsfwNamespaces */
    public function testQualifiedNsfwLabelIsHiddenWhenProducedByFactory(string $namespace): void
    {
        $presentation = $this->factory()->fromAuthorizedFields(
            self::EVENT_ID,
            1,
            self::AUTHOR,
            123,
            [
                ['l', 'nudity', $namespace],
                ['title', 'qualified-secret-title'],
                ['summary', 'qualified-secret-description'],
            ],
            'qualified-secret-body',
        );
        $rendered = $this->renderTwigComponent('Molecules:EventPreview', ['presentation' => $presentation]);
        $crawler = $rendered->crawler();

        self::assertSame(0, $crawler->filter('.event-preview__title, .event-preview__description')->count());
        self::assertSame(1, $crawler->filter('.event-preview__sensitive')->count());
        self::assertSame('Sensitive content', $crawler->filter('.event-preview__sensitive')->text());
        self::assertSame('Kind 1', $crawler->filter('.event-preview__kind')->text());
        self::assertSame(self::EVENT_ID, $crawler->filter('.event-preview__id')->text());
        self::assertSame($presentation->authorNpub, $crawler->filter('.event-preview__author')->text());
        foreach (['qualified-secret-title', 'qualified-secret-description', 'qualified-secret-body'] as $secret) {
            self::assertStringNotContainsString($secret, $rendered->toString());
        }
    }

    /** @return array<string, array{string}> */
    public static function qualifiedNsfwNamespaces(): array
    {
        return [
            'lowercase namespace without L tag' => ['nsfw'],
            'mixed-case namespace without L tag' => ['NsFw'],
        ];
    }

    public function testNullTitleUsesLocalizedFallbackAndLiteralZeroIsPreserved(): void
    {
        $empty = $this->render($this->presentation([
            'kind' => 0,
            'title' => null,
            'description' => null,
        ]));
        self::assertSame('Untitled event', $empty->filter('.event-preview__title')->text());
        self::assertSame('Kind 0', $empty->filter('.event-preview__kind')->text());
        self::assertSame(0, $empty->filter('.event-preview__description')->count());

        $zero = $this->render($this->presentation([
            'title' => '0',
            'description' => '0',
            'eventId' => '0',
            'authorNpub' => '0',
        ]));
        self::assertSame('0', $zero->filter('.event-preview__title')->text());
        self::assertSame('0', $zero->filter('.event-preview__description')->text());
        self::assertSame('0', $zero->filter('.event-preview__id')->text());
        self::assertSame('0', $zero->filter('.event-preview__author')->text());

        $pubkeyZero = $this->render($this->presentation(['authorNpub' => null, 'authorPubkey' => '0']));
        self::assertSame('0', $pubkeyZero->filter('.event-preview__author')->text());
    }

    public function testUnknownKindAndEmptySourceRemainRenderable(): void
    {
        $presentation = $this->factory()->fromAuthorizedFields('', 65000, '', 0, [], '');
        $crawler = $this->render($presentation);

        self::assertSame('Untitled event', $crawler->filter('.event-preview__title')->text());
        self::assertSame('Kind 65000', $crawler->filter('.event-preview__kind')->text());
        self::assertSame('Unknown', $crawler->filter('.event-preview__id')->text());
        self::assertSame('Unknown', $crawler->filter('.event-preview__author')->text());
        self::assertSame(0, $crawler->filter('.event-preview__description')->count());
    }

    public function testUntrustedTitleDescriptionAndIdentitiesAreEscaped(): void
    {
        $values = [
            'title' => '<svg onload="alert(1)">title</svg>',
            'description' => '<script>alert("description")</script> & text',
            'eventId' => '<img src=x onerror="alert(2)">',
            'authorPubkey' => '<a href="javascript:alert(3)">author</a>',
            'authorNpub' => null,
        ];
        $rendered = $this->renderTwigComponent('Molecules:EventPreview', [
            'presentation' => $this->presentation($values),
        ]);
        $html = $rendered->toString();
        foreach (['title', 'description', 'eventId', 'authorPubkey'] as $name) {
            self::assertStringContainsString(htmlspecialchars($values[$name], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
            self::assertStringNotContainsString($values[$name], $html);
        }
        self::assertSame(0, $rendered->crawler()->filter('svg, script, img, a')->count());

        $npub = '<b>untrusted npub</b>';
        $html = $this->renderTwigComponent('Molecules:EventPreview', [
            'presentation' => $this->presentation(['authorNpub' => $npub]),
        ])->toString();
        self::assertStringContainsString(htmlspecialchars($npub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $html);
        self::assertStringNotContainsString($npub, $html);

        $sensitive = $this->renderTwigComponent('Molecules:EventPreview', [
            'presentation' => $this->presentation([
                'isSensitive' => true,
                'eventId' => $values['eventId'],
                'authorNpub' => $npub,
            ]),
        ]);
        self::assertStringContainsString(htmlspecialchars($values['eventId'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $sensitive->toString());
        self::assertStringContainsString(htmlspecialchars($npub, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $sensitive->toString());
        self::assertSame(0, $sensitive->crawler()->filter('img, b')->count());

        $fallback = $this->render($this->presentation(['authorNpub' => " \t", 'authorPubkey' => 'fallback-pubkey']));
        self::assertSame('fallback-pubkey', $fallback->filter('.event-preview__author')->text());
    }

    /** @dataProvider sensitiveOrWarningFlags */
    public function testSensitiveOrWarnedPresentationExposesOnlyLabelAndIdentity(bool $hasContentWarning, bool $isSensitive): void
    {
        $reference = new EventReference(
            sourceEventId: 'sensitive-id',
            position: 0,
            tagName: 'e',
            type: EventReferenceType::EventId,
            rawValue: 'secret-reference',
            target: 'secret-reference',
            targetKind: null,
            targetPubkey: null,
            targetIdentifier: null,
            role: EventReferenceRole::Reference,
            status: EventReferenceStatus::Unresolved,
            relayHint: null,
            versionEventId: null,
        );
        $presentation = $this->presentation([
            'eventId' => 'sensitive-id',
            'kind' => 65000,
            'authorNpub' => 'npub-sensitive',
            'title' => 'secret-title',
            'description' => 'secret-description',
            'descriptionOnReveal' => 'secret-reveal-description',
            'imageUrl' => 'https://example.test/secret-image',
            'hasContentWarning' => $hasContentWarning,
            'contentWarningReason' => 'secret-warning-reason',
            'isSensitive' => $isSensitive,
            'textContent' => 'secret-body',
            'references' => [$reference, $reference],
            'rawTags' => [['secret-tag', 'secret-tag-value']],
        ]);

        $rendered = $this->renderTwigComponent('Molecules:EventPreview', ['presentation' => $presentation]);
        $crawler = $rendered->crawler();
        self::assertSame(
            'Sensitive content Kind 65000 Event ID sensitive-id Author npub-sensitive',
            $crawler->filter('.event-preview')->text(),
        );
        self::assertSame(0, $crawler->filter('.event-preview__title, .event-preview__description')->count());
        self::assertSame(1, $crawler->filter('.event-preview__id.break-word')->count());
        self::assertSame(1, $crawler->filter('.event-preview__author.break-word')->count());
        self::assertSame(0, $crawler->filter('a, form, button, input, img, video, audio, iframe, [data-controller], [data-action]')->count());
        self::assertStringNotContainsString('2', $rendered->toString(), 'The two reference occurrences must not produce a count, even in an attribute.');
        foreach (['secret-title', 'secret-description', 'secret-reveal-description', 'secret-image', 'secret-warning-reason', 'secret-body', 'secret-reference', 'secret-tag'] as $secret) {
            self::assertStringNotContainsString($secret, $rendered->toString());
        }
    }

    /** @return array<string, array{bool, bool}> */
    public static function sensitiveOrWarningFlags(): array
    {
        return [
            'warning tag and sensitive flag' => [true, true],
            'other sensitive classification' => [false, true],
            'warning tag with inconsistent sensitive flag' => [true, false],
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     * @dataProvider contradictoryWarningEvidence
     */
    public function testContradictoryWarningEvidenceStillHidesSensitiveFields(array $evidence): void
    {
        $presentation = $this->presentation(array_replace([
            'eventId' => 'sensitive-id',
            'kind' => 65000,
            'authorNpub' => 'npub-sensitive',
            'title' => 'direct-secret-title',
            'description' => 'direct-secret-description',
            'descriptionOnReveal' => 'direct-secret-reveal',
            'imageUrl' => 'https://example.test/direct-secret-image',
            'hasContentWarning' => false,
            'contentWarningReason' => null,
            'isSensitive' => false,
            'textContent' => 'direct-secret-body',
            'rawTags' => [],
        ], $evidence));
        $rendered = $this->renderTwigComponent('Molecules:EventPreview', ['presentation' => $presentation]);
        $crawler = $rendered->crawler();

        self::assertSame(0, $crawler->filter('.event-preview__title, .event-preview__description')->count());
        self::assertSame(1, $crawler->filter('.event-preview__sensitive')->count());
        self::assertSame(
            'Sensitive content Kind 65000 Event ID sensitive-id Author npub-sensitive',
            $crawler->filter('.event-preview')->text(),
        );
        foreach (['direct-secret-title', 'direct-secret-description', 'direct-secret-reveal', 'direct-secret-image', 'direct-secret-body', 'warning-reason-secret', 'raw-warning-secret'] as $secret) {
            self::assertStringNotContainsString($secret, $rendered->toString());
        }
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function contradictoryWarningEvidence(): array
    {
        return [
            'warning reason only' => [['contentWarningReason' => 'warning-reason-secret']],
            'warning tag with reason' => [['rawTags' => [['content-warning', 'raw-warning-secret']]]],
            'warning tag with empty reason' => [['rawTags' => [['content-warning', '']]]],
            'warning tag with omitted reason' => [['rawTags' => [['content-warning']]]],
            'warning reason and tag' => [[
                'contentWarningReason' => 'warning-reason-secret',
                'rawTags' => [['content-warning', 'raw-warning-secret']],
            ]],
            'NSFW hashtag' => [['rawTags' => [['t', 'NSFW']]]],
            'NSFW label namespace' => [['rawTags' => [['L', 'nsfw']]]],
            'qualified NSFW label without L tag' => [['rawTags' => [['l', 'nudity', 'nsfw']]]],
            'mixed-case qualified NSFW label without L tag' => [['rawTags' => [['l', 'nudity', 'NsFw']]]],
            'content-warning label namespace' => [['rawTags' => [['L', 'content-warning']]]],
            'content-warning qualified label' => [['rawTags' => [['l', 'nudity', 'content-warning']]]],
        ];
    }

    public function testLabelsUseTheConfiguredLocale(): void
    {
        $translator = self::getContainer()->get('translator');
        self::assertInstanceOf(LocaleAwareInterface::class, $translator);
        self::assertInstanceOf(TranslatorBagInterface::class, $translator);
        self::assertInstanceOf(TranslatorInterface::class, $translator);

        $locales = self::getContainer()->getParameter('kernel.enabled_locales');
        self::assertIsArray($locales);
        self::assertNotEmpty($locales);
        foreach ($locales as $locale) {
            self::assertIsString($locale);
            self::assertNotSame('', $locale);
            $translator->setLocale($locale);
            $normal = $this->render($this->presentation([
                'kind' => 65000,
                'title' => null,
                'eventId' => " \t",
                'authorNpub' => null,
                'authorPubkey' => ' ',
            ]));
            $sensitive = $this->render($this->presentation([
                'kind' => 65000,
                'isSensitive' => true,
                'eventId' => '',
                'authorNpub' => '',
                'authorPubkey' => '',
            ]));

            foreach (['untitled', 'sensitive', 'kind', 'id', 'author'] as $label) {
                $key = 'event_workspace.preview.' . $label;
                self::assertTrue($translator->getCatalogue($locale)->defines($key, 'messages'), $locale . ': ' . $key);
                self::assertNotSame($key, $translator->trans($key, ['%kind%' => 65000]), $locale . ': ' . $key);
            }
            self::assertSame($translator->trans('event_workspace.preview.untitled'), $normal->filter('.event-preview__title')->text());
            self::assertSame($translator->trans('event_workspace.preview.sensitive'), $sensitive->filter('.event-preview__sensitive')->text());
            self::assertSame($translator->trans('event_workspace.preview.kind', ['%kind%' => 65000]), $normal->filter('.event-preview__kind')->text());
            self::assertSame($translator->trans('event_workspace.preview.id'), $normal->filter('dt')->eq(0)->text());
            self::assertSame($translator->trans('event_workspace.preview.author'), $normal->filter('dt')->eq(1)->text());
            self::assertTrue($translator->getCatalogue($locale)->defines('text.unknown', 'messages'), $locale . ': text.unknown');
            self::assertNotSame('text.unknown', $translator->trans('text.unknown'), $locale . ': text.unknown');
            self::assertSame($translator->trans('text.unknown'), $normal->filter('.event-preview__id')->text());
            self::assertSame($translator->trans('text.unknown'), $normal->filter('.event-preview__author')->text());
            self::assertSame($translator->trans('text.unknown'), $sensitive->filter('.event-preview__id')->text());
            self::assertSame($translator->trans('text.unknown'), $sensitive->filter('.event-preview__author')->text());
        }
    }

    /** @dataProvider parentElements */
    public function testNestedPreviewDoesNotCreateLinksFormsMediaOrControllers(string $parent, string $open, string $close): void
    {
        $presentation = $this->presentation([
            'title' => '<a href="/injected">untrusted title</a>',
            'description' => 'ordinary description',
            'imageUrl' => 'https://example.test/never-load.png',
            'textContent' => '<video src="https://example.test/never-load.mp4"></video>',
        ]);
        $template = self::getContainer()->get('twig')->createTemplate(
            $open . '{{ component("Molecules:EventPreview", {presentation: presentation}) }}' . $close,
        );
        $html = $template->render(['presentation' => $presentation]);
        $crawler = new Crawler($html);

        self::assertSame(1, $crawler->filter($parent . ' > .event-preview')->count());
        self::assertSame($parent === 'a' ? 1 : 0, preg_match_all('/<\s*a\b/i', $html));
        self::assertSame($parent === 'form' ? 1 : 0, preg_match_all('/<\s*form\b/i', $html));
        $preview = $crawler->filter('.event-preview');
        self::assertSame(0, $preview->filter('a, form, button, input, img, video, audio, picture, source, iframe, object, embed, script, [data-controller], [data-action]')->count());
        self::assertStringNotContainsString('https://example.test/never-load', $html);
        self::assertStringContainsString('&lt;a href=&quot;/injected&quot;&gt;untrusted title&lt;/a&gt;', $html);
    }

    /** @return array<string, array{string, string, string}> */
    public static function parentElements(): array
    {
        return [
            'anchor' => ['a', '<a href="/outer">', '</a>'],
            'form' => ['form', '<form action="/outer">', '</form>'],
        ];
    }

    /** @param array<string, mixed> $options */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return new EventPreviewTestKernel($options['environment'] ?? 'test', $options['debug'] ?? true);
    }

    private function factory(): EventPresentationFactory
    {
        return new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
    }

    /** @param array<string, mixed> $overrides */
    private function presentation(array $overrides = []): EventPresentation
    {
        return new EventPresentation(...array_replace([
            'eventId' => self::EVENT_ID,
            'kind' => 30023,
            'authorPubkey' => self::AUTHOR,
            'authorNpub' => 'npub-author',
            'coordinate' => null,
            'recordUid' => null,
            'createdAt' => 123,
            'publishedAt' => null,
            'title' => 'Title',
            'description' => 'Description',
            'descriptionOnReveal' => null,
            'imageUrl' => null,
            'hasContentWarning' => false,
            'contentWarningReason' => null,
            'isSensitive' => false,
            'bodyFormat' => EventBodyFormat::PlainText,
            'textContent' => null,
            'autoUpdate' => null,
            'references' => [],
            'rawTags' => [],
        ], $overrides));
    }

    private function render(EventPresentation $presentation): Crawler
    {
        return $this->renderTwigComponent('Molecules:EventPreview', ['presentation' => $presentation])->crawler();
    }
}

/**
 * Register the actual Twig component while loading only the Symfony bundles
 * needed to render it against the project's templates and translations.
 */
final class EventPreviewTestKernel extends Kernel
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
            'secret' => 'event-preview-test',
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
        $container->services()
            ->set(EventPreview::class)
            ->autowire()
            ->autoconfigure();
    }

    private function configureRoutes(RoutingConfigurator $routes): void
    {
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-event-preview-' . getmypid() . '/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        return sys_get_temp_dir() . '/newsroom-event-preview-' . getmypid() . '/log';
    }
}
