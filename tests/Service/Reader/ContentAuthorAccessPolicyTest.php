<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Repository\BannedPubkeyRepository;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ContentAuthorAccessPolicyTest extends TestCase
{
    public function testFreshDatabaseMuteAndBanMembershipWithoutContentRows(): void
    {
        $url = $_ENV['DATABASE_URL'] ?? $_SERVER['DATABASE_URL'] ?? null;
        if (!is_string($url) || $url === '') {
            self::markTestSkipped('DATABASE_URL is required for PostgreSQL author access tests.');
        }
        $parameters = (new DsnParser(['postgresql' => 'pdo_pgsql', 'postgres' => 'pdo_pgsql']))->parse($url);
        $connection = DriverManager::getConnection($parameters);
        $connection->beginTransaction();

        try {
            $schema = 'author_access_test_' . bin2hex(random_bytes(6));
            $connection->executeStatement('CREATE SCHEMA ' . $schema);
            $connection->executeStatement('SET LOCAL search_path TO ' . $schema);
            $connection->executeStatement('CREATE TABLE app_user (npub VARCHAR(255) UNIQUE, roles JSON)');
            $connection->executeStatement('CREATE TABLE banned_pubkey (pubkey VARCHAR(64) PRIMARY KEY)');

            $manager = $this->createMock(EntityManagerInterface::class);
            $manager->method('getConnection')->willReturn($connection);
            $manager->method('getClassMetadata')->willReturnCallback(static fn (string $class) => new ClassMetadata($class));
            $registry = $this->createMock(ManagerRegistry::class);
            $registry->method('getManagerForClass')->willReturn($manager);
            $users = new UserEntityRepository($registry, $manager);
            $keys = new NostrKeyService();
            $policy = new ContentAuthorAccessPolicy($users, new BannedPubkeyRepository($registry), $keys, new NullLogger());
            $hex = str_repeat('a', 64);
            $npub = $keys->convertPublicKeyToBech32($hex);

            self::assertFalse($policy->isSuppressed($hex));
            $connection->insert('app_user', ['npub' => $npub, 'roles' => '["ROLE_ADMIN","ROLE_MUTED_EXTRA"]']);
            self::assertFalse($users->isAdminMuted($npub));
            self::assertFalse($policy->isSuppressed($hex));

            $connection->update('app_user', ['roles' => '["ROLE_ADMIN","ROLE_MUTED"]'], ['npub' => $npub]);
            self::assertTrue($users->isAdminMuted($npub));
            $this->assertNotFound($policy, $hex);

            $connection->update('app_user', ['roles' => null], ['npub' => $npub]);
            self::assertFalse($policy->isSuppressed($hex));
            $connection->insert('banned_pubkey', ['pubkey' => $hex]);
            $this->assertNotFound($policy, $npub);

            $connection->delete('app_user', ['npub' => $npub]);
            $this->assertNotFound($policy, $hex);
            self::assertFalse($policy->isSuppressed(str_repeat('b', 64)));
        } finally {
            $connection->rollBack();
            $connection->close();
        }
    }

    private function assertNotFound(ContentAuthorAccessPolicy $policy, string $identifier): void
    {
        try {
            $policy->assertReadable($identifier);
            self::fail('Suppressed author must not be readable.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('Content not found.', $exception->getMessage());
        }
    }
}
