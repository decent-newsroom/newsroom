<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ArticleRecoveryTranslationsTest extends TestCase
{
    public function testEditorRecoveryKeysExistInEveryEnabledLocale(): void
    {
        $root = dirname(__DIR__, 3);
        $configuration = Yaml::parseFile($root . '/config/packages/translation.yaml');
        $template = file_get_contents($root . '/templates/editor/layout.html.twig');
        self::assertIsString($template);
        preg_match_all("/'article_recovery\\.([a-z_]+)'\\|trans/", $template, $matches);
        $keys = array_unique($matches[1]);
        self::assertNotEmpty($keys);

        foreach ($configuration['framework']['enabled_locales'] as $locale) {
            $catalogue = Yaml::parseFile($root . '/translations/messages.' . $locale . '.yaml');
            foreach ($keys as $key) {
                self::assertArrayHasKey($key, $catalogue['article_recovery'] ?? [], "$locale: article_recovery.$key");
                self::assertNotEmpty($catalogue['article_recovery'][$key], "$locale: article_recovery.$key");
            }
            self::assertArrayHasKey('editor', $catalogue['home'], "$locale: home.editor");
        }
    }
}
