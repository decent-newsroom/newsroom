<?php

namespace App\UnfoldBundle\Controller;

use App\UnfoldBundle\Config\SiteConfigLoader;
use App\UnfoldBundle\Content\ContentProvider;
use App\UnfoldBundle\Http\HostResolver;
use App\UnfoldBundle\Http\RouteMatcher;
use App\UnfoldBundle\Theme\ContextBuilder;
use App\UnfoldBundle\Theme\HandlebarsRenderer;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Main controller for Unfold site rendering
 */
class SiteController
{
    public function __construct(
        private readonly HostResolver $hostResolver,
        private readonly SiteConfigLoader $siteConfigLoader,
        private readonly ContentProvider $contentProvider,
        private readonly RouteMatcher $routeMatcher,
        private readonly ContextBuilder $contextBuilder,
        private readonly HandlebarsRenderer $renderer,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(Request $request): Response
    {
        // 1. Resolve host to UnfoldSite
        // First check if the request listener already resolved it
        $unfoldSite = $request->attributes->get('_unfold_site');

        // Fall back to HostResolver if not pre-resolved (for direct access)
        if ($unfoldSite === null) {
            $unfoldSite = $this->hostResolver->resolve();
        }

        if ($unfoldSite === null) {
            throw new NotFoundHttpException('Site not found for this subdomain');
        }

        // 2. Load SiteConfig from magazine coordinate (kind 30040)
        // SiteConfigLoader returns a placeholder config if fetch fails, so no exception handling needed
        $siteConfig = $this->siteConfigLoader->loadFromCoordinate($unfoldSite->getCoordinate());

        // Check if we got a placeholder config (content still loading)
        $isPlaceholder = $siteConfig->title === 'Loading...' || empty($siteConfig->pubkey);

        if ($isPlaceholder) {
            $this->logger->warning('Serving placeholder config - content may still be loading', [
                'subdomain' => $unfoldSite->getSubdomain(),
                'coordinate' => $unfoldSite->getCoordinate(),
            ]);
        }

        // 3. Set theme from SiteConfig (theme comes from AppData event)
        // $this->renderer->setTheme($siteConfig->theme);


        // 4. Get categories for route matching
        $categories = $this->contentProvider->getCategories($siteConfig);

        // 5. Match route
        $path = $request->getPathInfo();
        $route = $this->routeMatcher->match($path, $siteConfig, $categories);

        // Log route matching for debugging
        $this->logger->debug('Unfold route matched', [
            'subdomain' => $unfoldSite->getSubdomain(),
            'path' => $path,
            'route_type' => $route['type'],
            'categories_count' => count($categories),
            'site_title' => $siteConfig->title,
        ]);

        // 6. Build context and render based on page type
        return match ($route['type']) {
            RouteMatcher::PAGE_HOME => $this->renderHome($siteConfig, $categories),
            RouteMatcher::PAGE_CATEGORY => $this->renderCategory($siteConfig, $categories, $route),
            RouteMatcher::PAGE_POST => $this->renderPost($siteConfig, $categories, $route),
            RouteMatcher::PAGE_NOT_FOUND => throw new NotFoundHttpException('Page not found'),
            default => throw new NotFoundHttpException('Page not found'),
        };
    }

    private function renderHome($siteConfig, array $categories): Response
    {
        $posts = $this->contentProvider->getHomePosts($siteConfig);
        $context = $this->contextBuilder->buildHomeContext($siteConfig, $categories, $posts);

        $html = $this->renderer->render('index', $context);

        return new Response($html);
    }

    private function renderCategory($siteConfig, array $categories, array $route): Response
    {
        $category = $route['category'];
        $posts = $this->contentProvider->getCategoryPosts($category->coordinate);
        $context = $this->contextBuilder->buildCategoryContext($siteConfig, $categories, $category, $posts);

        $html = $this->renderer->render('category', $context);

        return new Response($html);
    }

    private function renderPost($siteConfig, array $categories, array $route): Response
    {
        $slug = $route['slug'];
        $post = $this->contentProvider->getPost($slug, $siteConfig);

        if ($post === null) {
            throw new NotFoundHttpException('Article not found');
        }

        $context = $this->contextBuilder->buildPostContext($siteConfig, $categories, $post);

        $html = $this->renderer->render('post', $context);

        return new Response($html);
    }
}

