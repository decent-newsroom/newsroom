<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Repository\UpdateRepository;
use App\Repository\UpdateSubscriptionRepository;
use App\Security\UserDTOProvider;
use App\Service\Nostr\NostrKeyService;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class SubscriptionsControllerTest extends WebTestCase
{
    /** @dataProvider readerPages */
    public function testFreeReaderCanOpenSubscriptionPages(string $path, string $heading): void
    {
        $client = static::createClient();
        $this->login($client);
        $updates = $this->createMock(UpdateRepository::class);
        $updates->method('findRecentForUser')->willReturn([]);
        static::getContainer()->set(UpdateRepository::class, $updates);
        $sources = $this->createMock(UpdateSubscriptionRepository::class);
        $sources->method('findActiveForUser')->willReturn([]);
        static::getContainer()->set(UpdateSubscriptionRepository::class, $sources);

        $client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('private'));
        self::assertTrue($client->getResponse()->headers->hasCacheControlDirective('no-store'));
        self::assertSelectorTextContains('h1', $heading);
        self::assertSelectorExists('meta[name="turbo-cache-control"][content="no-cache"]');
        self::assertSelectorNotExists('a[href="/subscription/updates-pro"]');
    }

    public static function readerPages(): iterable
    {
        yield 'feed' => ['/subscriptions', 'Subscriptions'];
        yield 'sources' => ['/subscriptions/sources', 'Subscribed sources'];
        yield 'legacy feed' => ['/updates', 'Subscriptions'];
        yield 'legacy sources' => ['/updates/subscriptions', 'Subscribed sources'];
    }

    public function testFeedFragmentRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request('GET', '/subscriptions/items');
        self::assertResponseRedirects('/login');
    }

    /** @dataProvider retiredPurchaseLinks */
    public function testRetiredPurchasesRedirectToFreeSubscriptions(string $method, string $path): void
    {
        $client = static::createClient();
        $client->request($method, $path);
        self::assertResponseRedirects('/subscriptions', 303);
    }

    public static function retiredPurchaseLinks(): iterable
    {
        yield 'landing' => ['GET', '/subscription/updates-pro'];
        yield 'purchase' => ['POST', '/subscription/updates-pro/subscribe/monthly'];
        yield 'renewal' => ['POST', '/subscription/updates-pro/renew/yearly'];
    }

    private function login(KernelBrowser $client): void
    {
        $user = new User();
        $user->setNpub((new NostrKeyService())->convertPublicKeyToBech32(str_repeat('a', 64)));
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
                throw new \LogicException('The test supplies its reader directly.');
            }
        };
        static::getContainer()->set(UserDTOProvider::class, $provider);
        $client->loginUser($user);
    }
}
