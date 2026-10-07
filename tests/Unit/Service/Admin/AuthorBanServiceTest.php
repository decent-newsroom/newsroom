<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Admin;

use App\Entity\User;
use App\Enum\RolesEnum;
use App\Repository\UserEntityRepository;
use App\Service\Admin\AuthorBanService;
use App\Service\MutedPubkeysService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class AuthorBanServiceTest extends TestCase
{
    /** @dataProvider userProvider */
    public function testBanIsCommittedAndMutePreservesExistingRoles(bool $existing): void
    {
        $hex = str_repeat('a', 64);
        $npub = PublicKey::fromHex($hex)->toBech32();
        $user = $existing ? new User() : null;
        if ($user !== null) {
            $user->setNpub($npub);
            $user->addRole(RolesEnum::WRITER->value);
        }
        $users = $this->createMock(UserEntityRepository::class);
        $users->method('findOneBy')->with(['npub' => $npub])->willReturn($user);
        $conn = $this->createMock(Connection::class);
        $committed = false;
        $conn->method('transactional')->willReturnCallback(static function (callable $work) use ($conn, &$committed): void {
            $work($conn);
            $committed = true;
        });
        $conn->expects(self::once())->method('executeStatement')->with(
            self::callback(static fn (string $sql): bool => str_contains($sql, 'ON CONFLICT (pubkey) DO NOTHING')),
            self::callback(static fn (array $params): bool => $params['pubkey'] === $hex
                && $params['reason'] === 'spam' && $params['addedBy'] === 'operator'),
        )->willReturn(1);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);
        $created = null;
        if ($existing) {
            $em->expects(self::never())->method('persist');
        } else {
            $em->expects(self::once())->method('persist')->willReturnCallback(static function (User $newUser) use (&$created): void {
                $created = $newUser;
            });
        }
        $em->expects(self::once())->method('flush');
        $em->expects(self::once())->method('clear');
        $mutes = $this->createMock(MutedPubkeysService::class);
        $mutes->expects(self::once())->method('invalidateCache')->willReturnCallback(static function () use (&$committed): void {
            self::assertTrue($committed);
        });

        (new AuthorBanService($em, $users, $mutes, new NullLogger()))->ban([$hex], 'spam', 'operator');

        $muted = $user ?? $created;
        self::assertSame($npub, $muted->getNpub());
        self::assertContains(RolesEnum::MUTED->value, $muted->getRoles());
        if ($existing) {
            self::assertContains(RolesEnum::WRITER->value, $muted->getRoles());
        }
    }

    public static function userProvider(): array
    {
        return ['existing' => [true], 'new' => [false]];
    }
}
