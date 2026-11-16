<?php

declare(strict_types=1);

namespace App\Tests\Unit\Helper;

use App\Helper\NavigationBuilderTrait;
use PHPUnit\Framework\TestCase;

final class NavigationBuilderTraitTest extends TestCase
{
    public function testNewsroomHasOneLandingLinkAndKeepsWorkspaceActions(): void
    {
        $builder = new class {
            use NavigationBuilderTrait;

            public function newsroom(array $unfoldItems): array
            {
                return $this->buildNewsroomNav($unfoldItems);
            }

            public function main(): array
            {
                return $this->buildMainNav();
            }

            public function readingNook(): array
            {
                return $this->buildReadingNookNav();
            }
        };
        $unfoldItem = [
            'label' => 'Publication', 'href' => '/publication/admin',
            'icon' => 'iconoir:post', 'translate' => false,
        ];
        foreach ([$builder->main(), $builder->readingNook(), $builder->newsroom([]), $builder->newsroom([$unfoldItem])] as $navigation) {
            $items = array_merge(...array_column($navigation, 'items'));
            $landing = array_values(array_filter($items, static fn (array $item): bool => ($item['route'] ?? null) === 'my_content'));
            self::assertCount(1, $landing);
            self::assertSame('nav.newsroom', $landing[0]['label']);
        }

        foreach ([[], [$unfoldItem]] as $unfoldItems) {
            $navigation = $builder->newsroom($unfoldItems);
            $items = array_merge(...array_column($navigation, 'items'));
            $routes = array_column($items, 'route');
            foreach (['my_magazines', 'reading_list_index', 'media_manager', 'editor-create', 'mag_wizard_new', 'read_wizard_new'] as $route) {
                self::assertContains($route, $routes);
            }
            self::assertNotContains('newsroom.nav.overview', array_column($navigation, 'label'));
            if ($unfoldItems !== []) {
                self::assertContains($unfoldItem, $items);
            }
        }
    }
}
