<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DefaultController;
use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrSigner;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Doctrine\ORM\EntityManagerInterface;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;

final class DefaultControllerExactPreviewTest extends TestCase
{
    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const AUTHOR = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const OTHER = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    /** @dataProvider exactPayloads */
    public function testBrowserAndLegacyPayloadsResolveOnlyTheExactLocalId(array $payload): void
    {
        $response = $this->preview($payload, $this->event());

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('event-preview__title', $response->getContent());
        self::assertStringContainsString(self::ID, $response->getContent());
    }

    public static function exactPayloads(): iterable
    {
        foreach (['note', 'nevent'] as $type) {
            $payload = self::browserPayload($type);
            yield $type . ' browser' => [$payload];
            // Supplied decoded identity/hints cannot redirect the raw reference.
            $payload['decoded']['id'] = self::OTHER;
            $payload['decoded']['kind'] = 30024;
            yield $type . ' forged decoded fields' => [$payload];
            $payload['decoded'] = json_encode($payload['decoded'], JSON_THROW_ON_ERROR);
            yield $type . ' JSON string' => [$payload];
        }
        yield 'legacy id' => [['identifier' => 'legacy', 'type' => 'note', 'decoded' => ['id' => self::ID]]];
        yield 'legacy numeric entry' => [['identifier' => 'legacy', 'type' => 'nevent', 'decoded' => [self::ID]]];
        yield 'legacy uppercase hex' => [['identifier' => 'legacy', 'type' => 'note', 'decoded' => ['id' => strtoupper(self::ID)]]];
    }

    /** @dataProvider invalidPayloads */
    public function testMalformedOrConflictingIdentityDoesNotQueryLocalData(array $payload): void
    {
        $response = $this->preview($payload, invalid: true);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('alert-warning', $response->getContent());
        self::assertStringNotContainsString('event-preview', $response->getContent());
    }

    public static function invalidPayloads(): iterable
    {
        $payload = self::browserPayload();
        $payload['decoded']['bech'] = substr($payload['identifier'], 0, -1) . '!';
        $payload['identifier'] = $payload['decoded']['bech'];
        yield 'bad checksum' => [$payload];
        $payload = self::browserPayload();
        $payload['type'] = 'nevent';
        $payload['decoded']['type'] = 'nevent';
        yield 'wrong Bech32 type' => [$payload];
        $payload = self::browserPayload();
        $payload['decoded']['type'] = 'naddr';
        yield 'conflicting inner type' => [$payload];
        $payload = self::browserPayload();
        $payload['identifier'] = (string) Bech32::note(self::OTHER);
        yield 'conflicting raw identifiers' => [$payload];
        foreach ([null, [], 42, 'not-bech32'] as $bech) {
            $payload = self::browserPayload();
            $payload['decoded']['bech'] = $bech;
            $payload['decoded']['id'] = self::ID;
            yield 'invalid raw never falls back to supplied id ' . get_debug_type($bech) => [$payload];
        }
        foreach ([self::OTHER . 'a', 'abc', str_repeat('z', 64), [], 42] as $id) {
            yield 'invalid legacy id ' . json_encode($id) => [['identifier' => 'legacy', 'type' => 'note', 'decoded' => ['id' => $id]]];
        }
        foreach ([
            (string) Bech32::naddr(kind: 30040, pubkey: self::AUTHOR, identifier: 'book'),
            (new NostrNip19Service())->encodeNpub(self::AUTHOR),
            (string) Bech32::note('abcd'),
        ] as $bech) {
            yield 'non-exact or short identifier ' . $bech => [[
                'identifier' => $bech, 'type' => 'note', 'decoded' => ['bech' => $bech, 'type' => 'note'],
            ]];
        }
    }

    /** @dataProvider embedKinds */
    public function testKindCoverageMatchesTheImmediateEmbed(int $kind, bool $readable): void
    {
        $response = $this->preview(self::browserPayload(), $this->event($kind));

        self::assertSame($readable, str_contains($response->getContent(), 'event-preview__title'));
        if (!$readable) {
            self::assertSame('<div class="alert alert-info">Event not found locally.</div>', $response->getContent());
        }
    }

    public static function embedKinds(): iterable
    {
        foreach ([1, 20, 21, 22, 30040, 30041, 30818] as $kind) {
            yield 'public ' . $kind => [$kind, true];
        }
        foreach ([3, 4, 1059, 10003, 30024, 30078, 39089, 65001] as $kind) {
            yield 'scoped or unknown ' . $kind => [$kind, false];
        }
    }

    public function testMissingOrWrongRevisionNeverFallsForwardToCoordinateOrProjection(): void
    {
        foreach ([null, $this->event(id: self::OTHER)] as $event) {
            $response = $this->preview(self::browserPayload('nevent'), $event);
            self::assertStringContainsString('Event not found locally.', $response->getContent());
        }
    }

    /** @dataProvider invalidLongformProjections */
    public function testLongformRequiresItsExactMatchingProjection(string $mismatch): void
    {
        $article = $this->article();
        $event = $this->event(30023);
        match ($mismatch) {
            'missing' => $article = null,
            'event id' => $article->setEventId(self::OTHER),
            'author' => $article->setPubkey(self::OTHER),
            'kind' => $article->setKind(KindsEnum::LONGFORM_DRAFT),
            'slug' => $article->setSlug('other'),
            'raw first d' => $article->setRaw(['tags' => [['d', 'other'], ['d', 'article']]]),
            'raw missing' => $article->setRaw(null),
            'raw malformed first d' => $article->setRaw(['tags' => [['d', 42], ['d', 'article']]]),
            'event malformed first d' => $event->setTags([['d', 42], ['d', 'article']]),
        };
        $response = $this->preview(self::browserPayload(), $event, $article);
        self::assertStringContainsString('Event not found locally.', $response->getContent());
        self::assertStringNotContainsString('event-preview', $response->getContent());
    }

    public static function invalidLongformProjections(): iterable
    {
        foreach (['missing', 'event id', 'author', 'kind', 'slug', 'raw first d', 'raw missing', 'raw malformed first d', 'event malformed first d'] as $case) {
            yield $case => [$case];
        }
    }

    public function testExclusiveLongformRequiresEntitlementEvenForDirectApiRequests(): void
    {
        foreach (['anonymous', 'user', 'member'] as $viewer) {
            $response = $this->preview(
                self::browserPayload('nevent'),
                $this->event(30023),
                $this->article()->setEssayistExclusive(true),
                viewer: $viewer,
            );
            self::assertSame($viewer === 'member', str_contains($response->getContent(), 'event-preview__title'));
        }
    }

    public function testAuthorizedLongformNeedsNoBodyConversionAndKeepsFirstD(): void
    {
        $article = $this->article();
        $article->setRaw(['tags' => [['d', 'article'], ['d', 'other']]]);
        $response = $this->preview(self::browserPayload(), $this->event(30023), $article);
        self::assertStringContainsString('secret-title', $response->getContent());
        self::assertStringContainsString(self::ID, $response->getContent());
    }

    /** @dataProvider warningSources */
    public function testWarningsReturnOnlyTranslatedWarningWithoutAnyIdentityOrMetadata(string $source, array $warning): void
    {
        $event = $this->event($source === 'event' ? 20 : 30023);
        $article = $source === 'event' ? null : $this->article();
        if ($source === 'article') {
            $article->setRaw(['tags' => [['d', 'article'], $warning], 'sensitive' => false]);
        } else {
            $event->setTags([...$event->getTags(), $warning]);
        }
        $response = $this->preview(self::browserPayload(), $event, $article);
        self::assertStringContainsString('event-preview__sensitive', $response->getContent());
        self::assertStringContainsString('event_workspace.preview.sensitive', $response->getContent());
        foreach (['secret', self::ID, self::AUTHOR, 'npub', '<a ', '<img', 'event-preview__id'] as $hidden) {
            self::assertStringNotContainsString($hidden, $response->getContent());
        }
    }

    public static function warningSources(): iterable
    {
        foreach (['event', 'longform event', 'article'] as $source) {
            foreach ([['content-warning', 'secret-reason'], ['l', 'secret-label', 'nsfw'], ['t', 'NSFW']] as $warning) {
                yield $source . ' ' . $warning[0] => [$source, $warning];
            }
        }
    }

    /** @dataProvider visibilityDenials */
    public function testVisibilityAndLookupFailuresNeverExposePreviews(string $denial): void
    {
        $response = $this->preview(self::browserPayload(), $this->event(30023), $this->article(), denial: $denial, viewer: 'user');
        self::assertSame(200, $response->getStatusCode());
        foreach (['secret', self::ID, self::AUTHOR, 'npub', '<a ', '<img', 'event-preview'] as $hidden) {
            self::assertStringNotContainsString($hidden, $response->getContent());
        }
    }

    public static function visibilityDenials(): iterable
    {
        foreach (['author', 'tombstone', 'hidden', 'mute', 'mute failure', 'event lookup failure', 'projection lookup failure'] as $denial) {
            yield $denial => [$denial];
        }
    }

    private static function browserPayload(string $type = 'note'): array
    {
        $bech = $type === 'note' ? (string) Bech32::note(self::ID) : (string) Bech32::nevent(
            id: self::ID, relays: ['wss://untrusted.invalid'], author: self::OTHER, kind: 30024,
        );
        return ['identifier' => $bech, 'type' => $type, 'decoded' => ['bech' => $bech, 'type' => $type]];
    }

    private function event(int $kind = 1, string $id = self::ID): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setTags([['d', 'article'], ['title', 'secret-title'], ['summary', 'secret-summary'], ['image', 'https://secret.invalid/image']]);
        $event->setContent('secret-body');
        return $event;
    }

    private function article(): Article
    {
        $article = (new Article())->setEventId(self::ID)->setKind(KindsEnum::LONGFORM)
            ->setPubkey(self::AUTHOR)->setSlug('article')->setCreatedAt(123);
        $article->setRaw(['tags' => [['d', 'article']]]);
        return $article;
    }

    private function preview(
        array $payload,
        ?Event $event = null,
        ?Article $article = null,
        bool $invalid = false,
        string $denial = '',
        string $viewer = 'anonymous',
    ): Response {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findByNaddr');
        if ($denial === 'event lookup failure') {
            $events->method('findOneBy')->willThrowException(new \RuntimeException('DB unavailable'));
        } else {
            $events->expects($invalid ? self::never() : self::once())->method('findOneBy')
                ->with(['id' => self::ID])->willReturn($event);
        }
        $articles = $this->createMock(ArticleRepository::class);
        $projectionExpected = !$invalid && $event?->getId() === self::ID && $event?->getKind() === 30023
            && !in_array($denial, ['author', 'tombstone', 'hidden', 'mute', 'mute failure', 'event lookup failure'], true)
            && ($event->getTags()[0][1] ?? null) === 'article';
        $projectionLookup = $articles->expects($projectionExpected ? self::once() : self::never())->method('findOneBy')
            ->with(['eventId' => self::ID]);
        if ($denial === 'projection lookup failure') {
            $projectionLookup->willThrowException(new \RuntimeException('Projection unavailable'));
        } else {
            $projectionLookup->willReturn($article);
        }
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($invalid ? self::never() : self::atLeastOnce())->method('getRepository')->willReturnMap([
            [Event::class, $events], [Article::class, $articles],
        ]);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($denial === 'author') {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn($denial === 'tombstone');
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('isHidden')->willReturn($denial === 'hidden');
        $mutes = $this->createMock(UserMuteListService::class);
        if ($denial === 'mute failure') {
            $mutes->method('getMutedPubkeysStrict')->willThrowException(new \RuntimeException('Mutes unavailable'));
        } else {
            $mutes->method('getMutedPubkeysStrict')->willReturn($denial === 'mute' ? [self::AUTHOR] : []);
        }
        $security = $this->createMock(Security::class);
        if ($viewer !== 'anonymous') {
            $user = $this->createMock(UserInterface::class);
            $user->method('getUserIdentifier')->willReturn(self::OTHER);
            $user->method('getRoles')->willReturn($viewer === 'member' ? ['ROLE_ESSAYIST_MEMBER'] : ['ROLE_USER']);
            $security->method('getUser')->willReturn($user);
        }
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(str_contains($denial, 'failure') ? self::once() : self::never())->method('error');
        $access = new EventPreviewAccessPolicy(
            $authors, $deletions, $hidden, new RecordIdentityService(), $security, $mutes,
            new NostrKeyService(), $logger, new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class), $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        $controller = new class extends DefaultController {
            protected function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                if ($view === 'event_workspace/_api_sensitive_preview.html.twig') {
                    TestCase::assertSame([], $parameters, 'Warning-only rendering must not receive Event, Article or presentation metadata.');
                }
                $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 3) . '/templates'), ['strict_variables' => true]);
                $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $parameters = []): string => strtr($key, $parameters)));
                return new Response($twig->render($view, $parameters));
            }
        };
        $requests = new RequestStack();
        $requests->push(new Request(content: json_encode($payload, JSON_THROW_ON_ERROR)));
        return $controller->nostrPreview(
            $requests, $em, $logger,
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $access,
            new AuthoredArticlePreview(new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service())),
            new NostrEventVerifier(new NostrSigner($this->createMock(SignatureServiceInterface::class))),
        );
    }
}
