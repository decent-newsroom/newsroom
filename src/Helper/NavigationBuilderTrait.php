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
                    ['label' => 'updates.manageSubscriptions', 'route' => 'updates_subscriptions', 'icon' => 'iconoir:rss-feed'],
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
                    ['label' => 'newsroom.nav.my_content', 'route' => 'my_content', 'icon' => 'iconoir:home'],
                ],
            ],
            [
                'label' => 'newsroom.nav.publications',
                'items' => [
                    ['label' => 'newsroom.nav.magazines', 'route' => 'my_magazines', 'icon' => 'iconoir:book-stack'],
                    ['label' => 'newsroom.nav.reading_lists', 'route' => 'reading_list_index', 'icon' => 'iconoir:journal-page'],
                ],
            ],
            [
                'label' => 'newsroom.nav.media',
                'items' => [
                    ['label' => 'newsroom.nav.media_manager', 'route' => 'media_manager', 'icon' => 'iconoir:compass'],
                ],
            ],
            [
                'label' => 'nav.create',
                'items' => [
                    ['label' => 'nav.newArticle', 'route' => 'editor-create', 'icon' => 'iconoir:edit-pencil'],
                    ['label' => 'nav.newMagazine', 'route' => 'mag_wizard_new', 'icon' => 'iconoir:plus'],
                    ['label' => 'nav.newReadingList', 'route' => 'read_wizard_new', 'icon' => 'iconoir:journal-page'],
                ],
            ],
        ];
    }

    /**
     * Build the Expressions local navigation structure.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string}>}>
     */
    protected function buildExpressionsNav(): array
    {
        return [
            [
                'label' => 'expressions.workspace.nav.overview',
                'items' => [
                    ['label' => 'expressions.workspace.nav.workspace', 'route' => 'expressions_workspace', 'icon' => 'iconoir:home'],
                    ['label' => 'expressions.workspace.nav.create_expression', 'route' => 'expression_create', 'icon' => 'iconoir:edit-pencil'],
                ],
            ],
            [
                'label' => 'expressions.workspace.nav.feed_testing',
                'items' => [
                    ['label' => 'expressions.workspace.nav.expressions', 'route' => 'expression_list', 'icon' => 'iconoir:list-select'],
                    ['label' => 'expressions.workspace.nav.spells', 'route' => 'spell_list', 'icon' => 'iconoir:magic-wand'],
                ],
            ],
        ];
    }

    /**
     * Build the Mercury-backed Bookshelf navigation structure.
     *
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string, icon: string}>}>
     */
    protected function buildBookshelfNav(bool $isAuthenticated = false): array
    {
        $sections = [
            [
                'label' => 'bookshelf.nav.library',
                'items' => [
                    ['label' => 'bookshelf.nav.search', 'route' => 'bookshelf', 'icon' => 'iconoir:search'],
                    ...($isAuthenticated ? [[
                        'label' => 'bookshelf.nav.my_books',
                        'route' => 'bookshelf_my_books',
                        'icon' => 'iconoir:bookmark-book',
                    ]] : []),
                ],
            ],
        ];

        return $sections;
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
                    ['label' => 'nav.bookshelf', 'route' => 'bookshelf'],
                ],
            ],
        ];

        if ($isAuthenticated) {
            $sections[] = [
                'label' => 'nav.personal',
                'items' => [
                    ['label' => 'nav.readingNook', 'route' => 'reading_nook'],
                    ['label' => 'nav.newsroom', 'route' => 'my_content'],
                    ['label' => 'nav.expressions', 'route' => 'expressions_workspace'],
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
