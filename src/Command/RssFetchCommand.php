<?php

namespace App\Command;

use App\Entity\Article;
use App\Entity\NzineBot;
use App\Factory\ArticleFactory;
use App\Repository\NzineRepository;
use App\Service\EncryptionService;
use App\Service\NostrClient;
use App\Service\RssFeedService;
use Doctrine\ORM\EntityManagerInterface;
use League\HTMLToMarkdown\HtmlConverter;
use swentel\nostr\Event\Event;
use swentel\nostr\Key\Key;
use swentel\nostr\Sign\Sign;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\String\Slugger\AsciiSlugger;

#[AsCommand(
    name: 'nzine:rss:fetch',
    description: 'Fetch RSS feeds and save new articles for configured nzines',
)]
class RssFetchCommand extends Command
{
    public function __construct(
        private readonly NzineRepository        $nzineRepository,
        private readonly ArticleFactory         $factory,
        private readonly RssFeedService         $rssFeedService,
        private readonly EntityManagerInterface $entityManager,
        private readonly NostrClient            $nostrClient,
        private readonly EncryptionService      $encryptionService
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $slugger = new AsciiSlugger();

        $nzines = $this->nzineRepository->findAll();
        foreach ($nzines as $nzine) {
            if (!$nzine->getFeedUrl()) {
                continue;
            }

            /** @var NzineBot $bot */
            $bot = $nzine->getNzineBot();
            $bot->setEncryptionService($this->encryptionService);

            $key = new Key();
            $npub = $key->getPublicKey($bot->getNsec());
            $articles = $this->entityManager->getRepository(Article::class)->findBy(['pubkey' => $npub]);
            $io->writeln('Found ' . count($articles) . ' existing articles for bot ' . $npub);

            $io->section('Fetching RSS for: ' . $nzine->getFeedUrl());

            try {
                $feed = $this->rssFeedService->fetchFeed($nzine->getFeedUrl());
            } catch (\Throwable $e) {
                $io->warning('Failed to fetch ' . $nzine->getFeedUrl() . ': ' . $e->getMessage());
                continue;
            }

            foreach ($feed['items'] as $item) {
                try {
                    $event = new Event();
                    $event->setKind(30023); // NIP-23 Long-form content

                    // created_at — use parsed pubDate (timestamp int) or now
                    $createdAt = isset($item['pubDate']) && is_numeric($item['pubDate'])
                        ? (int)$item['pubDate']
                        : time();
                    $event->setCreatedAt($createdAt);

                    // slug (NIP-33 'd' tag) — stable per source item
                    $base = trim(($nzine->getSlug() ?? 'nzine') . '-' . ($item['title'] ?? ''));
                    $slug = (string) $slugger->slug($base)->lower();

                    // HTML → Markdown
                    $raw = trim($item['content'] ?? '') ?: trim($item['description'] ?? '');
                    $rawHtml = $this->normalizeWeirdHtml($raw);
                    $cleanHtml = $this->sanitizeHtml($rawHtml);
                    $markdown = $this->htmlToMarkdown($cleanHtml);
                    $event->setContent($markdown);

                    // Tags
                    $tags = [
                        ['title', $this->safeStr($item['title'] ?? '')],
                        ['d', $slug],
                        ['source', $this->safeStr($item['link'] ?? '')],
                    ];

                    // summary (short description)
                    $summary = $this->ellipsis($this->plainText($item['description'] ?? ''), 280);
                    if ($summary !== '') {
                        $tags[] = ['summary', $summary];
                    }

                    // image
                    if (!empty($item['image'])) {
                        $tags[] = ['image', $this->safeStr($item['image'])];
                    } else {
                        // try to sniff first <img> from content if media tag was missing
                        if (preg_match('~<img[^>]+src="([^"]+)"~i', $rawHtml, $m)) {
                            $tags[] = ['image', $m[1]];
                        }
                    }

                    // categories → "t" tags
                    if (!empty($item['categories']) && is_array($item['categories'])) {
                        foreach ($item['categories'] as $category) {
                            $cat = trim((string)$category);
                            if ($cat !== '') {
                                $event->addTag(['t', $cat]);
                            }
                        }
                    }

                    $event->setTags($tags);

                    // Sign event
                    $signer = new Sign();
                    $signer->signEvent($event, $bot->getNsec());

                    // Publish (add/adjust relays as you like)
                    try {
                        $this->nostrClient->publishEvent($event, [
                            'wss://purplepag.es',
                            'wss://relay.damus.io',
                            'wss://nos.lol',
                        ]);
                        $io->writeln('Published long-form event: ' . ($item['title'] ?? '(no title)'));
                    } catch (\Throwable $e) {
                        $io->warning('Publish failed: ' . $e->getMessage());
                    }

                    // Persist locally
                    $article = $this->factory->createFromLongFormContentEvent((object)$event->toArray());
                    $this->entityManager->persist($article);

                } catch (\Throwable $e) {
                    // keep going on item errors
                    $io->warning('Item failed: ' . ($item['title'] ?? '(no title)') . ' — ' . $e->getMessage());
                }
            }

            $this->entityManager->flush();
            $io->success('RSS fetch complete for: ' . $nzine->getFeedUrl());

            // --- Update bot profile (kind 0) using feed metadata ---
            $feedMeta = $feed['feed'] ?? null;
            if ($feedMeta) {
                $profile = [
                    'name'    => $feedMeta['title'] ?? $nzine->getTitle(),
                    'about'   => $feedMeta['description'] ?? '',
                    'picture' => $feedMeta['image'] ?? null,
                    'website' => $feedMeta['link'] ?? null,
                ];
                $p = new Event();
                $p->setKind(0);
                $p->setCreatedAt(time());
                $p->setContent(json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                $signer = new Sign();
                $signer->signEvent($p, $bot->getNsec());
                try {
                    $this->nostrClient->publishEvent($p, ['wss://purplepag.es']);
                    $io->success('Published bot profile (kind 0) with feed metadata');
                } catch (\Throwable $e) {
                    $io->warning('Failed to publish bot profile event: ' . $e->getMessage());
                }
            }
        }

        return Command::SUCCESS;
    }

    /** -------- Helpers: HTML prep + converter + small utils -------- */

    private function normalizeWeirdHtml(string $html): string
    {
        // 1) Unwrap Ghost "HTML cards": keep only the <body> content, drop <html>/<head> wrappers and scripts
        $html = preg_replace_callback('/<!--\s*kg-card-begin:\s*html\s*-->.*?<!--\s*kg-card-end:\s*html\s*-->/si', function ($m) {
            $block = $m[0];
            // Extract inner <body>…</body> if present
            if (preg_match('/<body\b[^>]*>(.*?)<\/body>/si', $block, $mm)) {
                $inner = $mm[1];
            } else {
                // No explicit body; just strip the markers
                $inner = preg_replace('/<!--\s*kg-card-(?:begin|end):\s*html\s*-->/', '', $block);
            }
            return $inner;
        }, $html);

        // 2) Nuke any remaining document wrappers that would cut DOM parsing short
        $html = preg_replace([
            '/<\/?html[^>]*>/i',
            '/<\/?body[^>]*>/i',
            '/<head\b[^>]*>.*?<\/head>/si',
        ], '', $html);

        dump($html);

        return $html;
    }


    private function sanitizeHtml(string $html): string
    {
        if ($html === '') return $html;

        // 0) quick pre-clean: kill scripts/styles early to avoid DOM bloat
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html);
        $html = preg_replace('~<!--.*?-->~s', '', $html); // comments

        // 1) Normalize weird widgets and wrappers BEFORE DOM parse
        // lightning-widget → simple text
        $html = preg_replace_callback(
            '~<lightning-widget[^>]*\bto="([^"]+)"[^>]*>.*?</lightning-widget>~is',
            fn($m) => '<p>⚡ Tips: ' . htmlspecialchars($m[1]) . '</p>',
            $html
        );
        // Ghost/Koenig wrappers: keep useful inner content
        $html = preg_replace('~<figure[^>]*\bkg-image-card\b[^>]*>\s*(<img[^>]+>)\s*</figure>~i', '$1', $html);
        $html = preg_replace('~<div[^>]*\bkg-callout-card\b[^>]*>(.*?)</div>~is', '<blockquote>$1</blockquote>', $html);
        // YouTube iframes → links
        $html = preg_replace_callback(
            '~<iframe[^>]+src="https?://www\.youtube\.com/embed/([A-Za-z0-9_\-]+)[^"]*"[^>]*></iframe>~i',
            fn($m) => '<p><a href="https://youtu.be/' . $m[1] . '">Watch on YouTube</a></p>',
            $html
        );

        // 2) Try to pretty up malformed markup via Tidy (if available)
        if (function_exists('tidy_parse_string')) {
            try {
                $tidy = tidy_parse_string($html, [
                    'clean' => true,
                    'output-xhtml' => true,
                    'show-body-only' => false,
                    'wrap' => 0,
                    'drop-empty-paras' => true,
                    'merge-divs' => true,
                    'merge-spans' => true,
                    'numeric-entities' => false,
                    'quote-ampersand' => true,
                ], 'utf8');
                $tidy->cleanRepair();
                $html = (string)$tidy;
            } catch (\Throwable $e) {
                // ignore tidy failures
            }
        }

        // 3) DOM sanitize: remove junk, unwrap html/body/head, allowlist elements/attrs
        $dom = new \DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
        // force UTF-8 meta so DOMDocument doesn't mangle
            '<!DOCTYPE html><meta http-equiv="Content-Type" content="text/html; charset=utf-8">'.$html,
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        if (!$loaded) {
            // fallback: as-is minus tags we already stripped
            return $html;
        }

        $xpath = new \DOMXPath($dom);

        // Remove <head>, <script>, <style>, <link>, <meta>, <noscript>, <object>, <embed>
        foreach (['//head','//script','//style','//link','//meta','//noscript','//object','//embed'] as $q) {
            foreach ($xpath->query($q) as $n) {
                $n->parentNode?->removeChild($n);
            }
        }

        // Remove iframes that survived (non-YouTube or any at this point)
        foreach ($xpath->query('//iframe') as $n) {
            $n->parentNode?->removeChild($n);
        }

        // Remove any custom elements we don’t want (e.g., <lightning-widget>, <amp-*>)
        foreach ($xpath->query('//*[starts-with(name(), "amp-") or local-name()="lightning-widget"]') as $n) {
            $n->parentNode?->removeChild($n);
        }

        // Allowlist basic attributes; drop event handlers/javascript: urls
        $allowedAttrs = ['href','src','alt','title','width','height','class'];
        foreach ($xpath->query('//@*') as $attr) {
            $name = $attr->nodeName;
            $val  = $attr->nodeValue ?? '';
            if (!in_array($name, $allowedAttrs, true)) {
                $attr->ownerElement?->removeAttributeNode($attr);
                continue;
            }
            // kill javascript: and data: except images
            if ($name === 'href' || $name === 'src') {
                $valTrim = trim($val);
                $lower = strtolower($valTrim);
                $isDataImg = str_starts_with($lower, 'data:image/');
                if (str_starts_with($lower, 'javascript:') || (str_starts_with($lower, 'data:') && !$isDataImg)) {
                    $attr->ownerElement?->removeAttribute($name);
                } else {
                    $attr->nodeValue = $valTrim;
                }
            }
        }

        // Unwrap <html> and <body> → gather innerHTML
        $body = $dom->getElementsByTagName('body')->item(0);
        $container = $body ?: $dom; // fallback

        // Drop empty spans/divs that are just whitespace
        foreach ($xpath->query('.//span|.//div', $container) as $n) {
            if (!trim($n->textContent ?? '') && !$n->getElementsByTagName('*')->length) {
                $n->parentNode?->removeChild($n);
            }
        }

        // Serialize inner HTML of container
        $cleanHtml = '';
        foreach ($container->childNodes as $child) {
            $cleanHtml .= $dom->saveHTML($child);
        }

        // Final tiny cleanups
        $cleanHtml = preg_replace('~\s+</p>~', '</p>', $cleanHtml);
        $cleanHtml = preg_replace('~<p>\s+</p>~', '', $cleanHtml);

        return trim($cleanHtml);
    }

    private function htmlToMarkdown(string $html): string
    {
        $converter = $this->makeConverter();
        $md = trim($converter->convert($html));

        // ensure there's a blank line after images
        // 1) images that already sit alone on a line
        $md = preg_replace('/^(>?\s*)!\[[^\]]*]\([^)]*\)\s*$/m', "$0\n", $md);
        // 2) inline images: add a newline after the token (optional — comment out if you only want #1)
        $md = preg_replace('/!\[[^\]]*]\([^)]*\)/', "$0\n", $md);

        // collapse any excessive blank lines to max two
        $md = preg_replace("/\n{3,}/", "\n\n", $md);

        // Optional: coalesce too many blank lines caused by sanitization/conversion
        $md = preg_replace("~\n{3,}~", "\n\n", $md);

        return $md;
    }

    private function makeConverter(): HtmlConverter
    {
        return new HtmlConverter([
            'header_style' => 'atx',
            'bold_style'   => '**',
            'italic_style' => '*',
            'hard_break'   => true,
            'strip_tags'   => true,
            'remove_nodes' => 'script style',
        ]);
    }

    private function plainText(string $html): string
    {
        return trim(html_entity_decode(strip_tags($html)));
    }

    private function ellipsis(string $text, int $max): string
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) <= $max) return $text;
        return rtrim(mb_substr($text, 0, $max - 1)) . '…';
    }

    private function safeStr(?string $s): string
    {
        return $s === null ? '' : trim($s);
    }
}
