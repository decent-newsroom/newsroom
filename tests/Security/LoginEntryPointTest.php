<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User;
use App\Security\UserDTOProvider;
use App\Service\Nostr\NostrKeyService;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

final class LoginEntryPointTest extends WebTestCase
{
    /** @dataProvider protectedPages */
    public function testAnonymousBrowserIsRedirectedToLogin(string $path): void
    {
        $client = static::createClient();
        $client->request('GET', $path);

        self::assertResponseRedirects('/login', 302);
    }

    public static function protectedPages(): iterable
    {
        yield 'firewall access control' => ['/admin'];
        yield 'controller authorization' => ['/my-content'];
    }

    /** @dataProvider jsonRequests */
    public function testAnonymousProgrammaticRequestsStillReceiveJson(array $headers): void
    {
        $client = static::createClient();
        $client->request('GET', '/my-content', [], [], $headers);

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame('Authentication required', json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']);
    }

    public static function jsonRequests(): iterable
    {
        yield 'accept header' => [['HTTP_ACCEPT' => 'application/json']];
        yield 'content type' => [['CONTENT_TYPE' => 'application/json']];
        yield 'ajax' => [['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']];
    }

    /** @dataProvider forbiddenRequests */
    public function testAuthenticatedUserWithoutRoleRemainsForbidden(array $headers): void
    {
        $client = static::createClient();
        $user = new User();
        $user->setNpub((new NostrKeyService())->convertPublicKeyToBech32(str_repeat('a', 64)));
        static::getContainer()->set(UserDTOProvider::class, new class implements UserProviderInterface {
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
        });
        $client->loginUser($user);
        $client->request('GET', '/admin', [], [], $headers);

        self::assertResponseStatusCodeSame(403);
        if ($headers !== []) {
            self::assertResponseHeaderSame('Content-Type', 'application/json');
            self::assertSame('Access denied', json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['error']);
        }
    }

    public static function forbiddenRequests(): iterable
    {
        yield 'browser' => [[]];
        yield 'json' => [['HTTP_ACCEPT' => 'application/json']];
    }
}
