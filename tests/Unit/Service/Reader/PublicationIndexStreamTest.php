<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Entity\Event;
use App\Entity\User;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\PublicationIndexStream;
use App\Service\UserMuteListService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;

final class PublicationIndexStreamTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testCurrentPublicIndexesAreBoundedAndDoNotReviveRestrictedRevisions(): void
    {
        $eventRows = [
            $this->event('1', self::AUTHOR, 200, [['d', 'book'], ['title', 'Current book'], ['summary', 'A book'], ['image', 'https://example.org/cover']]),
            $this->event('2', self::AUTHOR, 199, [['d', 'book'], ['title', 'Old book']]),
            $this->event('3', self::AUTHOR, 198, [['d', 'Book'], ['title', 'Case-sensitive']]),
            $this->event('4', self::AUTHOR, 197, [['d', 'private'], ['s', 'private'], ['title', 'Scoped secret']]),
            $this->event('5', self::AUTHOR, 196, [['d', 'private'], ['title', 'Old secret']]),
            $this->event('6', self::AUTHOR, 195, [['d', 'sensitive'], ['content-warning'], ['title', 'Sensitive secret']]),
            $this->event('7', self::AUTHOR, 194, [['d', 'hidden'], ['title', 'Hidden secret']]),
            $this->event('8', self::AUTHOR, 193, [['d', 'deleted'], ['title', 'Deleted secret']]),
            $this->event('9', self::OTHER, 192, [['d', 'suppressed'], ['title', 'Suppressed secret']]),
            $this->event('a', self::AUTHOR, 191, [['d', ['malformed']], ['d', 'not-an-identity']]),
            $this->event('b', self::AUTHOR, 190, [['title', 'Empty identifier'], ['image', 'javascript:alert(1)']]),
        ];
        $repo = $this->createMock(EventRepository::class);
        $repo->expects(self::once())->method('findBy')
            ->with(['kind' => 30040], ['created_at' => 'DESC', 'id' => 'ASC'], 200)
            ->willReturn($eventRows);
        $service = $this->service($repo, [self::OTHER], ['30040:' . self::AUTHOR . ':hidden'], [str_repeat('8', 64)]);
        $result = $service->load();

        self::assertSame(['Current book', 'Case-sensitive', 'Empty identifier'], array_column($result['items'], 'title'));
        self::assertSame(['book', 'Book', ''], array_column($result['items'], 'slug'));
        self::assertSame('https://example.org/cover', $result['items'][0]['image']);
        self::assertNull($result['items'][2]['image']);
        self::assertTrue(PublicationIndexStream::isSuperseded(['pubkey' => self::AUTHOR, 'slug' => 'private'], $result['seen']));
        self::assertFalse(PublicationIndexStream::isSuperseded(['pubkey' => self::AUTHOR, 'slug' => 'BOOK'], $result['seen']));
        self::assertStringNotContainsString('secret', serialize($result['items']));
    }

    public function testProfileSourceUsesAuthorAndHonorsStrictViewerMutes(): void
    {
        $repo = $this->createMock(EventRepository::class);
        $repo->expects(self::once())->method('findBy')
            ->with(['kind' => 30040, 'pubkey' => self::AUTHOR], ['created_at' => 'DESC', 'id' => 'ASC'], 200)
            ->willReturn([$this->event('1', self::AUTHOR, 100, [['d', 'book'], ['title', 'Muted']])]);
        $user = $this->createMock(User::class);
        $user->method('getUserIdentifier')->willReturn(self::OTHER);
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $keys = new NostrKeyService();
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->expects(self::once())->method('getMutedPubkeysStrict')->with(self::OTHER)->willReturn([self::AUTHOR]);

        $result = $this->service($repo, security: $security, keys: $keys, mutes: $mutes)->load(self::AUTHOR);
        self::assertSame([], $result['items']);
        self::assertTrue(PublicationIndexStream::isSuperseded(['pubkey' => self::AUTHOR, 'slug' => 'book'], $result['seen']));
    }

    private function event(string $id, string $author, int $created, array $tags): Event
    {
        $event = new Event();
        $event->setId(str_repeat($id, 64));
        $event->setPubkey($author);
        $event->setKind(30040);
        $event->setCreatedAt($created);
        $event->setTags($tags);
        return $event;
    }

    private function service(
        EventRepository $repo,
        array $suppressed = [],
        array $hidden = [],
        array $deleted = [],
        ?Security $security = null,
        ?NostrKeyService $keys = null,
        ?UserMuteListService $mutes = null,
    ): PublicationIndexStream {
        $security ??= $this->createMock(Security::class);
        $keys ??= new NostrKeyService();
        $mutes ??= $this->createMock(UserMuteListService::class);
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn($suppressed);
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('findSuppressedEventIds')->willReturn($deleted);
        $coordinates = $this->createMock(HiddenCoordinateRepository::class);
        $coordinates->method('findHiddenCoordinates')->willReturn($hidden);
        $access = new EventPreviewAccessPolicy(
            $authors, $deletions, $coordinates, new RecordIdentityService(), $security,
            $mutes, $keys, new NullLogger(), new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class), $this->createMock(Nip19EncoderInterface::class),
            )),
        );
        return new PublicationIndexStream($repo, $access, $security, $keys);
    }
}
