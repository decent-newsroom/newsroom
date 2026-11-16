<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Article;
use App\Entity\Event;
use App\Entity\User;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Security\UserDTOProvider;
use App\Service\Nostr\NostrClient;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\UserProfileService;
use App\Service\Search\ContentSearchService;
use App\Util\ForumTopics;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class HomeInterestNavigationTest extends WebTestCase
{
    private const READER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OTHER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testSignedInReaderSeesInterestsAndNamedSets(): void
    {
        $client = static::createClient();
        $this->login($client);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findLatestByPubkeyAndKind')
            ->with(self::READER, KindsEnum::INTERESTS->value)->willReturn(new Event());
        static::getContainer()->set(EventRepository::class, $events);
        $profiles = $this->createMock(UserProfileService::class);
        $profiles->expects(self::once())->method('getInterestSets')->with(self::READER)->willReturn($this->sets());
        static::getContainer()->set(UserProfileService::class, $profiles);

        $crawler = $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSelectorExists('.stream-topics a[href="/my-interests"]');
        self::assertSelectorCount(count(ForumTopics::TOPICS) + 5, '.stream-topics a');
        self::assertSame('Own & <set>', $crawler->filter('.stream-topics a')->eq(1)->text());
        self::assertSelectorNotExists('.stream-topics script');
        $links = $crawler->filter('.stream-topics a');
        self::assertSame('/my-interests/set/shared?author=' . self::READER, $links->eq(1)->attr('href'));
        self::assertSame('/my-interests/set/shared?author=' . self::OTHER, $links->eq(2)->attr('href'));
        self::assertSame('/my-interests/set?author=' . self::OTHER, $links->eq(3)->attr('href'));
        self::assertSame('/my-interests/set/science/notes?author=' . self::READER, $links->eq(4)->attr('href'));
    }

    public function testOwnedSetsAppearWithoutAnInterestsEvent(): void
    {
        $client = static::createClient();
        $this->login($client);
        $events = $this->createMock(EventRepository::class);
        $events->method('findLatestByPubkeyAndKind')->willReturn(null);
        static::getContainer()->set(EventRepository::class, $events);
        $profiles = $this->createMock(UserProfileService::class);
        $profiles->method('getInterestSets')->with(self::READER)->willReturn([$this->sets()[0]]);
        static::getContainer()->set(UserProfileService::class, $profiles);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.stream-topics a[href="/my-interests"]');
        self::assertSelectorExists('.stream-topics a[href="/my-interests/set/shared?author=' . self::READER . '"]');
    }

    public function testHomeUsesLocallyStoredInterestsAndSets(): void
    {
        $client = static::createClient();
        $this->login($client);
        $list = new Event();
        $list->setTags([['a', '30015:' . self::OTHER . ':followed']]);
        $followed = new Event();
        $followed->setTags([['title', 'Followed'], ['t', 'nostr']]);
        $owned = new Event();
        $owned->setDTag('own');
        $owned->setTags([['title', 'Owned'], ['t', 'bitcoin']]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::exactly(2))->method('findLatestByPubkeyAndKind')
            ->with(self::READER, KindsEnum::INTERESTS->value)->willReturn($list);
        $events->expects(self::once())->method('findByNaddr')
            ->with(KindsEnum::INTEREST_SETS->value, self::OTHER, 'followed')->willReturn($followed);
        $events->expects(self::once())->method('findAllByPubkeyAndKind')
            ->with(self::READER, KindsEnum::INTEREST_SETS->value)->willReturn([$owned]);
        static::getContainer()->set(EventRepository::class, $events);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.stream-topics a[href="/my-interests"]');
        self::assertSelectorExists('.stream-topics a[href="/my-interests/set/followed?author=' . self::OTHER . '"]');
        self::assertSelectorExists('.stream-topics a[href="/my-interests/set/own?author=' . self::READER . '"]');
    }

    public function testReaderWithoutNostrIdentityDoesNotResolveInterestLists(): void
    {
        $client = static::createClient();
        $this->login($client, false);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findLatestByPubkeyAndKind');
        static::getContainer()->set(EventRepository::class, $events);
        $profiles = $this->createMock(UserProfileService::class);
        $profiles->expects(self::never())->method('getInterestSets');
        static::getContainer()->set(UserProfileService::class, $profiles);

        $client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorCount(count(ForumTopics::TOPICS), '.stream-topics a');
    }

    /**
     * @dataProvider interestSetRoutes
     */
    public function testSetLinksResolveTheExactFollowedCoordinate(string $path, string $expectedTitle, string $expectedTag): void
    {
        $client = static::createClient();
        $this->login($client);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->method('getUserInterestSets')->with(self::READER)->willReturn($this->sets());
        static::getContainer()->set(NostrClient::class, $nostr);
        $search = $this->createMock(ContentSearchService::class);
        $search->expects(self::once())->method('searchByTopics')->with([$expectedTag], 200)->willReturn([]);
        static::getContainer()->set(ContentSearchService::class, $search);

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', $expectedTitle);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function interestSetRoutes(): iterable
    {
        yield 'same identifier, other author' => ['/my-interests/set/shared?author=' . self::OTHER, 'Followed set', 'followed'];
        yield 'empty identifier' => ['/my-interests/set?author=' . self::OTHER, 'Empty identifier', 'empty'];
        yield 'identifier with slash' => ['/my-interests/set/science/notes?author=' . self::READER, 'Science', 'science'];
        yield 'legacy link without author' => ['/my-interests/set/shared', 'Own & <set>', 'own'];
    }

    /**
     * @dataProvider interestSetPaginationRoutes
     */
    public function testPaginationKeepsTheSetCoordinate(string $path, string $previousUrl): void
    {
        $client = static::createClient();
        $this->login($client);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->method('getUserInterestSets')->willReturn($this->sets());
        static::getContainer()->set(NostrClient::class, $nostr);
        $article = (new Article())->setPubkey(self::READER)->setSlug('example')
            ->setTitle('Example')->setKind(KindsEnum::LONGFORM)->setCreatedAt(1);
        $search = $this->createMock(ContentSearchService::class);
        $search->method('searchByTopics')->willReturn(array_fill(0, 21, $article));
        static::getContainer()->set(ContentSearchService::class, $search);

        $crawler = $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame($previousUrl, $crawler->filter('.pagination-prev')->attr('href'));
    }

    /** @return iterable<string, array{string, string}> */
    public static function interestSetPaginationRoutes(): iterable
    {
        yield 'nonempty identifier' => [
            '/my-interests/set/shared?author=' . self::OTHER . '&page=2',
            '/my-interests/set/shared?author=' . self::OTHER . '&page=1',
        ];
        yield 'empty identifier' => [
            '/my-interests/set?author=' . self::OTHER . '&page=2',
            '/my-interests/set?author=' . self::OTHER . '&page=1',
        ];
    }

    public function testSetRouteRejectsAnUnfollowedAuthor(): void
    {
        $client = static::createClient();
        $this->login($client);
        $nostr = $this->createMock(NostrClient::class);
        $nostr->method('getUserInterestSets')->willReturn($this->sets());
        static::getContainer()->set(NostrClient::class, $nostr);

        $client->request('GET', '/my-interests/set/shared?author=' . str_repeat('c', 64));
        self::assertResponseStatusCodeSame(404);
    }

    private function login(KernelBrowser $client, bool $withNostrIdentity = true): void
    {
        $user = new User();
        if ($withNostrIdentity) {
            $user->setNpub((new NostrKeyService())->convertPublicKeyToBech32(self::READER));
        } else {
            $user->setLocalIdentifier('test-local-reader');
        }
        $provider = new class implements UserProviderInterface {
            public function refreshUser(UserInterface $user): UserInterface
            {
                return $user;
            }

            public function supportsClass(string $class): bool
            {
                return $class === User::class;
            }

            public function loadUserByIdentifier(string $identifier): UserInterface
            {
                throw new \LogicException('The test does not load users by identifier.');
            }
        };
        static::getContainer()->set(UserDTOProvider::class, $provider);
        $client->loginUser($user);
    }

    /** @return list<array{title: string, tags: list<string>, pubkey: string, dTag: string, followed: bool, owned: bool}> */
    private function sets(): array
    {
        return [
            ['title' => 'Own & <set>', 'tags' => ['own'], 'pubkey' => self::READER, 'dTag' => 'shared', 'followed' => false, 'owned' => true],
            ['title' => 'Followed set', 'tags' => ['followed'], 'pubkey' => self::OTHER, 'dTag' => 'shared', 'followed' => true, 'owned' => false],
            ['title' => 'Empty identifier', 'tags' => ['empty'], 'pubkey' => self::OTHER, 'dTag' => '', 'followed' => true, 'owned' => false],
            ['title' => 'Science', 'tags' => ['science'], 'pubkey' => self::READER, 'dTag' => 'science/notes', 'followed' => false, 'owned' => true],
        ];
    }
}
