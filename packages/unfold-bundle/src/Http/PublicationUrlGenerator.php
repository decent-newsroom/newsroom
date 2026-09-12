<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Http;

use DecentNewsroom\UnfoldBundle\Content\CategoryData;
use DecentNewsroom\UnfoldBundle\Content\PostData;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Builds canonical URLs on the host serving the current publication request.
 */
final class PublicationUrlGenerator
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function baseUrl(?Request $request = null): string
    {
        return rtrim(($request ?? $this->currentRequest())->getSchemeAndHttpHost(), '/');
    }

    public function absolute(string $path, ?Request $request = null): string
    {
        if (parse_url($path, PHP_URL_SCHEME) !== null) {
            return $path;
        }

        $request ??= $this->currentRequest();
        if (str_starts_with($path, '//')) {
            return $request->getScheme() . ':' . $path;
        }

        return $this->baseUrl($request) . '/' . ltrim($path, '/');
    }

    public function home(?Request $request = null): string
    {
        return $this->absolute('/', $request);
    }

    public function category(CategoryData|string $category, ?Request $request = null): string
    {
        $slug = $category instanceof CategoryData ? $category->slug : $category;

        return $this->absolute('/' . rawurlencode($slug), $request);
    }

    public function post(PostData|string $post, ?Request $request = null): string
    {
        $slug = $post instanceof PostData ? $post->slug : $post;

        return $this->absolute('/a/' . rawurlencode($slug), $request);
    }

    public function rss(?Request $request = null): string
    {
        return $this->absolute('/rss.xml', $request);
    }

    public function sitemap(?Request $request = null): string
    {
        return $this->absolute('/sitemap.xml', $request);
    }

    public function robots(?Request $request = null): string
    {
        return $this->absolute('/robots.txt', $request);
    }

    private function currentRequest(): Request
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            throw new \LogicException('A current request is required to build publication URLs.');
        }

        return $request;
    }
}
