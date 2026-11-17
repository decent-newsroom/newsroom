<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Repository\HighlightRepository;
use App\Service\EventWorkspace\AuthoredArticlePreview;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrEventVerifier;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrSigner;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\Reader\HighlightSourceVisibility;
use App\Service\UserMuteListService;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use Innis\Nostr\Core\Domain\Service\SignatureServiceInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;

trait HighlightSourceVisibilityFactory
{
    private function makeHighlightVisibility(
        EventRepository $events,
        ?HighlightRepository $highlights = null,
        ?ArticleRepository $articles = null,
        ?Security $security = null,
        ?EventPreviewAccessPolicy $access = null,
        ?NostrEventVerifier $verifier = null,
        ?LoggerInterface $logger = null,
    ): HighlightSourceVisibility {
        $security ??= $this->createMock(Security::class);
        $logger ??= new NullLogger();
        $nip19 = new NostrNip19Service();

        return new HighlightSourceVisibility(
            $events,
            $highlights ?? $this->createMock(HighlightRepository::class),
            $articles ?? $this->createMock(ArticleRepository::class),
            $security,
            $nip19,
            $logger,
            $access ?? $this->makeHighlightPreviewAccess($security, logger: $logger),
            new AuthoredArticlePreview(new EventPresentationFactory(new RecordIdentityService(), $nip19)),
            $verifier ?? $this->makeHighlightVerifier(),
        );
    }

    private function makeHighlightPreviewAccess(
        Security $security,
        ?ContentAuthorAccessPolicy $authors = null,
        ?DeletedEventRepository $deletions = null,
        ?HiddenCoordinateRepository $hidden = null,
        ?LoggerInterface $logger = null,
    ): EventPreviewAccessPolicy {
        return new EventPreviewAccessPolicy(
            $authors ?? $this->createMock(ContentAuthorAccessPolicy::class),
            $deletions ?? $this->createMock(DeletedEventRepository::class),
            $hidden ?? $this->createMock(HiddenCoordinateRepository::class),
            new RecordIdentityService(),
            $security,
            $this->createMock(UserMuteListService::class),
            new NostrKeyService(),
            $logger ?? new NullLogger(),
            new ArticleAccessService(new NostrIdentityService(
                $this->createMock(Nip19DecoderInterface::class),
                $this->createMock(Nip19EncoderInterface::class),
            )),
        );
    }

    private function makeHighlightVerifier(bool $valid = true): NostrEventVerifier
    {
        $signatures = $this->createMock(SignatureServiceInterface::class);
        $signatures->method('verify')->willReturn($valid);

        return new NostrEventVerifier(new NostrSigner($signatures));
    }
}
