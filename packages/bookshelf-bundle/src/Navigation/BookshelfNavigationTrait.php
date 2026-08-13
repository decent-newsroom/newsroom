<?php

declare(strict_types=1);

namespace DecentNewsroom\BookshelfBundle\Navigation;

/**
 * Builds the local sidebar navigation for the Bookshelf's own routes.
 *
 * Bundle-owned: every route referenced here (`bookshelf`, `bookshelf_my_books`)
 * is defined by this bundle's own routing.
 */
trait BookshelfNavigationTrait
{
    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string, icon: string}>}>
     */
    protected function buildBookshelfNav(bool $isAuthenticated = false): array
    {
        return [
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
    }
}
