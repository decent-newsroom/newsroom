<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Entity\Article;
use App\Enum\RolesEnum;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Reader\ArticleAccessService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\UserInterface;

final class ArticleAccessServiceTest extends TestCase
{
    private const HEX = '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2';

    public function testEssayistMemberCanViewExclusiveArticleWithoutAuthorMatch(): void
    {
        $service = new ArticleAccessService($this->identityService());

        self::assertTrue($service->canViewEssayistExclusive(
            $this->viewer(str_repeat('f', 64), [RolesEnum::ESSAYIST_MEMBER->value]),
            (new Article())->setPubkey(self::HEX),
        ));
    }

    public function testArticleAuthorCanViewAndEditExclusiveArticle(): void
    {
        $service = new ArticleAccessService($this->identityService());
        $article = (new Article())->setPubkey(self::HEX);
        $viewer = $this->viewer(self::HEX);

        self::assertTrue($service->canViewEssayistExclusive($viewer, $article));
        self::assertTrue($service->canEdit($viewer, $article));
    }

    public function testAnonymousViewerCannotViewExclusiveArticle(): void
    {
        $service = new ArticleAccessService($this->identityService());

        self::assertFalse($service->canViewEssayistExclusive(null, (new Article())->setPubkey(self::HEX)));
    }

    private function identityService(): NostrIdentityService
    {
        return new NostrIdentityService(
            $this->createMock(Nip19DecoderInterface::class),
            $this->createMock(Nip19EncoderInterface::class),
        );
    }

    /** @param string[] $roles */
    private function viewer(string $identifier, array $roles = []): UserInterface
    {
        return new class($identifier, $roles) implements UserInterface {
            /** @param string[] $roles */
            public function __construct(
                private readonly string $identifier,
                private readonly array $roles,
            ) {
            }

            public function getRoles(): array
            {
                return $this->roles;
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }
}