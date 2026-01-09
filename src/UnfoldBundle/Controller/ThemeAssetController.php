<?php

namespace App\UnfoldBundle\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Mime\MimeTypes;

/**
 * Serves static assets from theme directories
 */
class ThemeAssetController
{
    private readonly string $themesBasePath;
    private readonly MimeTypes $mimeTypes;

    public function __construct(
        private readonly string $projectDir,
    ) {
        $this->themesBasePath = $projectDir . '/src/UnfoldBundle/Resources/themes';
        $this->mimeTypes = new MimeTypes();
    }

    public function __invoke(Request $request, string $theme, string $path): Response
    {
        // Sanitize theme name - only allow alphanumeric, dash, underscore
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $theme)) {
            throw new NotFoundHttpException('Invalid theme name');
        }

        // Prevent directory traversal
        $path = ltrim($path, '/');
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            throw new NotFoundHttpException('Invalid path');
        }

        // Build full file path
        $filePath = $this->themesBasePath . '/' . $theme . '/assets/' . $path;

        // Verify file exists and is within theme directory
        $realPath = realpath($filePath);
        $realThemePath = realpath($this->themesBasePath . '/' . $theme);

        if ($realPath === false || $realThemePath === false) {
            throw new NotFoundHttpException('Asset not found');
        }

        if (!str_starts_with($realPath, $realThemePath)) {
            throw new NotFoundHttpException('Invalid asset path');
        }

        if (!is_file($realPath)) {
            throw new NotFoundHttpException('Asset not found');
        }

        // Determine content type
        $extension = pathinfo($realPath, PATHINFO_EXTENSION);
        $mimeType = $this->mimeTypes->getMimeTypes($extension)[0] ?? 'application/octet-stream';

        $response = new BinaryFileResponse($realPath);
        $response->headers->set('Content-Type', $mimeType);

        // Cache for 1 week in production
        $response->setMaxAge(604800);
        $response->setPublic();

        return $response;
    }
}

