<?php

declare(strict_types=1);

namespace App\Service\Analytics;

/**
 * Detects crawler / bot traffic by inspecting the HTTP User-Agent string.
 *
 * Strategy (in order of check):
 *   1. Empty / missing UA  → almost always a bot or headless scraper.
 *   2. Known crawler keywords  → maintained list of well-known bots.
 *   3. Suspicious CLI / library patterns  → curl, python-requests, scrapy, …
 *
 * A detected "bot" is NOT discarded — it is stored with the `is_bot` flag set
 * to TRUE so the admin analytics dashboard can show the bot/human split while
 * keeping human-only numbers clean.
 */
final class BotDetector
{
    /**
     * Case-insensitive substrings that identify known crawlers and bots.
     * Ordered roughly by frequency / importance.
     */
    private const BOT_PATTERNS = [
        // Major search engines
        'googlebot',
        'bingbot',
        'slurp',           // Yahoo! Slurp
        'duckduckbot',
        'baiduspider',
        'yandexbot',
        'yandex',
        'sogou',
        'exabot',
        'ia_archiver',     // Wayback Machine / Internet Archive
        'archive.org',
        'facebookexternalhit',
        'facebot',
        'twitterbot',
        'linkedinbot',
        'pinterest',
        'slackbot',
        'telegrambot',
        'discordbot',
        'whatsapp',
        'applebot',
        'petalbot',
        'semrushbot',
        'ahrefsbot',
        'dotbot',
        'mj12bot',
        'seznambot',
        'rogerbot',
        'screaming frog',

        // Generic crawler / spider keywords
        'crawler',
        'spider',
        'robot',
        'scraper',
        'checker',
        'monitor',
        'archiver',
        'fetcher',

        // Common HTTP libraries / CLI tools used by scrapers
        'python-requests',
        'python-urllib',
        'python-httpx',
        'go-http-client',
        'java/',
        'jakarta commons',
        'apache-httpclient',
        'okhttp',
        'libwww-perl',
        'lwp-trivial',
        'curl/',
        'wget/',
        'httpie',
        'scrapy',
        'phantomjs',
        'headlesschrome',
        'selenium',
        'puppeteer',
        'playwright',

        // SEO / security / uptime tools
        'uptimerobot',
        'pingdom',
        'statuscake',
        'gtmetrix',
        'pagespeed',
        'lighthouse',
        'site24x7',
        'newrelic',
        'datadog',
        'zabbix',
        'nagios',
        'hyperscan',
        'nuclei',
    ];

    /**
     * Return TRUE when the User-Agent string is identifiable as a bot/crawler.
     */
    public function isBot(?string $userAgent): bool
    {
        if ($userAgent === null || trim($userAgent) === '') {
            return true;
        }

        $lower = strtolower($userAgent);

        foreach (self::BOT_PATTERNS as $pattern) {
            if (str_contains($lower, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return a truncated, safe copy of the UA string suitable for storage.
     * Strips null bytes and limits to 512 characters.
     */
    public function sanitize(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $clean = str_replace("\0", '', $userAgent);

        return mb_substr($clean, 0, 512);
    }
}

