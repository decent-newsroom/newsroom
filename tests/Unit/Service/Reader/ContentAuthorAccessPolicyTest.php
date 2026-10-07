<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Repository\BannedPubkeyRepository;
use App\Repository\UserEntityRepository;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ContentAuthorAccessPolicyTest extends TestCase
{
    private const HEX = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @dataProvider mutedIdentifiers */
    public function testAdminMutedAuthorReturnsGeneric404(string $identifier): void
    {
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::once())->method('isAdminMuted')
            ->with((string) Bech32::npub(self::HEX))->willReturn(true);
        $bans = $this->createMock(BannedPubkeyRepository::class);
        $bans->expects(self::once())->method('isBanned')->with(self::HEX)->willReturn(false);
        $policy = new ContentAuthorAccessPolicy($users, $bans, new NostrKeyService(), new NullLogger());

        try {
            $policy->assertReadable($identifier);
            self::fail('Suppressed author must not be readable.');
        } catch (NotFoundHttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
            self::assertSame('Content not found.', $exception->getMessage());
        }
    }

    public static function mutedIdentifiers(): iterable
    {
        yield 'hex' => [self::HEX];
        yield 'uppercase hex' => [strtoupper(self::HEX)];
        $npub = (string) Bech32::npub(self::HEX);
        yield 'npub' => [$npub];
        yield 'nostr npub' => ['nostr:' . $npub];
        yield 'nprofile' => [(string) Bech32::nprofile(pubkey: self::HEX)];
    }

    public function testPermanentBansAreCheckedFreshEvenWithoutMuteRole(): void
    {
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::once())->method('isAdminMuted')->willReturn(false);
        $bans = $this->createMock(BannedPubkeyRepository::class);
        $bans->expects(self::exactly(2))->method('isBanned')->with(self::HEX)
            ->willReturnOnConsecutiveCalls(false, true);
        $policy = new ContentAuthorAccessPolicy($users, $bans, new NostrKeyService(), new NullLogger());

        $policy->assertReadable(self::HEX);
        $this->expectException(NotFoundHttpException::class);
        $policy->assertReadable(self::HEX);
    }

    public function testMuteChangesAreNotHiddenByTheExistingMuteCache(): void
    {
        $users = $this->createMock(UserEntityRepository::class);
        $users->expects(self::exactly(3))->method('isAdminMuted')->willReturnOnConsecutiveCalls(false, true, false);
        $bans = $this->createMock(BannedPubkeyRepository::class);
        $bans->method('isBanned')->willReturn(false);
        $policy = new ContentAuthorAccessPolicy($users, $bans, new NostrKeyService(), new NullLogger());

        self::assertFalse($policy->isSuppressed(self::HEX));
        self::assertTrue($policy->isSuppressed(self::HEX));
        self::assertFalse($policy->isSuppressed(self::HEX));
    }

    public function testLookupFailuresDoNotAllowContentAccess(): void
    {
        $users = $this->createMock(UserEntityRepository::class);
        $users->method('isAdminMuted')->willThrowException(new \RuntimeException('Database unavailable'));
        $bans = $this->createMock(BannedPubkeyRepository::class);
        $bans->method('isBanned')->willReturn(false);
        $policy = new ContentAuthorAccessPolicy($users, $bans, new NostrKeyService(), new NullLogger());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Database unavailable');
        $policy->assertReadable(self::HEX);
    }
}
