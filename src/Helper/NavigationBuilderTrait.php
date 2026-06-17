<?php

declare(strict_types=1);

namespace App\Helper;

/**
 * Helper trait to generate navigation structures for layouts.
 *
 * Used by controllers to pass nav config to templates.
 */
trait NavigationBuilderTrait
{
    /**
     * Build the Reading Nook local navigation structure.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string}>}>
     */
    protected function buildReadingNookNav(): array
    {
        return [
            [
                'label' => 'reading_nook.nav.overview',
                'items' => [
                    ['label' => 'reading_nook.nav.all_items', 'route' => 'reading_nook'],
                ],
            ],
            [
                'label' => 'reading_nook.nav.saved',
                'items' => [
                    ['label' => 'reading_nook.nav.bookmarks', 'route' => 'my_bookmarks'],
                    ['label' => 'reading_nook.nav.interests', 'route' => 'my_interests'],
                ],
            ],
            [
                'label' => 'reading_nook.nav.collections',
                'items' => [
                    ['label' => 'reading_nook.nav.reading_lists', 'route' => 'reading_list_index'],
                    ['label' => 'reading_nook.nav.follow_packs', 'route' => 'follow_packs'],
                ],
            ],
        ];
    }

    /**
     * Build the Newsroom local navigation structure.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string}>}>
     */
    protected function buildNewsroomNav(): array
    {
        return [
            [
                'label' => 'newsroom.nav.overview',
                'items' => [
                    ['label' => 'newsroom.nav.my_content', 'route' => 'my_content'],
                ],
            ],
            [
                'label' => 'newsroom.nav.articles',
                'items' => [
                    ['label' => 'newsroom.nav.drafts', 'route' => 'my_content'],
                    ['label' => 'newsroom.nav.published', 'route' => 'my_content'],
                ],
            ],
            [
                'label' => 'newsroom.nav.publications',
                'items' => [
                    ['label' => 'newsroom.nav.magazines', 'route' => 'my_magazines'],
                    ['label' => 'newsroom.nav.reading_lists', 'route' => 'reading_list_index'],
                ],
            ],
            [
                'label' => 'newsroom.nav.media',
                'items' => [
                    ['label' => 'newsroom.nav.media_manager', 'route' => 'media_manager'],
                ],
            ],
        ];
    }

    /**
     * Build the main global navigation structure.
     *
     * @param bool|null $isAuthenticated If null, checks $this->getUser() (only works when used in AbstractController)
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string}>}>
     */
    protected function buildMainNav(?bool $isAuthenticated = null): array
    {
        if ($isAuthenticated === null) {
            // This method is only used in controller contexts where getUser() is available
            $isAuthenticated = method_exists($this, 'getUser') && $this->getUser() !== null;
        }

        $sections = [
            [
                'label' => '',
                'items' => [
                    ['label' => 'nav.discover', 'route' => 'discover'],
                ],
            ],
            [
                'label' => 'nav.publications',
                'items' => [
                    ['label' => 'nav.newsstand', 'route' => 'newsstand'],
                ],
            ],
        ];

        if ($isAuthenticated) {
            $sections[] = [
                'label' => 'nav.personal',
                'items' => [
                    ['label' => 'nav.readingNook', 'route' => 'reading_nook'],
                    ['label' => 'nav.newsroom', 'route' => 'my_content'],
                ],
            ];
            $sections[] = [
                'label' => 'nav.create',
                'items' => [
                    ['label' => 'nav.newMagazine', 'route' => 'mag_wizard_new'],
                    ['label' => 'nav.newReadingList', 'route' => 'reading_list_index'],
                    ['label' => 'nav.newArticle', 'route' => 'editor-create'],
                ],
            ];
        }

        return $sections;
    }
}


