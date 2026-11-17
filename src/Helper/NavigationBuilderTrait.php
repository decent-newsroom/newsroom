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
     * Stable destinations shared by the reader and publishing workspaces.
     */
    protected function buildWorkspaceDestinations(): array
    {
        return [
            'label' => '',
            'items' => [
                ['label' => 'nav.discover', 'route' => 'home', 'icon' => 'iconoir:compass'],
                ['label' => 'nav.readingNook', 'route' => 'reading_nook', 'icon' => 'iconoir:bookmark'],
                ['label' => 'subscription_feed.title', 'route' => 'updates_index', 'icon' => 'iconoir:rss-feed'],
                ['label' => 'nav.newsroom', 'route' => 'my_content', 'icon' => 'iconoir:home'],
            ],
        ];
    }

    /**
     * Publication-local links work on both the host and coordinate admin mounts.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, href: string, active: bool, icon: string}>}>
     */
    protected function buildUnfoldAdminNav(string $adminPathPrefix, string $currentPath): array
    {
        $prefix = rtrim($adminPathPrefix, '/');
        $currentPath = rtrim($currentPath, '/');

        return [[
            'label' => 'unfold_admin.title',
            'items' => [
                [
                    'label' => 'unfold_admin.overview',
                    'href' => $prefix,
                    'active' => $currentPath === $prefix,
                    'icon' => 'iconoir:home',
                ],
                [
                    'label' => 'unfold_admin.content',
                    'href' => $prefix . '/content',
                    'active' => $currentPath === $prefix . '/content',
                    'icon' => 'iconoir:journal-page',
                ],
                [
                    'label' => 'unfold_admin.settings',
                    'href' => $prefix . '/settings',
                    'active' => $currentPath === $prefix . '/settings',
                    'icon' => 'iconoir:settings',
                ],
            ],
        ]];
    }

    /**
     * Build the Reading Nook local navigation structure.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string}>}>
     */
    protected function buildReadingNookNav(): array
    {
        return [
            $this->buildWorkspaceDestinations(),
            [
                'label' => 'reading_nook.nav.overview',
                'items' => [
                    ['label' => 'reading_nook.nav.all_items', 'route' => 'reading_nook', 'icon' => 'iconoir:home'],
                ],
            ],
            [
                'label' => 'reading_nook.nav.saved',
                'items' => [
                    ['label' => 'reading_nook.nav.bookmarks', 'route' => 'my_bookmarks', 'icon' => 'iconoir:bookmark'],
                    ['label' => 'reading_nook.nav.interests', 'route' => 'my_interests', 'icon' => 'iconoir:compass'],
                ],
            ],
            [
                'label' => 'reading_nook.nav.collections',
                'items' => [
                    ['label' => 'reading_nook.nav.reading_lists', 'route' => 'reading_list_index', 'icon' => 'iconoir:journal-page'],
                    ['label' => 'reading_nook.nav.follow_packs', 'route' => 'follow_packs', 'icon' => 'iconoir:community'],
                ],
            ],
            [
                'label' => 'updates.pageTitle',
                'items' => [
                    ['label' => 'subscription_feed.feed', 'route' => 'updates_index', 'icon' => 'iconoir:rss-feed'],
                    ['label' => 'updates.manageSubscriptions', 'route' => 'updates_subscriptions', 'icon' => 'iconoir:rss-feed'],
                ],
            ],
        ];
    }

    /**
     * Build the Newsroom local navigation structure.
     *
     * @param array<int, array{label: string, href: string, icon: string, translate: bool}> $unfoldItems
     * @return array<int, array{label: string, items: array<int, array{label: string, route?: string, href?: string, icon: string, translate?: bool}>}>
     */
    protected function buildNewsroomNav(array $unfoldItems = []): array
    {
        $sections = [
            $this->buildWorkspaceDestinations(),
            [
                'label' => 'newsroom.nav.publications',
                'items' => [
                    ['label' => 'newsroom.nav.magazines', 'route' => 'my_magazines', 'icon' => 'iconoir:book-stack'],
                    ['label' => 'newsroom.nav.reading_lists', 'route' => 'reading_list_index', 'icon' => 'iconoir:journal-page'],
                ],
            ],
        ];

        if ($unfoldItems !== []) {
            $sections[] = [
                'label' => 'newsroom.nav.unfold',
                'items' => $unfoldItems,
            ];
        }

        $sections[] = [
            'label' => 'newsroom.nav.media',
            'items' => [
                ['label' => 'newsroom.nav.media_manager', 'route' => 'media_manager', 'icon' => 'iconoir:compass'],
            ],
        ];
        $sections[] = [
            'label' => 'nav.create',
            'items' => [
                ['label' => 'nav.newArticle', 'route' => 'editor-create', 'icon' => 'iconoir:edit-pencil'],
                ['label' => 'nav.newMagazine', 'route' => 'mag_wizard_new', 'icon' => 'iconoir:plus'],
                ['label' => 'nav.newReadingList', 'route' => 'read_wizard_new', 'icon' => 'iconoir:journal-page'],
            ],
        ];

        return $sections;
    }

    /**
     * Build the main global navigation structure.
     *
     * @param bool|null $isAuthenticated Retained for callers; destinations remain visible before sign-in.
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string}>}>
     */
    protected function buildMainNav(?bool $isAuthenticated = null): array
    {
        $sections = [
            $this->buildWorkspaceDestinations(),
            [
                'label' => 'nav.publications',
                'items' => [
                    ['label' => 'nav.newsstand', 'route' => 'newsstand', 'icon' => 'iconoir:book-stack'],
                    ['label' => 'nav.bookshelf', 'route' => 'bookshelf', 'icon' => 'iconoir:bookmark-book'],
                ],
            ],
            [
                'label' => 'nav.create',
                'items' => [
                    ['label' => 'nav.newMagazine', 'route' => 'mag_wizard_new', 'icon' => 'iconoir:plus'],
                    ['label' => 'nav.newReadingList', 'route' => 'read_wizard_new', 'icon' => 'iconoir:journal-page'],
                    ['label' => 'nav.newArticle', 'route' => 'editor-create', 'icon' => 'iconoir:edit-pencil'],
                ],
            ],
        ];

        return $sections;
    }
}
