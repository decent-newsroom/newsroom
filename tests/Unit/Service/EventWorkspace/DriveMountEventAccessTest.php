<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Entity\Article;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Enum\RolesEnum;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\DriveMountEventAccess;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

final class DriveMountEventAccessTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const READER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const EVENT_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function testExclusiveArticleOpeningUsesExistingMembershipEntitlement(): void
    {
        $event = $this->articleEvent();
        $article = $this->articleProjection(true);
        $referenceSource = new Event();
        $referenceSource->setId(str_repeat('d', 64));
        $referenceSource->setPubkey(self::AUTHOR);
        $referenceSource->setKind(30045);
        $referenceSource->setCreatedAt(123);
        $referenceSource->setTags([
            ['d', 'directory'],
            ['a', '30023:' . self::AUTHOR . ':story'],
        ]);
        $referenceSource->setContent('');

        $events = $this->createMock(EventRepository::class);
        $events->expects(self::exactly(2))->method('findWorkspaceDriveArticleTargets')
            ->with(['30023:' . self::AUTHOR . ':story'], [])
            ->willReturn(['coordinates' => ['30023:' . self::AUTHOR . ':story' => $event], 'eventIds' => []]);
        $articles = $this->createMock(ArticleRepository::class);
        $articles->method('findWorkspaceEntitlementByEventId')
            ->with(self::EVENT_ID)->willReturn($article);
        $member = $this->viewer([RolesEnum::ESSAYIST_MEMBER->value]);
        $access = $this->access($events, $articles, $member);
        $references = (new \App\Service\EventWorkspace\EventPresentationFactory(
            new RecordIdentityService(),
            new \App\Service\Nostr\NostrNip19Service(),
        ))->fromAuthorizedEvent($referenceSource)->references;

        self::assertSame([1 => $event], $access->readableArticleTargets($references));
        self::assertTrue($access->isReadableTarget($event));

        $nonMember = $this->viewer([]);
        $deniedAccess = $this->access($events, $articles, $nonMember);
        self::assertSame([], $deniedAccess->readableArticleTargets($references));
        self::assertFalse($deniedAccess->isReadableTarget($event));
    }

    public function testUnsupportedSensitiveAndScopedTargetsFailClosed(): void
    {
        $events = $this->createMock(EventRepository::class);
        $articles = $this->createMock(ArticleRepository::class);
        $access = $this->access($events, $articles, $this->viewer([RolesEnum::ESSAYIST_MEMBER->value]));
        $unsupported = $this->plainEvent(4, [['d', 'unsupported']]);
        $sensitive = $this->plainEvent(1, [['content-warning', 'private text']]);
        $scoped = $this->plainEvent(1, [['h', 'private-scope']]);

        self::assertFalse($access->isReadableTarget($unsupported));
        self::assertFalse($access->isReadableTarget($sensitive));
        self::assertFalse($access->isReadableTarget($scoped));
    }

    public function testPublicationAudienceScopesFailClosedForBothIndexesAndChapters(): void
    {
        $access = $this->access(
            $this->createMock(EventRepository::class),
            $this->createMock(ArticleRepository::class),
            $this->viewer([]),
        );
        foreach ([30040, 30041] as $kind) {
            self::assertTrue($access->isReadableTarget($this->plainEvent($kind, [['d', 'public']])));
            foreach ([['s', '30879:' . self::AUTHOR . ':members'], ['s'], ['s', 42], ['h', 'private']] as $scope) {
                self::assertFalse(
                    $access->isReadableTarget($this->plainEvent($kind, [['d', 'restricted'], $scope])),
                    'Audience-scoped publication kind ' . $kind . ' must not open.',
                );
            }
        }
    }

    public function testDeletionHiddenCoordinateMuteAndSuppressionAreRecheckedForEachTarget(): void
    {
        $target = $this->plainEvent(30041, [['d', 'chapter']]);
        foreach (['deleted', 'hidden', 'muted', 'suppressed'] as $denial) {
            $access = $this->access(
                $this->createMock(EventRepository::class),
                $this->createMock(ArticleRepository::class),
                $this->viewer([]),
                $denial,
            );
            self::assertFalse($access->isReadableTarget($target), $denial . ' targets must not open.');
        }
    }

    private function access(
        EventRepository $events,
        ArticleRepository $articles,
        UserInterface $viewer,
        ?string $denial = null,
    ): DriveMountEventAccess {
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        if ($denial === 'suppressed') {
            $authors->method('assertReadable')->willThrowException(new NotFoundHttpException());
        }
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturn($denial === 'deleted');
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('isHidden')->willReturn($denial === 'hidden');
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn($denial === 'muted' ? [self::AUTHOR] : []);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($viewer);
        $decoder = $this->createMock(\DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface::class);
        $encoder = $this->createMock(\DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface::class);
        $articleAccess = new ArticleAccessService(new NostrIdentityService($decoder, $encoder));
        $policy = new EventPreviewAccessPolicy(
            $authors,
            $deletions,
            $hidden,
            new RecordIdentityService(),
            $security,
            $mutes,
            new NostrKeyService(),
            new NullLogger(),
            $articleAccess,
        );

        return new DriveMountEventAccess($events, $articles, $policy);
    }

    /** @param list<string> $roles */
    private function viewer(array $roles): UserInterface
    {
        $viewer = $this->createMock(UserInterface::class);
        $viewer->method('getUserIdentifier')->willReturn(self::READER);
        $viewer->method('getRoles')->willReturn($roles);

        return $viewer;
    }

    private function articleEvent(): Event
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setPubkey(self::AUTHOR);
        $event->setKind(KindsEnum::LONGFORM->value);
        $event->setCreatedAt(123);
        $event->setTags([['d', 'story'], ['title', 'Story']]);
        $event->setContent('Signed article body');
        $event->setSig(str_repeat('f', 128));

        return $event;
    }

    /** @param list<array<mixed>> $tags */
    private function plainEvent(int $kind, array $tags): Event
    {
        $event = new Event();
        $event->setId(self::EVENT_ID);
        $event->setPubkey(self::AUTHOR);
        $event->setKind($kind);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent('body');
        $event->setSig(str_repeat('f', 128));

        return $event;
    }

    private function articleProjection(bool $exclusive): Article
    {
        $article = new Article();
        $article->setEventId(self::EVENT_ID);
        $article->setPubkey(self::AUTHOR);
        $article->setKind(KindsEnum::LONGFORM);
        $article->setSlug('story');
        $article->setCreatedAt(123);
        $article->setSig(str_repeat('f', 128));
        $article->setRaw(['tags' => [['d', 'story'], ['title', 'Story']]]);
        $article->setEssayistExclusive($exclusive);

        return $article;
    }
}
