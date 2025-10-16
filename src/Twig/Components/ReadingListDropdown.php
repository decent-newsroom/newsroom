<?php

namespace App\Twig\Components;

use App\Service\ReadingListManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\Event; // Add this line

#[AsTwigComponent]
final class ReadingListDropdown
{
    public string $coordinate = '';

    public function __construct(
        private readonly ReadingListManager $readingListManager,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager, // Inject EntityManagerInterface
    ) {}

    public function getUserLists(): array
    {
        if (!$this->security->getUser()) {
            return [];
        }

        return $this->readingListManager->getUserReadingLists();
    }

    public function getListsWithArticles(): array
    {
        $lists = $this->getUserLists();

        // Fetch full article data for each list
        foreach ($lists as &$list) {
            $event = $this->entityManager->getRepository(Event::class)->find($list['id']); // Modify this line
            if ($event) {
                $list['eventJson'] = json_encode($event->getTags());
            }
            $list['articles'] = $this->readingListManager->getArticleCoordinatesForList($list['slug']);
        }

        return $lists;
    }
}
