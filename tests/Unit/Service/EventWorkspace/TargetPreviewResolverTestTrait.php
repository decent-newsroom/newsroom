<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\EventWorkspace;

use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Symfony\Bundle\SecurityBundle\Security;

/** Source characterization tests do not authorize any target metadata. */
trait TargetPreviewResolverTestTrait
{
    private function targetResolver(): TargetPreviewResolver
    {
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::never())->method('findWorkspacePreviewTargets');

        return new TargetPreviewResolver(
            $events,
            $this->createMock(DeletedEventRepository::class),
            $this->createMock(HiddenCoordinateRepository::class),
            $this->createMock(ContentAuthorAccessPolicy::class),
            new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service()),
            $this->createMock(Security::class),
            $this->createMock(UserMuteListService::class),
        );
    }
}
