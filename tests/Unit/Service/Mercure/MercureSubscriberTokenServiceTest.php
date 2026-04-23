<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Mercure;

use App\Entity\User;
use App\Service\Mercure\MercureSubscriberTokenService;
use PHPUnit\Framework\TestCase;

class MercureSubscriberTokenServiceTest extends TestCase
{
    public function testTokenContainsOnlyOwnUserTopicInSubscribeClaim(): void
    {
        $service = new MercureSubscriberTokenService('test-secret');

        $user = new User();
        $user->setId(7);
        $user->setNpub('npub1testuser');

        $token = $service->mintForUser($user);
        $parts = explode('.', $token);
        self::assertCount(3, $parts, 'JWT should have three segments');

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        self::assertIsArray($payload);
        self::assertSame(['/users/7/notifications'], $payload['mercure']['subscribe']);
        self::assertArrayHasKey('exp', $payload);
        self::assertGreaterThan(time(), $payload['exp']);
    }

    public function testTopicForUserUsesNumericId(): void
    {
        $user = new User();
        $user->setId(42);
        $user->setNpub('npub1whatever');
        self::assertSame('/users/42/notifications', MercureSubscriberTokenService::topicForUser($user));
    }
}

