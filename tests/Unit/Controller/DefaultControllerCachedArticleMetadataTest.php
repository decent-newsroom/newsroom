<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\DefaultController;
use App\Repository\EventRepository;
use App\Service\Cache\RedisViewStore;
use App\Service\LatestArticles\LatestArticlesExclusionPolicy;
use App\Service\Search\ArticleSearchFactory;
use App\Service\UserMuteListService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

final class DefaultControllerCachedArticleMetadataTest extends TestCase
{
    private const AUTHOR = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testLatestArticlesUsesCachedProfileWhenMetadataEventIsMissing(): void
    {
        $article = ['pubkey' => self::AUTHOR, 'slug' => 'article', 'title' => 'Cached article'];
        $profile = ['pubkey' => self::AUTHOR, 'name' => 'Cached writer', 'display_name' => 'Cached writer'];
        $views = $this->createMock(RedisViewStore::class);
        $views->expects(self::once())->method('fetchLatestArticles')->willReturn([[
            'article' => $article,
            'author' => null,
            'profiles' => [self::AUTHOR => $profile],
        ]]);
        $events = $this->createMock(EventRepository::class);
        $events->expects(self::once())->method('findLatestMetadataByPubkeys')
            ->with([self::AUTHOR])->willReturn([]);
        $exclusionPolicy = $this->createMock(LatestArticlesExclusionPolicy::class);
        $exclusionPolicy->expects(self::once())->method('getAllExcludedPubkeys')->willReturn([]);
        $exclusionPolicy->expects(self::once())->method('shouldExcludeArticleData')
            ->with($article, self::callback(static function (\App\Dto\UserMetadata $metadata): bool {
                return $metadata->name === 'Cached writer' && $metadata->displayName === 'Cached writer';
            }))
            ->willReturn(false);

        $controller = new class extends DefaultController {
            /** @var array<string, mixed> */
            public array $parameters = [];

            /** @param array<string, mixed> $parameters */
            public function render(string $view, array $parameters = [], ?Response $response = null): Response
            {
                $this->parameters = $parameters;
                return $response ?? new Response();
            }

            protected function getUser(): ?UserInterface
            {
                return null;
            }
        };
        $response = $controller->latestArticles(
            $views,
            $exclusionPolicy,
            $this->createMock(ArticleSearchFactory::class),
            $events,
            $this->createMock(UserMuteListService::class),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $controller->parameters['articles']);
        self::assertSame('Cached writer', $controller->parameters['authorsMetadata'][self::AUTHOR]->name);
    }
}
