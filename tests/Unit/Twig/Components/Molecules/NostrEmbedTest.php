<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

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
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrSigner;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use App\Twig\Components\Molecules\NostrEmbed;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Innis\Nostr\Core\Domain\Entity\Event as NostrCoreEvent;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;

final class NostrEmbedTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    /** @dataProvider exactReferences */
    public function testExactIdSharedPreviewRetainsIdentityAndOccurrences(string $type, string $reference): void
    {
        $event = $this->event(30040, [
            ['d', 'book'], ['title', 'Book'], ['a', '30041:' . self::AUTHOR . ':chapter'],
            ['a', '30041:' . self::AUTHOR . ':chapter'],
        ]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)->willReturn($event);
        $events->expects(self::never())->method('findByNaddr');
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::never())->method('findOneBy');
        $component = $this->component($events, $articles);
        $component->mount($reference, $type);

        self::assertSame(self::ID, $component->presentation->eventId);
        self::assertSame($event->getTags(), $component->presentation->getRawTags());
        self::assertCount(2, $component->presentation->references);
        self::assertSame('/e/' . $reference, $component->href);
        $html = $this->render($component);
        self::assertStringContainsString('event-preview__title', $html);
        self::assertStringContainsString('Book', $html);
        self::assertStringContainsString('href="/e/' . $reference . '"', $html);
        self::assertStringNotContainsString('data-controller=', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public static function exactReferences(): array
    {
        return [
            'note' => ['note', (new NostrNip19Service())->encodeNote(self::ID)],
            'nevent' => ['nevent', (string) Bech32::nevent(id: self::ID, author: self::AUTHOR, kind: 30040)],
        ];
    }

    /** @dataProvider exactReferences */
    public function testServerResolvedExactTargetStillValidatesIdentityWithoutEventQueries(string $type, string $reference): void
    {
        $event = $this->event(30040, [['d', 'book'], ['title', 'Book']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findById');
        $events->expects(self::never())->method('findByNaddr');
        $component = $this->component($events);
        $component->mount($reference, $type, $event);
        self::assertSame(self::ID, $component->presentation->eventId);

        $event->setId(str_repeat('d', 64));
        $mismatch = $this->component($events);
        $mismatch->mount($reference, $type, $event);
        self::assertTrue($mismatch->restricted);
        self::assertNull($mismatch->presentation);
        self::assertStringNotContainsString('href=', $this->render($mismatch));
        self::assertStringNotContainsString('nostr--deferred-embed', $this->render($mismatch));
    }

    /** @dataProvider addressKinds */
    public function testAddressUsesLatestLookupAndSafeNavigation(int $kind, string $prefix): void
    {
        $event = $this->event($kind, [['d', 'intro'], ['title', 'Intro']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with($kind, self::AUTHOR, 'intro')->willReturn($event);
        $events->expects(self::never())->method('findById');
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'intro', $kind);
        $component = $this->component($events);
        $component->mount($reference, 'naddr');
        self::assertSame(self::ID, $component->presentation->eventId);
        self::assertSame($prefix . $reference, $component->href);
        $html = $this->render($component);
        self::assertStringContainsString('href="' . $prefix . $reference . '"', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
    }

    public static function addressKinds(): array
    {
        return [[30040, '/e/'], [30041, '/chapter/'], [30818, '/e/']];
    }

    /** @dataProvider invalidAddressTags */
    public function testAddressFirstDAndAuthorMismatchCannotExposeCandidate(array $tags, string $author): void
    {
        $event = $this->event(30040, $tags);
        $event->setPubkey($author);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn($event);
        $component = $this->component($events);
        $component->mount((new NostrNip19Service())->encodeAddr(self::AUTHOR, 'book', 30040), 'naddr');
        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public static function invalidAddressTags(): array
    {
        return [
            'duplicate first mismatch' => [[['d', 'wrong'], ['d', 'book']], self::AUTHOR],
            'malformed first' => [[['d', 42], ['d', 'book']], self::AUTHOR],
            'wrong author' => [[['d', 'book']], str_repeat('c', 64)],
        ];
    }

    public function testMissingKeepsDeferredPlaceholderButErrorsFailClosedAndLog(): void
    {
        $reference = (new NostrNip19Service())->encodeNote(self::ID);
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn(null);
        $component = $this->component($events);
        $component->mount($reference, 'note');
        $html = $this->render($component);
        self::assertStringContainsString('data-controller="nostr--deferred-embed"', $html);
        self::assertStringContainsString('href="/e/' . $reference . '"', $html);
        self::assertFalse($component->restricted);

        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willThrowException(new \RuntimeException('db unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('NostrEmbed: local resolution failed; denying preview', self::anything());
        $component = $this->component($events, logger: $logger);
        $component->mount($reference, 'note');
        self::assertTrue($component->restricted);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testMissingChapterAddressKeepsDeferredFallbackAndChapterLink(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'chapter', 30041);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->willReturn(null);
        $component = $this->component($events);
        $component->mount($reference, 'naddr');
        self::assertFalse($component->restricted);
        self::assertStringContainsString('data-controller="nostr--deferred-embed"', $this->render($component));
        self::assertSame('/chapter/' . $reference, $component->href);
    }

    public function testMissingCommentAddressStaysLocalOnlyAndKeepsRelayHintAndCoordinateIdentity(): void
    {
        $relay = 'wss://comment-parent.example.test';
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'parent:edition', 30040, [$relay]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, 'parent:edition')->willReturn(null);
        $events->expects(self::never())->method('findById');
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::never())->method('findOneBy');

        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');
        $html = $this->render($component);

        self::assertFalse($component->restricted);
        self::assertStringContainsString('data-controller="nostr--deferred-embed"', $html);
        self::assertStringContainsString('href="/e/' . $reference . '"', $html);
        $decoded = (new NostrNip19Service())->decode($component->bech);
        self::assertSame([$relay], $decoded['relays']);
        self::assertSame('parent:edition', $decoded['identifier']);
    }

    public function testExactIdCannotBeReplacedByAnotherRevision(): void
    {
        $event = $this->event(1, [['title', 'other revision']]);
        $event->setId(str_repeat('d', 64));
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($event);
        $component = $this->component($events);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
    }

    public function testEventBodyIsEscapedAndNestedReferencesAreNotExpanded(): void
    {
        $event = $this->event(1, [['title', '<script>unsafe</script>']]);
        $event->setContent('Text nostr:nevent1untrusted <img src="https://invalid.test/image">');
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->willReturn($event);
        $events->expects(self::never())->method('findByNaddr');
        $component = $this->component($events);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        $html = $this->render($component);
        self::assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('nostr--deferred-embed', $html);
        self::assertStringNotContainsString('data-controller=', $html);
    }

    public function testEmptyAddressAndFirstSignedDRemainDistinct(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findByNaddr')->with(30040, self::AUTHOR, '')
            ->willReturn($this->event(30040, [['d', ''], ['d', 'later']]));
        $component = $this->component($events);
        $component->mount((new NostrNip19Service())->encodeAddr(self::AUTHOR, '', 30040), 'naddr');
        self::assertSame(self::ID, $component->presentation->eventId);
        self::assertFalse($component->restricted);
    }

    /** @dataProvider warningTags */
    public function testWarningsHideAllPreviewMetadata(array $tag): void
    {
        $event = $this->event(20, [['title', 'secret-title'], ['summary', 'secret-summary'], ['image', 'https://secret.test/image'], $tag]);
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($event);
        $component = $this->component($events);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->sensitive);
        self::assertNull($component->presentation);
        $html = $this->render($component);
        self::assertStringContainsString('event-preview__sensitive', $html);
        foreach (['secret', self::ID, self::AUTHOR, '<img', '<a ', 'data-controller='] as $hidden) {
            self::assertStringNotContainsString($hidden, $html);
        }
    }

    public static function warningTags(): array
    {
        return [[['content-warning']], [['content-warning', 'secret-reason']], [['L', 'nsfw']], [['l', 'x', 'nsfw']], [['t', 'NSFW']]];
    }

    /** @dataProvider deniedKinds */
    public function testUnknownAndScopedKindsAreRestricted(int $kind): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($this->event($kind, [['d', 'x']]));
        $component = $this->component($events);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
    }

    public static function deniedKinds(): array
    {
        return [[4], [30024], [65530]];
    }

    public function testLongformRequiresExactEntitledProjectionAndUsesArticleRoute(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn($this->event(30023, [['d', 'article'], ['title', 'Article']]));
        $articles = $this->createMock(ArticleRepository::class);
        $article = $this->article()->setEssayistExclusive(true);
        $articles->expects(self::exactly(3))->method('findOneBy')->with(['eventId' => self::ID])->willReturn($article);
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);

        $anonymous = $this->component($events, $articles);
        $anonymous->mount($reference, 'naddr');
        self::assertTrue($anonymous->restricted);
        self::assertNull($anonymous->presentation);

        $security = $this->viewerSecurity(['ROLE_ESSAYIST_MEMBER']);
        $member = $this->component($events, $articles, security: $security);
        $member->mount($reference, 'naddr');
        self::assertSame(self::ID, $member->presentation->eventId);
        self::assertSame('/article/' . $reference, $member->href);

        $article->setEventId(str_repeat('d', 64));
        $mismatch = $this->component($events, $articles, security: $security);
        $mismatch->mount($reference, 'naddr');
        self::assertTrue($mismatch->restricted);
        self::assertNull($mismatch->presentation);
    }

    public function testLongformMissingProjectionFailsClosed(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn($this->event(30023, [['d', 'article']]));
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn(null);
        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');
        self::assertTrue($component->restricted);

        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $article = $this->articleWithRaw();
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findOneBy')
            ->with(
                ['slug' => 'article', 'pubkey' => self::AUTHOR, 'kind' => KindsEnum::LONGFORM],
                ['createdAt' => 'DESC', 'eventId' => 'ASC'],
            )
            ->willReturn($article);
        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');
        self::assertFalse($component->restricted);
        self::assertSame($article->getEventId(), $component->presentation->eventId);
        self::assertSame('Article title', $component->presentation->title);
        self::assertSame('Signed article content', $component->presentation->textContent);
        self::assertSame('/article/' . $reference, $component->href);
        self::assertStringContainsString('Article title', $this->render($component));
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testArticleOnlyPreviewFailsClosedWhenRawEventEnvelopeIsIncomplete(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($this->article());
        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');

        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testArticleOnlyPreviewFailsClosedWhenSignatureVerificationFails(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($this->articleWithRaw());
        $component = $this->component($events, $articles, verifier: $this->articleVerifier(valid: false));
        $component->mount($reference, 'naddr');

        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testArticleOnlyPreviewFailsClosedWhenSignedEnvelopeHasNoSignature(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $article = $this->articleWithRaw();
        $raw = $article->getRaw();
        unset($raw['sig']);
        $article->setRaw($raw);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($article);
        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');

        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testArticleOnlyPreviewFailsClosedWhenRawTimestampDiffersFromProjection(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $article = $this->articleWithRaw();
        $raw = $article->getRaw();
        $raw['created_at'] = 999;
        $article->setRaw($raw);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($article);
        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');

        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testArticleOnlyPreviewUsesWarningOnlyStateForSensitiveRawTags(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $article = $this->articleWithRaw([
            ['d', 'article'],
            ['title', 'Article title'],
            ['content-warning', 'private reason'],
        ]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($article);
        $component = $this->component($events, $articles);
        $component->mount($reference, 'naddr');
        $html = $this->render($component);

        self::assertFalse($component->restricted);
        self::assertTrue($component->sensitive);
        self::assertNull($component->presentation);
        self::assertStringContainsString('event-preview__sensitive', $html);
        self::assertStringNotContainsString('Article title', $html);
        self::assertStringNotContainsString('private reason', $html);
        self::assertStringNotContainsString('href=', $html);
        self::assertStringNotContainsString('data-controller=', $html);
    }

    public function testArticleOnlyPreviewAppliesAccessPolicyBeforePresentation(): void
    {
        $reference = (new NostrNip19Service())->encodeAddr(self::AUTHOR, 'article', 30023);
        $events = $this->createMock(EventRepository::class);
        $events->method('findByNaddr')->willReturn(null);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($this->articleWithRaw());
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::once())->method('assertReadable')->with(self::AUTHOR)
            ->willThrowException(new NotFoundHttpException());
        $component = $this->component($events, $articles, authors: $authors);
        $component->mount($reference, 'naddr');
        $html = $this->render($component);

        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('Article title', $html);
        self::assertStringNotContainsString('data-controller=', $html);
    }

    public function testLongformProjectionLookupFailureIsLoggedAndNotDeferred(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($this->event(30023, [['d', 'article']]));
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willThrowException(new \RuntimeException('projection unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('NostrEmbed: local resolution failed; denying preview', self::anything());
        $component = $this->component($events, $articles, logger: $logger);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public function testArticleRawWarningCannotBeClearedByEvent(): void
    {
        $event = $this->event(30023, [['d', 'article'], ['title', 'secret-title']]);
        $article = $this->article();
        $article->setRaw(['tags' => [['d', 'article'], ['content-warning', 'secret-reason']]]);
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($event);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($article);
        $component = $this->component($events, $articles);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->sensitive);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('secret', $this->render($component));
    }

    public function testEventWarningCannotBeClearedByArticleProjection(): void
    {
        $event = $this->event(30023, [['d', 'article'], ['title', 'secret-title'], ['t', 'NSFW']]);
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($event);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findOneBy')->willReturn($this->article());
        $component = $this->component($events, $articles);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->sensitive);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('secret', $this->render($component));
    }

    public function testLongformExactIdUsesItsOwnAuthorizedProjection(): void
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findById')->with(self::ID)
            ->willReturn($this->event(30023, [['d', 'article'], ['title', 'Exact']]));
        $events->expects(self::never())->method('findByNaddr');
        $articles = $this->createMock(ArticleRepository::class);
        $articles->expects(self::once())->method('findOneBy')->with(['eventId' => self::ID])->willReturn($this->article());
        $reference = (string) Bech32::nevent(id: self::ID, author: self::AUTHOR, kind: 30023);
        $component = $this->component($events, $articles);
        $component->mount($reference, 'nevent');
        self::assertSame(self::ID, $component->presentation->eventId);
        self::assertSame('/e/' . $reference, $component->href);
    }

    /** @dataProvider policyDenials */
    public function testVisibilityPolicyDeniesWithoutFallingBack(string $stage): void
    {
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $mutes = $this->createMock(UserMuteListService::class);
        if ($stage === 'author') {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $deletions->method('isSuppressed')->willReturn($stage === 'tombstone');
        $hidden->method('isHidden')->willReturn($stage === 'hidden');
        if ($stage === 'mute') {
            $mutes->method('getMutedPubkeysStrict')->willReturn([strtoupper(self::AUTHOR)]);
        }
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($this->event(30040, [['d', 'book'], ['title', 'private']]));
        $component = $this->component($events, authors: $authors, deletions: $deletions, hidden: $hidden, security: $this->viewerSecurity(), mutes: $mutes);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
        self::assertStringNotContainsString('private', $this->render($component));
        self::assertStringNotContainsString('data-controller=', $this->render($component));
    }

    public static function policyDenials(): array
    {
        return [['author'], ['tombstone'], ['hidden'], ['mute']];
    }

    public function testStrictMuteFailureIsLoggedAndDenied(): void
    {
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willThrowException(new \RuntimeException('mute unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with(
            'Event preview access policy failed; denying preview.',
            self::callback(static fn (array $context): bool => $context['policy_stage'] === 'strict_nip51_mute_list'),
        );
        $events = $this->createMock(EventRepository::class);
        $events->method('findById')->willReturn($this->event(1, []));
        $component = $this->component($events, logger: $logger, security: $this->viewerSecurity(), mutes: $mutes);
        $component->mount((new NostrNip19Service())->encodeNote(self::ID), 'note');
        self::assertTrue($component->restricted);
        self::assertNull($component->presentation);
    }

    private function component(
        EventRepository $events,
        ?ArticleRepository $articles = null,
        ?LoggerInterface $logger = null,
        ?ContentAuthorAccessPolicy $authors = null,
        ?DeletedEventRepository $deletions = null,
        ?HiddenCoordinateRepository $hidden = null,
        ?Security $security = null,
        ?UserMuteListService $mutes = null,
        ?NostrEventVerifier $verifier = null,
    ): NostrEmbed {
        $logger ??= new NullLogger();
        $policy = new EventPreviewAccessPolicy(
            $authors ?? $this->createMock(ContentAuthorAccessPolicy::class),
            $deletions ?? $this->createMock(DeletedEventRepository::class),
            $hidden ?? $this->createMock(HiddenCoordinateRepository::class),
            new RecordIdentityService(),
            $security ?? $this->createMock(Security::class),
            $mutes ?? $this->createMock(UserMuteListService::class),
            new NostrKeyService(),
            $logger,
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        return new NostrEmbed(
            $events, $articles ?? $this->createMock(ArticleRepository::class), $policy,
            new AuthoredArticlePreview(new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service())),
            $verifier ?? $this->articleVerifier(),
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()), $logger,
        );
    }

    private function articleVerifier(bool $valid = true): NostrEventVerifier
    {
        $signatures = $this->createMock(SignatureServiceInterface::class);
        $signatures->method('verify')->willReturn($valid);

        return new NostrEventVerifier(new NostrSigner($signatures));
    }

    private function viewerSecurity(array $roles = []): Security
    {
        $viewer = $this->createMock(UserInterface::class);
        $viewer->method('getRoles')->willReturn($roles);
        $viewer->method('getUserIdentifier')->willReturn((new NostrKeyService())->convertPublicKeyToBech32(str_repeat('c', 64)));
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($viewer);
        return $security;
    }

    private function event(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::ID);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setContent('Event body');
        $event->setTags($tags);
        $event->setSig(str_repeat('c', 128));
        return $event;
    }

    private function article(): Article
    {
        $article = (new Article())->setEventId(self::ID)->setKind(KindsEnum::LONGFORM)
            ->setPubkey(self::AUTHOR)->setSlug('article')->setCreatedAt(123);
        $article->setRaw(['tags' => [['d', 'article']]]);
        return $article;
    }

    /** @param list<list<string>> $tags */
    private function articleWithRaw(array $tags = [['d', 'article'], ['title', 'Article title']]): Article
    {
        $article = $this->article();
        $raw = NostrCoreEvent::fromArray([
            'pubkey' => self::AUTHOR,
            'created_at' => 123,
            'kind' => KindsEnum::LONGFORM->value,
            'tags' => $tags,
            'content' => 'Signed article content',
        ])->toArray();
        $raw['sig'] = str_repeat('c', 128);
        $article->setEventId($raw['id']);
        $article->setRaw($raw);
        return $article;
    }

    private function render(NostrEmbed $component): string
    {
        $twig = new Environment(new FilesystemLoader(dirname(__DIR__, 5) . '/templates'), ['strict_variables' => true]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $parameters = []): string => strtr($key, $parameters)));
        return $twig->render('components/Molecules/NostrEmbed.html.twig', get_object_vars($component));
    }
}
