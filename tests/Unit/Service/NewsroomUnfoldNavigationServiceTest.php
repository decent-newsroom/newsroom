<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Event;
use App\Entity\PublicationSubdomainSubscription;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use App\Service\NewsroomUnfoldNavigationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class NewsroomUnfoldNavigationServiceTest extends TestCase
{
    public function testBuildsAdministrationLinksUsingPublicationTitles(): void
    {
        $subscription = new PublicationSubdomainSubscription(
            'npub1owner',
            'daily-news',
            '30040:owner-pubkey:daily-news',
        );
        $subscription->activate();

        $subscriptionRepository = $this->createMock(PublicationSubdomainSubscriptionRepository::class);
        $subscriptionRepository->expects(self::once())
            ->method('findActiveValidByNpub')
            ->with('npub1owner')
            ->willReturn([$subscription]);

        $publication = new Event();
        $publication->setId('publication-event');
        $publication->setKind(KindsEnum::PUBLICATION_INDEX->value);
        $publication->setPubkey('owner-pubkey');
        $publication->setContent('');
        $publication->setCreatedAt(1);
        $publication->setTags([['title', 'Daily News'], ['d', 'daily-news']]);
        $publication->setSig('signature');

        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository->expects(self::once())
            ->method('findOneBy')
            ->with(
                [
                    'kind' => KindsEnum::PUBLICATION_INDEX,
                    'pubkey' => 'owner-pubkey',
                    'dTag' => 'daily-news',
                ],
                ['created_at' => \SortDirection::Descending],
            )
            ->willReturn($publication);

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('unfold_admin_coordinate_overview', [
                'mag' => 'daily-news',
            ])
            ->willReturn('/mag/daily-news/admin');

        $service = new NewsroomUnfoldNavigationService(
            $subscriptionRepository,
            $eventRepository,
            $urlGenerator,
        );

        self::assertSame([
            [
                'label' => 'Daily News',
                'href' => '/mag/daily-news/admin',
                'icon' => 'iconoir:globe',
                'translate' => false,
            ],
        ], $service->forOwner('npub1owner'));
    }

    public function testReturnsNoLinksWithoutAnOwner(): void
    {
        $subscriptionRepository = $this->createMock(PublicationSubdomainSubscriptionRepository::class);
        $subscriptionRepository->expects(self::never())->method('findActiveValidByNpub');

        $service = new NewsroomUnfoldNavigationService(
            $subscriptionRepository,
            $this->createMock(EventRepository::class),
            $this->createMock(UrlGeneratorInterface::class),
        );

        self::assertSame([], $service->forOwner(null));
    }
}
