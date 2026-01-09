<?php

namespace App\UnfoldBundle\Theme;

use LightnCandy\Flags;
use LightnCandy\LightnCandy;
use Psr\Log\LoggerInterface;

/**
 * Renders Handlebars templates using LightnCandy
 */
class HandlebarsRenderer
{
    private string $themesBasePath;
    private string $cachePath;
    private string $currentTheme = 'default';
    private array $compiledTemplates = [];

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $projectDir,
    ) {
        $this->themesBasePath = $projectDir . '/src/UnfoldBundle/Resources/themes';
        $this->cachePath = $projectDir . '/var/cache/unfold/templates';
    }

    /**
     * Set the current theme to use for rendering
     */
    public function setTheme(string $theme): void
    {
        if ($this->currentTheme !== $theme) {
            $this->currentTheme = $theme;
            $this->compiledTemplates = []; // Clear cache when theme changes
        }
    }

    /**
     * Get the current theme
     */
    public function getTheme(): string
    {
        return $this->currentTheme;
    }

    /**
     * Get list of available themes
     *
     * @return string[]
     */
    public function getAvailableThemes(): array
    {
        $themes = [];

        if (!is_dir($this->themesBasePath)) {
            return ['default'];
        }

        foreach (scandir($this->themesBasePath) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $themePath = $this->themesBasePath . '/' . $item;
            if (is_dir($themePath) && file_exists($themePath . '/index.hbs')) {
                $themes[] = $item;
            }
        }

        return $themes ?: ['default'];
    }

    /**
     * Get the current theme path
     */
    private function getThemePath(): string
    {
        return $this->themesBasePath . '/' . $this->currentTheme;
    }

    /**
     * Render a template with the given context
     */
    public function render(string $templateName, array $context, ?string $theme = null): string
    {
        if ($theme !== null) {
            $this->setTheme($theme);
        }

        // Add asset path prefix to context for runtime use
        $context['@assetPath'] = '/assets/themes/' . $this->currentTheme;

        $renderer = $this->getCompiledTemplate($templateName);

        try {
            return $renderer($context);
        } catch (\Throwable $e) {
            $this->logger->error('Error rendering template', [
                'template' => $templateName,
                'theme' => $this->currentTheme,
                'error' => $e->getMessage(),
            ]);

            // Return a basic error page
            return $this->renderError($templateName, $e->getMessage());
        }
    }

    /**
     * Get or compile a template
     */
    private function getCompiledTemplate(string $templateName): callable
    {
        $cacheKey = $this->currentTheme . '/' . $templateName;

        if (isset($this->compiledTemplates[$cacheKey])) {
            return $this->compiledTemplates[$cacheKey];
        }

        $templateFile = $this->getThemePath() . '/' . $templateName . '.hbs';
        $cacheFile = $this->cachePath . '/' . $this->currentTheme . '/' . $templateName . '.php';

        // Check if we need to recompile
        if ($this->needsRecompile($templateFile, $cacheFile)) {
            $this->compileTemplate($templateName, $templateFile, $cacheFile);
        }

        // Load the compiled template
        if (file_exists($cacheFile)) {
            $this->compiledTemplates[$cacheKey] = require $cacheFile;
        } else {
            // Fallback: compile in memory
            $this->compiledTemplates[$cacheKey] = $this->compileInMemory($templateFile);
        }

        return $this->compiledTemplates[$cacheKey];
    }

    /**
     * Check if template needs recompilation
     */
    private function needsRecompile(string $templateFile, string $cacheFile): bool
    {
        if (!file_exists($cacheFile)) {
            return true;
        }

        if (!file_exists($templateFile)) {
            return false;
        }

        return filemtime($templateFile) > filemtime($cacheFile);
    }

    /**
     * Compile a template and save to cache file
     */
    private function compileTemplate(string $templateName, string $templateFile, string $cacheFile): void
    {
        if (!file_exists($templateFile)) {
            $this->logger->warning('Template file not found, using fallback', [
                'template' => $templateName,
                'file' => $templateFile,
            ]);
            return;
        }

        $template = file_get_contents($templateFile);

        $phpCode = LightnCandy::compile($template, [
            'flags' => LightnCandy::FLAG_HANDLEBARS
                | LightnCandy::FLAG_ERROR_EXCEPTION
                | LightnCandy::FLAG_BESTPERFORMANCE
                | LightnCandy::FLAG_RUNTIMEPARTIAL,
            'partials' => $this->loadPartials(),
            'helpers' => $this->getHelpers(),
        ]);

        // Ensure cache directory exists
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // Wrap in a return statement for require
        $phpCode = '<?php return ' . $phpCode . ';';
        file_put_contents($cacheFile, $phpCode);

        $this->logger->debug('Compiled template', ['template' => $templateName]);
    }

    /**
     * Compile template in memory (fallback)
     */
    private function compileInMemory(string $templateFile): \Closure
    {
        if (!file_exists($templateFile)) {
            // Return a basic fallback renderer
            return fn(array $context) => $this->renderFallback($context);
        }

        $template = file_get_contents($templateFile);

        $phpCode = LightnCandy::compile($template, [
            'flags' => Flags::FLAG_HANDLEBARS
                | Flags::FLAG_ERROR_EXCEPTION
                | Flags::FLAG_RUNTIMEPARTIAL,
            'partials' => $this->loadPartials(),
            'helpers' => $this->getHelpers(),
        ]);

        $tmpFile = tempnam(sys_get_temp_dir(), 'lc_');
        file_put_contents($tmpFile, '<?php return ' . $phpCode . ';');
        $renderer = require $tmpFile;
        unlink($tmpFile);

        return $renderer;
    }

    /**
     * Load all partials from the theme
     */
    private function loadPartials(): array
    {
        $partialsDir = $this->getThemePath() . '/partials';
        $partials = [];

        if (!is_dir($partialsDir)) {
            return $partials;
        }

        foreach (glob($partialsDir . '/*.hbs') as $file) {
            $name = basename($file, '.hbs');
            $partials[$name] = file_get_contents($file);
        }

        return $partials;
    }

    /**
     * Get custom Handlebars helpers
     */
    private function getHelpers(): array
    {
        return [
            // Date formatting helper
            'date' => function ($date, $format = 'F j, Y') {
                if (is_numeric($date)) {
                    return date($format, $date);
                }
                return date($format, strtotime($date));
            },

            // URL helper
            'url' => function ($path) {
                return '/' . ltrim($path, '/');
            },

            // Asset URL helper - uses @assetPath from runtime context
            'asset' => function ($path, $options = null) {
                // Get asset path from context (passed in render method)
                $assetPath = $options['data']['root']['@assetPath'] ?? '/assets/themes/default';
                return $assetPath . '/' . ltrim($path, '/');
            },

            // Truncate helper
            'truncate' => function ($text, $length = 100) {
                if (strlen($text) <= $length) {
                    return $text;
                }
                return substr($text, 0, $length) . '...';
            },
        ];
    }

    /**
     * Render a basic fallback page
     */
    private function renderFallback(array $context): string
    {
        $site = $context['@site'] ?? [];
        $title = $site['title'] ?? 'Unfold Site';
        $posts = $context['posts'] ?? [];
        $post = $context['post'] ?? null;

        $html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title></head><body>';
        $html .= '<h1>' . htmlspecialchars($title) . '</h1>';

        if ($post) {
            $html .= '<article><h2>' . htmlspecialchars($post['title'] ?? '') . '</h2>';
            $html .= '<div>' . ($post['html'] ?? '') . '</div></article>';
        } elseif (!empty($posts)) {
            $html .= '<ul>';
            foreach ($posts as $p) {
                $html .= '<li><a href="' . htmlspecialchars($p['url'] ?? '') . '">' . htmlspecialchars($p['title'] ?? '') . '</a></li>';
            }
            $html .= '</ul>';
        }

        $html .= '</body></html>';

        return $html;
    }

    /**
     * Render error page
     */
    private function renderError(string $template, string $error): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Error</title></head><body>'
            . '<h1>Template Error</h1>'
            . '<p>Failed to render template: ' . htmlspecialchars($template) . '</p>'
            . '<pre>' . htmlspecialchars($error) . '</pre>'
            . '</body></html>';
    }

    /**
     * Clear template cache
     */
    public function clearCache(): void
    {
        $this->compiledTemplates = [];

        if (is_dir($this->cachePath)) {
            foreach (glob($this->cachePath . '/*.php') as $file) {
                unlink($file);
            }
        }

        $this->logger->info('Cleared template cache');
    }
}

