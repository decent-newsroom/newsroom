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

        // LightnCandy expects @ variables in both the context and also accessible via 'site' for compatibility
        // Ensure site data is accessible both ways
        if (isset($context['@site'])) {
            $context['site'] = $context['@site'];
        }

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
            // Use static array to prevent re-declaring functions
            static $loadedTemplates = [];
            if (!isset($loadedTemplates[$cacheFile])) {
                $loadedTemplates[$cacheFile] = require $cacheFile;
            }
            $this->compiledTemplates[$cacheKey] = $loadedTemplates[$cacheFile];
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
                | LightnCandy::FLAG_RUNTIMEPARTIAL
                | LightnCandy::FLAG_ADVARNAME,  // Support @site, @custom, etc.
            'partials' => $this->loadPartials(),
            'helpers' => $this->getHelpers(),
        ]);

        // Ensure cache directory exists
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        // LightnCandy output includes 'use' statements and 'return function...'
        // so we just need to wrap it in <?php
        $phpCode = '<?php ' . $phpCode;
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
                | Flags::FLAG_RUNTIMEPARTIAL
                | Flags::FLAG_ADVARNAME,  // Support @site, @custom, etc.
            'partials' => $this->loadPartials(),
            'helpers' => $this->getHelpers(),
        ]);

        // Use temp file to evaluate the compiled code
        $tmpFile = tempnam(sys_get_temp_dir(), 'lc_');
        file_put_contents($tmpFile, '<?php ' . $phpCode);
        $renderer = require $tmpFile;
        unlink($tmpFile);

        return $renderer;
    }

    /**
     * Load all partials from the theme (including nested directories)
     */
    private function loadPartials(): array
    {
        $partialsDir = $this->getThemePath() . '/partials';
        $partials = [];

        if (!is_dir($partialsDir)) {
            return $partials;
        }

        // Load partials recursively
        $this->loadPartialsRecursive($partialsDir, '', $partials);

        return $partials;
    }

    /**
     * Recursively load partials from directory
     */
    private function loadPartialsRecursive(string $dir, string $prefix, array &$partials): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir . '/' . $item;

            if (is_dir($path)) {
                // Recurse into subdirectory
                $newPrefix = $prefix ? $prefix . '/' . $item : $item;
                $this->loadPartialsRecursive($path, $newPrefix, $partials);
            } elseif (str_ends_with($item, '.hbs')) {
                // Load partial file
                $name = $prefix ? $prefix . '/' . basename($item, '.hbs') : basename($item, '.hbs');
                $partials[$name] = file_get_contents($path);
            }
        }
    }

    /**
     * Get custom Handlebars helpers
     */
    private function getHelpers(): array
    {
        return [
            // Date formatting helper
            'date' => function ($date, $options = null) {
                $format = 'F j, Y';
                if (is_array($options) && isset($options['hash']['format'])) {
                    $format = $options['hash']['format'];
                }
                if (is_numeric($date)) {
                    return date($format, $date);
                }
                return date($format, strtotime($date ?? 'now'));
            },


            // Asset URL helper - uses @assetPath from runtime context
            'asset' => function ($path, $options = null) {
                $assetPath = $options['data']['root']['@assetPath'] ?? '/assets/themes/default';
                return $assetPath . '/' . ltrim($path ?? '', '/');
            },

            // Truncate helper
            'truncate' => function ($text, $length = 100) {
                if (strlen($text ?? '') <= $length) {
                    return $text;
                }
                return substr($text, 0, $length) . '...';
            },

            // Ghost 'match' block helper - compares values
            'match' => function ($val1, $operator = null, $val2 = null, $options = null) {
                // Handle different argument patterns
                if (is_array($operator) && isset($operator['fn'])) {
                    // match value "string" - implicit equals
                    $options = $operator;
                    $val2 = $val1;
                    $val1 = $options['hash']['value'] ?? '';
                    $operator = '=';
                } elseif (is_array($val2) && isset($val2['fn'])) {
                    // match val1 val2 - implicit equals
                    $options = $val2;
                    $val2 = $operator;
                    $operator = '=';
                } elseif (is_array($options) && !isset($options['fn'])) {
                    // Rearrange if options got mixed up
                    $options = $val2;
                    $val2 = $operator;
                    $operator = '=';
                }

                $result = match ($operator) {
                    '=', '==' => $val1 == $val2,
                    '!=' => $val1 != $val2,
                    '>' => $val1 > $val2,
                    '<' => $val1 < $val2,
                    '>=' => $val1 >= $val2,
                    '<=' => $val1 <= $val2,
                    default => $val1 == $operator, // implicit equals with second arg
                };

                if (is_array($options)) {
                    if ($result && isset($options['fn'])) {
                        return $options['fn']($options['data']['root'] ?? []);
                    } elseif (!$result && isset($options['inverse'])) {
                        return $options['inverse']($options['data']['root'] ?? []);
                    }
                }
                return '';
            },

            // Ghost 'foreach' helper - iterate with @index, @first, @last
            'foreach' => function ($items, $options = null) {
                if (!is_array($items) || !is_array($options) || !isset($options['fn'])) {
                    return '';
                }

                $result = '';
                $count = count($items);
                $index = 0;

                foreach ($items as $key => $item) {
                    $data = $options['data'] ?? [];
                    $data['index'] = $index;
                    $data['first'] = ($index === 0);
                    $data['last'] = ($index === $count - 1);
                    $data['key'] = $key;

                    $itemContext = is_array($item) ? $item : ['this' => $item];
                    $itemContext['@index'] = $index;
                    $itemContext['@first'] = ($index === 0);
                    $itemContext['@last'] = ($index === $count - 1);

                    $result .= $options['fn']($itemContext, ['data' => $data]);
                    $index++;
                }

                return $result;
            },

            // Ghost 'is' helper - check page type
            'is' => function ($types, $options = null) {
                if (!is_array($options) || !isset($options['fn'])) {
                    return '';
                }

                $currentType = $options['data']['root']['@pageType'] ?? 'home';
                $typeList = array_map('trim', explode(',', $types ?? ''));

                if (in_array($currentType, $typeList)) {
                    return $options['fn']($options['data']['root'] ?? []);
                } elseif (isset($options['inverse'])) {
                    return $options['inverse']($options['data']['root'] ?? []);
                }
                return '';
            },

            // Ghost 'has' helper - check for properties
            'has' => function ($options = null) {
                if (!is_array($options) || !isset($options['fn'])) {
                    return '';
                }

                $hash = $options['hash'] ?? [];
                $context = $options['data']['root'] ?? [];

                // Check various conditions
                $match = false;
                if (isset($hash['index'])) {
                    $currentIndex = $context['@index'] ?? 0;
                    $indices = array_map('trim', explode(',', $hash['index']));
                    $match = in_array((string)$currentIndex, $indices);
                } elseif (isset($hash['visibility'])) {
                    $match = false; // Default: no visibility restrictions
                } else {
                    $match = true; // Default true if no conditions
                }

                if ($match) {
                    return $options['fn']($context);
                } elseif (isset($options['inverse'])) {
                    return $options['inverse']($context);
                }
                return '';
            },

            // Ghost 'get' helper - fetch data (returns empty for now)
            'get' => function ($resource = null, $options = null) {
                // This would need to fetch data from Nostr - return empty for now
                return '';
            },

            // Ghost 'navigation' helper - render navigation
            'navigation' => function ($options = null) {
                if (!is_array($options)) {
                    return '';
                }

                $type = $options['hash']['type'] ?? 'primary';
                // Check both @site and site for LightnCandy compatibility
                $navigation = $options['data']['root']['@site']['navigation']
                    ?? $options['data']['root']['site']['navigation']
                    ?? [];

                $html = '';
                foreach ($navigation as $item) {
                    $html .= '<li class="nav-' . htmlspecialchars($item['slug'] ?? '') . '">';
                    $html .= '<a href="' . htmlspecialchars($item['url'] ?? '') . '">';
                    $html .= htmlspecialchars($item['label'] ?? '');
                    $html .= '</a></li>';
                }
                // Return SafeString to prevent double-escaping
                return new \LightnCandy\SafeString($html);
            },

            // Ghost 'ghost_head' helper - head meta/scripts
            'ghost_head' => function ($options = null) {
                return '<!-- ghost_head placeholder -->';
            },

            // Ghost 'ghost_foot' helper - footer scripts
            'ghost_foot' => function ($options = null) {
                return '<!-- ghost_foot placeholder -->';
            },

            // Ghost 'body_class' helper
            'body_class' => function ($options = null) {
                $pageType = $options['data']['root']['@pageType'] ?? 'home';
                return $pageType . '-template';
            },

            // Ghost 'meta_title' helper
            'meta_title' => function ($options = null) {
                $site = $options['data']['root']['@site'] ?? [];
                $post = $options['data']['root']['post'] ?? null;

                if ($post) {
                    return htmlspecialchars($post['title'] ?? '') . ' - ' . htmlspecialchars($site['title'] ?? '');
                }
                return htmlspecialchars($site['title'] ?? 'Unfold');
            },

            // Content helper - render post content
            'content' => function ($options = null) {
                $post = $options['data']['root']['post'] ?? [];
                return $post['html'] ?? $post['content'] ?? '';
            },

            // Excerpt helper
            'excerpt' => function ($options = null) {
                $post = $options['data']['root']['post'] ?? [];
                $words = $options['hash']['words'] ?? 50;
                $text = $post['excerpt'] ?? $post['summary'] ?? '';

                $wordArray = explode(' ', strip_tags($text));
                if (count($wordArray) > $words) {
                    $text = implode(' ', array_slice($wordArray, 0, $words)) . '...';
                }
                return $text;
            },

            // Plural helper
            'plural' => function ($count, $options = null) {
                if (!is_array($options)) {
                    return '';
                }
                $singular = $options['hash']['singular'] ?? '';
                $plural = $options['hash']['plural'] ?? $singular . 's';
                return $count == 1 ? $singular : $plural;
            },

            // Reading time helper
            'reading_time' => function ($options = null) {
                $post = $options['data']['root']['post'] ?? [];
                $content = $post['content'] ?? $post['html'] ?? '';
                $words = str_word_count(strip_tags($content));
                $minutes = max(1, ceil($words / 200));
                return $minutes . ' min read';
            },

            // img_url helper - return image URL (passthrough for now)
            'img_url' => function ($url, $options = null) {
                return $url ?? '';
            },

            // concat helper
            'concat' => function (...$args) {
                $options = array_pop($args);
                return implode('', array_filter($args, fn($a) => !is_array($a)));
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

