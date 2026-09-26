<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Admin;

use DecentNewsroom\UnfoldBundle\Admin\PublicationContext;
use DecentNewsroom\UnfoldBundle\Admin\PublicationMount;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettingsManager;
use DecentNewsroom\UnfoldBundle\Config\SiteConfigLoader;
use DecentNewsroom\UnfoldBundle\Contract\EventReadGatewayInterface;
use DecentNewsroom\UnfoldBundle\Contract\NostrEvent;
use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use DecentNewsroom\UnfoldBundle\Controller\Admin\AboutIndexController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

final class AboutIndexControllerTest extends TestCase
{
    private const OWNER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ROOT = '30040:' . self::OWNER . ':edition';
    private const FIRST = '30023:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:first';
    private const SECOND = '30023:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc:second';
    private const THIRD = '30023:dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd:third';

    /** @dataProvider mounts */
    public function testPrepareBuildsExactRootTagChangeOnBothMounts(PublicationMount $mount, string $prefix): void
    {
        $publisher = $this->createMock(SignedPublicationIndexPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $controller = $this->controller($publisher);
        $response = $controller->prepare($this->request(['about_article' => self::SECOND, '_token' => 'csrf']), $this->publication($mount, $prefix));
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(str_repeat('e', 64), $data['base_event_id']);
        self::assertSame(self::OWNER, $data['event']['pubkey']);
        self::assertSame(30040, $data['event']['kind']);
        self::assertSame('Unchanged root content', $data['event']['content']);
        self::assertSame([
            ['d', 'edition'],
            ['title', 'Magazine'],
            ['a', '30040:' . self::OWNER . ':culture'],
            ['a', self::SECOND],
        ], $data['event']['tags']);
    }

    public function testChangingSelectionAmongExistingRootArticlesStillPreparesASignedRevision(): void
    {
        $events = $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->willReturnCallback(static function (string $coordinate): ?NostrEvent {
            if ($coordinate === self::ROOT) {
                return new NostrEvent(
                    str_repeat('e', 64), self::OWNER, 30040, 'Unchanged root content',
                    [['d', 'edition'], ['a', self::FIRST], ['a', self::SECOND]],
                    123, str_repeat('a', 128),
                );
            }
            if ($coordinate === self::SECOND) {
                return new NostrEvent(
                    str_repeat('d', 64), str_repeat('c', 64), 30023, 'Article',
                    [['d', 'second'], ['title', 'About us']],
                    100, str_repeat('b', 128),
                );
            }
            return null;
        });
        $settings = $this->createMock(PublicationSettingsManager::class);
        $settings->method('get')->willReturn(new PublicationSettings(self::ROOT, aboutArticleCoordinate: self::THIRD));
        $response = $this->controller(events: $events, settings: $settings)->prepare(
            $this->request(['about_article' => self::SECOND, '_token' => 'csrf']),
            new PublicationContext(
                self::ROOT,
                new PublicationSettings(self::ROOT, aboutArticleCoordinate: self::THIRD),
                PublicationMount::COORDINATE,
                '/mag/edition/admin',
            ),
        );
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayHasKey('event', $data);
        self::assertSame([['d', 'edition'], ['a', self::FIRST], ['a', self::SECOND]], $data['event']['tags']);
    }
    public function testInvalidCsrfRejectsBeforeEventLookup(): void
    {
        $gateway = $this->createMock(EventReadGatewayInterface::class);
        $gateway->expects(self::never())->method('findByCoordinate');
        $response = $this->controller(events: $gateway)->prepare(
            $this->request(['about_article' => self::SECOND, '_token' => 'invalid']),
            $this->publication(PublicationMount::COORDINATE, '/mag/edition/admin'),
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testStaleRevisionRejectsBeforePublishing(): void
    {
        $publisher = $this->createMock(SignedPublicationIndexPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $response = $this->controller($publisher)->commit(
            $this->request([
                'about_article' => self::SECOND,
                'base_event_id' => str_repeat('f', 64),
                'event' => $this->signedEvent(),
                '_token' => 'csrf',
            ]),
            $this->publication(PublicationMount::COORDINATE, '/mag/edition/admin'),
        );

        self::assertSame(409, $response->getStatusCode());
    }

    public function testSignedEventCannotEditRootContentOrOtherTags(): void
    {
        $publisher = $this->createMock(SignedPublicationIndexPublisherInterface::class);
        $publisher->expects(self::never())->method('publish');
        $event = $this->signedEvent();
        $event['content'] = 'Changed root content';
        $response = $this->controller($publisher)->commit(
            $this->request([
                'about_article' => self::SECOND,
                'base_event_id' => str_repeat('e', 64),
                'event' => $event,
                '_token' => 'csrf',
            ]),
            $this->publication(PublicationMount::SUBDOMAIN, '/admin'),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testValidSignedEventReportsRelayFailureWithoutClaimingNetworkSuccess(): void
    {
        $publisher = $this->createMock(SignedPublicationIndexPublisherInterface::class);
        $publisher->expects(self::once())->method('publish')->with(
            self::callback(static fn (array $event): bool => $event['kind'] === 30040),
            self::ROOT,
            str_repeat('e', 64),
            self::SECOND,
            [],
            true,
        )->willReturn([
            'event_id' => str_repeat('f', 64),
            'published' => false,
            'relay_results' => ['wss://relay.example' => ['ok' => false]],
        ]);
        $loader = $this->createMock(SiteConfigLoader::class);
        $loader->expects(self::once())->method('invalidateFromCoordinate')->with(self::ROOT);
        $response = $this->controller($publisher, loader: $loader)->commit(
            $this->request([
                'about_article' => self::SECOND,
                'base_event_id' => str_repeat('e', 64),
                'event' => $this->signedEvent(),
                '_token' => 'csrf',
            ]),
            $this->publication(PublicationMount::COORDINATE, '/mag/edition/admin'),
        );
        $data = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($data['ok']);
        self::assertFalse($data['published']);
        self::assertTrue($data['retryable']);
    }

    /** @return iterable<string, array{PublicationMount, string}> */
    public static function mounts(): iterable
    {
        yield 'coordinate' => [PublicationMount::COORDINATE, '/mag/edition/admin'];
        yield 'subdomain' => [PublicationMount::SUBDOMAIN, '/admin'];
    }

    private function controller(
        ?SignedPublicationIndexPublisherInterface $publisher = null,
        ?EventReadGatewayInterface $events = null,
        ?SiteConfigLoader $loader = null,
        ?PublicationSettingsManager $settings = null,
    ): AboutIndexController {
        $events ??= $this->createMock(EventReadGatewayInterface::class);
        $events->method('findByCoordinate')->willReturnCallback(static function (string $coordinate): ?NostrEvent {
            if ($coordinate === self::ROOT) {
                return new NostrEvent(
                    str_repeat('e', 64), self::OWNER, 30040, 'Unchanged root content',
                    [
                        ['d', 'edition'],
                        ['title', 'Magazine'],
                        ['a', '30040:' . self::OWNER . ':culture'],
                        ['a', self::FIRST],
                    ],
                    123, str_repeat('a', 128),
                );
            }
            if ($coordinate === self::SECOND) {
                return new NostrEvent(
                    str_repeat('d', 64), str_repeat('c', 64), 30023, 'Article',
                    [['d', 'second'], ['title', 'About us']],
                    100, str_repeat('b', 128),
                );
            }
            return null;
        });
        if ($settings === null) {
            $settings = $this->createMock(PublicationSettingsManager::class);
            $settings->method('get')->with(self::ROOT)->willReturn(new PublicationSettings(self::ROOT));
        }
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturnCallback(static fn ($token): bool => $token->getValue() === 'csrf');

        return new AboutIndexController(
            $events,
            $publisher ?? $this->createMock(SignedPublicationIndexPublisherInterface::class),
            $settings,
            $loader ?? $this->createMock(SiteConfigLoader::class),
            $csrf,
            new NullLogger(),
        );
    }

    private function publication(PublicationMount $mount, string $prefix): PublicationContext
    {
        return new PublicationContext(self::ROOT, new PublicationSettings(self::ROOT), $mount, $prefix);
    }

    /** @param array<string, mixed> $data */
    private function request(array $data): Request
    {
        return Request::create('/admin/settings/about/prepare', 'POST', [], [], [], [], json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function signedEvent(): array
    {
        return [
            'id' => str_repeat('f', 64),
            'pubkey' => self::OWNER,
            'kind' => 30040,
            'created_at' => time(),
            'tags' => [
                ['d', 'edition'],
                ['title', 'Magazine'],
                ['a', '30040:' . self::OWNER . ':culture'],
                ['a', self::SECOND],
            ],
            'content' => 'Unchanged root content',
            'sig' => str_repeat('f', 128),
        ];
    }
}