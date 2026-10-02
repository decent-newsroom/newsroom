<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PublicationSubdomainSubscription;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Repository\PublicationSubdomainSubscriptionRepository;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class NewsroomUnfoldNavigationService
{
    public function __construct(
        private PublicationSubdomainSubscriptionRepository $subscriptionRepository,
        private EventRepository $eventRepository,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @return array<int, array{label: string, href: string, icon: string, translate: bool}>
     */
    public function forOwner(?string $npub): array
    {
        if ($npub === null || $npub === '') {
            return [];
        }

        $items = [];
        foreach ($this->subscriptionRepository->findActiveValidByNpub($npub) as $subscription) {
            $items[] = [
                'label' => $this->titleFor($subscription),
                'href' => $this->urlGenerator->generate('unfold_admin_coordinate_overview', [
                    'mag' => $this->identifierFor($subscription),
                ]),
                'icon' => 'iconoir:globe',
                'translate' => false,
            ];
        }

        return $items;
    }

    private function titleFor(PublicationSubdomainSubscription $subscription): string
    {
        $coordinate = explode(':', $subscription->getMagazineCoordinate(), 3);
        if (count($coordinate) !== 3 || (int) $coordinate[0] !== KindsEnum::PUBLICATION_INDEX->value) {
            return $subscription->getSubdomain();
        }

        [, $pubkey, $identifier] = $coordinate;
        $publication = $this->eventRepository->findOneBy(
            [
                'kind' => KindsEnum::PUBLICATION_INDEX,
                'pubkey' => $pubkey,
                'dTag' => $identifier,
            ],
            ['created_at' => \SortDirection::Descending],
        );

        return $publication?->getTitle() ?: $identifier;
    }

    private function identifierFor(PublicationSubdomainSubscription $subscription): string
    {
        $coordinate = explode(':', $subscription->getMagazineCoordinate(), 3);

        return $coordinate[2] ?? $subscription->getSubdomain();
    }
}
