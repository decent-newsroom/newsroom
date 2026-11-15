<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Event;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Repository\DeletedEventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;

final class EventPreviewAccessPolicyTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const VIEWER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const EVENT_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testAdminOrPermanentAuthorSuppressionDeniesPreview(): void
    {
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects(self::once())
            ->method('assertReadable')
            ->with(self::AUTHOR)
            ->willThrowException(new NotFoundHttpException());
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::never())->method('isSuppressed');

        self::assertFalse($this->policy($authors, $deletions)->isReadable($this->event()));
    }

    public function testNip09EventTombstoneDeniesExactEventPreview(): void
    {
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())
            ->method('isSuppressed')
            ->with(self::EVENT_ID, 1, self::AUTHOR, null, 123)
            ->willReturn(true);

        self::assertFalse($this->policy(deletions: $deletions)->isReadable($this->event()));
    }

    public function testNip09CoordinateTombstoneDeniesAddressablePreview(): void
    {
        $event = $this->event(30023, [['d', 'article-slug']]);
        $coordinate = '30023:' . self::AUTHOR . ':article-slug';
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('isHidden')->with($coordinate)->willReturn(false);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())
            ->method('isSuppressed')
            ->with(self::EVENT_ID, 30023, self::AUTHOR, 'article-slug', 123)
            ->willReturn(true);

        self::assertFalse($this->policy(hidden: $hidden, deletions: $deletions)->isReadable($event));
    }

    public function testHiddenCoordinateDeniesAddressablePreviewBeforeTombstoneLookup(): void
    {
        $event = $this->event(30023, [['d', 'article-slug']]);
        $coordinate = '30023:' . self::AUTHOR . ':article-slug';
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())->method('isHidden')->with($coordinate)->willReturn(true);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::never())->method('isSuppressed');

        self::assertFalse($this->policy(hidden: $hidden, deletions: $deletions)->isReadable($event));
    }

    public function testAuthenticatedViewerStrictMuteDeniesAuthorsContent(): void
    {
        $security = $this->securityForViewer();
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->expects(self::once())
            ->method('getMutedPubkeysStrict')
            ->with(self::VIEWER)
            ->willReturn([strtoupper(self::AUTHOR)]);

        self::assertFalse($this->policy(security: $security, mutes: $mutes)->isReadable($this->event()));
    }

    public function testStrictMuteLookupFailureFailsClosed(): void
    {
        $security = $this->securityForViewer();
        $mutes = $this->createMock(UserMuteListService::class);
        $failure = new \RuntimeException('mute list unavailable');
        $mutes->expects(self::once())
            ->method('getMutedPubkeysStrict')
            ->with(self::VIEWER)
            ->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                'Event preview access policy failed; denying preview.',
                [
                    'event_id' => self::EVENT_ID,
                    'policy_stage' => 'strict_nip51_mute_list',
                    'exception_class' => \RuntimeException::class,
                    'exception_message' => 'mute list unavailable',
                ],
            );

        self::assertFalse($this->policy(security: $security, mutes: $mutes, logger: $logger)->isReadable($this->event()));
    }

    public function testUnauthenticatedPublicPreviewDoesNotReadMuteList(): void
    {
        $security = $this->createMock(Security::class);
        $security->expects(self::once())->method('getUser')->willReturn(null);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->expects(self::never())->method('getMutedPubkeysStrict');

        self::assertTrue($this->policy(security: $security, mutes: $mutes)->isReadable($this->event()));
    }

    public function testArticleProjectionRequiresCompleteIdentityAndExclusiveEntitlement(): void
    {
        $policy = $this->policy();
        $article = $this->article()->setEssayistExclusive(true);
        self::assertFalse($policy->isArticleReadable($article, self::AUTHOR, 'article-slug'));

        $incomplete = $this->article()->setEventId('');
        self::assertFalse($policy->isArticleReadable($incomplete, self::AUTHOR, 'article-slug'));
    }

    public function testArticleProjectionAppliesCoordinateAndExactTombstonePolicy(): void
    {
        $article = $this->article();
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->expects(self::once())
            ->method('isHidden')
            ->with('30023:' . self::AUTHOR . ':article-slug')
            ->willReturn(true);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::never())->method('isSuppressed');

        self::assertFalse($this->policy(hidden: $hidden, deletions: $deletions)
            ->isArticleReadable($article, self::AUTHOR, 'article-slug'));
    }

    public function testArticleProjectionCoordinateTombstoneDeniesPreview(): void
    {
        $article = $this->article();
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('isHidden')->willReturn(false);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->expects(self::once())
            ->method('isSuppressed')
            ->with(self::EVENT_ID, 30023, self::AUTHOR, 'article-slug', 123)
            ->willReturn(true);

        self::assertFalse($this->policy(hidden: $hidden, deletions: $deletions)
            ->isArticleReadable($article, self::AUTHOR, 'article-slug'));
    }

    public function testEssayistMemberCanViewExclusiveArticleProjection(): void
    {
        $viewer = $this->createMock(UserInterface::class);
        $viewer->method('getUserIdentifier')->willReturn(self::VIEWER);
        $viewer->method('getRoles')->willReturn(['ROLE_USER', 'ROLE_ESSAYIST_MEMBER']);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($viewer);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->expects(self::once())->method('getMutedPubkeysStrict')->with(self::VIEWER)->willReturn([]);

        $article = $this->article()->setEssayistExclusive(true);
        self::assertTrue($this->policy(security: $security, mutes: $mutes)
            ->isArticleReadable($article, self::AUTHOR, 'article-slug'));
    }

    /** @dataProvider articleFirstDCases */
    public function testArticleRequiresMatchingSignedFirstD(?array $raw, string $identifier, bool $readable): void
    {
        $article = $this->article()->setSlug($identifier);
        $article->setRaw($raw);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->expects($readable ? self::once() : self::never())->method('assertReadable');

        self::assertSame($readable, $this->policy(authors: $authors)->isArticleReadable($article, self::AUTHOR, $identifier));
    }

    public static function articleFirstDCases(): iterable
    {
        yield 'duplicate cannot overwrite first identity' => [['tags' => [['d', 'other'], ['d', 'article-slug']]], 'article-slug', false];
        yield 'matching first wins over later duplicate' => [['tags' => [['d', 'article-slug'], ['d', 'other']]], 'article-slug', true];
        yield 'malformed first cannot fall through' => [['tags' => [['d', 42], ['d', 'article-slug']]], 'article-slug', false];
        yield 'missing first value' => [['tags' => [['d'], ['d', 'article-slug']]], 'article-slug', false];
        yield 'mismatched first' => [['tags' => [['d', 'other']]], 'article-slug', false];
        yield 'absent raw data' => [null, 'article-slug', false];
        yield 'missing tags' => [[], 'article-slug', false];
        yield 'malformed tags' => [['tags' => 'invalid'], 'article-slug', false];
        yield 'explicit empty first' => [['tags' => [['d', ''], ['d', 'other']]], '', true];
        yield 'missing d is not empty' => [['tags' => []], '', false];
        yield 'null first is not empty' => [['tags' => [['d', null], ['d', '']]], '', false];
        yield 'empty first cannot be overwritten' => [['tags' => [['d', ''], ['d', 'article-slug']]], 'article-slug', false];
    }

    private function policy(
        ?ContentAuthorAccessPolicy $authors = null,
        ?DeletedEventRepository $deletions = null,
        ?HiddenCoordinateRepository $hidden = null,
        ?Security $security = null,
        ?UserMuteListService $mutes = null,
        ?LoggerInterface $logger = null,
    ): EventPreviewAccessPolicy {
        if ($authors === null) {
            $authors = $this->createMock(ContentAuthorAccessPolicy::class);
            $authors->expects(self::once())->method('assertReadable')->with(self::AUTHOR);
        }

        if ($deletions === null) {
            $deletions = $this->createMock(DeletedEventRepository::class);
            $deletions->method('isSuppressed')->willReturn(false);
        }
        if ($hidden === null) {
            $hidden = $this->createMock(HiddenCoordinateRepository::class);
            $hidden->method('isHidden')->willReturn(false);
        }
        if ($security === null) {
            $security = $this->createMock(Security::class);
            $security->method('getUser')->willReturn(null);
        }
        $mutes ??= $this->createMock(UserMuteListService::class);
        $logger ??= $this->createMock(LoggerInterface::class);

        return new EventPreviewAccessPolicy(
            $authors,
            $deletions,
            $hidden,
            new RecordIdentityService(),
            $security,
            $mutes,
            new NostrKeyService(),
            $logger,
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
    }

    private function securityForViewer(): Security
    {
        $identity = (new NostrKeyService())->convertPublicKeyToBech32(self::VIEWER);
        $user = $this->createMock(UserInterface::class);
        $user->method('getUserIdentifier')->willReturn($identity);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    /** @param list<list<string>> $tags */
    private function event(int $kind = 1, array $tags = []): Event
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setKind($kind);
        $event->setPubkey(self::AUTHOR);
        $event->setCreatedAt(123);
        $event->setTags($tags);

        return $event;
    }

    private function article(): Article
    {
        $article = (new Article())
            ->setEventId(self::EVENT_ID)
            ->setKind(KindsEnum::LONGFORM)
            ->setPubkey(self::AUTHOR)
            ->setSlug('article-slug')
            ->setCreatedAt(123);
        $article->setRaw(['tags' => [['d', 'article-slug']]]);

        return $article;
    }
}
