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
        $unfoldSite = $this->hostResolver->resolve();

        if ($unfoldSite === null) {
            throw new NotFoundHttpException('Site not found for this subdomain');
        }

        // 2. Load SiteConfig directly from magazine naddr (kind 30040)
        try {
            $siteConfig = $this->siteConfigLoader->loadFromMagazine($unfoldSite->getNaddr());
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            $this->logger->error('Failed to load site config', [
                'subdomain' => $unfoldSite->getSubdomain(),
                'naddr' => $unfoldSite->getNaddr(),
                'error' => $e->getMessage(),
            ]);
            throw new NotFoundHttpException('Site configuration could not be loaded');
        }

        // 3. Set theme from SiteConfig (theme comes from AppData event)
        // $this->renderer->setTheme($siteConfig->theme);


        // 4. Get categories for route matching
        $categories = $this->contentProvider->getCategories($siteConfig);

        // 5. Match route
        $path = $request->getPathInfo();
        $route = $this->routeMatcher->match($path, $siteConfig, $categories);

        // 6. Build context and render based on page type
        return match ($route['type']) {
            RouteMatcher::PAGE_HOME => $this->renderHome($siteConfig, $categories),
            RouteMatcher::PAGE_CATEGORY => $this->renderCategory($siteConfig, $categories, $route),
            RouteMatcher::PAGE_POST => $this->renderPost($siteConfig, $categories, $route),
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

