<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Creates the default publication robots response.
 */
final class RobotsService
{
    private const CACHE_CONTROL = 'public, max-age=600';

    public function __construct(
        private readonly PublicationUrlGenerator $urls,
    ) {
    }

    public function createResponse(): Response
    {
        $content = implode("\n", [
            'User-agent: *',
            'Allow: /',
            'Sitemap: ' . $this->urls->sitemap(),
            '',
        ]);

        return new Response(
            $content,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'Cache-Control' => self::CACHE_CONTROL,
            ],
        );
    }

    public function build(): Response
    {
        return $this->createResponse();
    }
}
