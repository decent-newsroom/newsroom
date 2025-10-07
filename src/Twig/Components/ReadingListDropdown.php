<?php

namespace App\Twig\Components;

use App\Service\ReadingListManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent]
final class ReadingListDropdown
{
    public string $coordinate = '';

    public function __construct(
        private readonly ReadingListManager $readingListManager,
        private readonly Security $security,
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
            $list['articles'] = $this->readingListManager->getArticleCoordinatesForList($list['slug']);
        }

        return $lists;
    }
}

