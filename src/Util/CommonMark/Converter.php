<?php

namespace App\Util\CommonMark;

use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\NostrClient;
use App\Util\CommonMark\ImagesExtension\RawImageLinkExtension;
use App\Util\CommonMark\NostrSchemeExtension\NostrSchemeExtension;
use App\Util\NostrKeyUtil;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Embed\Bridge\OscaroteroEmbedAdapter;
use League\CommonMark\Extension\Embed\Embed;
use League\CommonMark\Extension\Embed\EmbedExtension;
use League\CommonMark\Extension\Embed\EmbedRenderer;
use League\CommonMark\Extension\Footnote\FootnoteExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\Extension\SmartPunct\SmartPunctExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\TableOfContents\TableOfContentsExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Renderer\HtmlDecorator;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\Note;
use nostriphant\NIP19\Data\NProfile;
use Twig\Environment as TwigEnvironment;

final readonly class Converter
{
    /** Match any nostr:* bech link (used for batching) */
    private const RE_ALL_NOSTR = '~nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^\s<>()\[\]{}"\'`.,;:!?]*~i';

    /** Replace anchors with href="nostr:..." while preserving inner text */
    private const RE_NOSTR_ANCHOR = '~<a\b(?<attrs>[^>]*?)\bhref=(["\'])(?<nostr>nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^"\']*)\2(?<attrs2>[^>]*)>(?<inner>.*?)</a>~is';

    /** Bare-text nostr links, defensive against href immediate prefix */
    private const RE_BARE_NOSTR = '~(?<!href=")(?<!href=\')nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^\s<>()\[\]{}"\'`.,;:!?]*~i';

    public function __construct(
        private RedisCacheService $redisCacheService,
        private NostrClient $nostrClient,
        private TwigEnvironment $twig,
        private NostrKeyUtil $nostrKeyUtil,
        private ArticleFactory $articleFactory,
        private ArticleRepository $articleRepository
    ) {}

    /**
     * @throws CommonMarkException
     */
    public function convertToHTML(string $markdown): string
    {
        $headingsCount = preg_match_all('/^#+\s.*$/m', $markdown);

        $config = [
            'table_of_contents' => ['min_heading_level' => 1, 'max_heading_level' => 3],
            'heading_permalink' => ['symbol' => '§'],
            'autolink'          => ['allowed_protocols' => ['https'], 'default_protocol' => 'https'],
            'embed'             => [
                'adapter'         => new OscaroteroEmbedAdapter(),
                'allowed_domains' => ['youtube.com', 'x.com', 'github.com', 'fountain.fm', 'blossom.primal.net', 'i.nostr.build', 'video.nostr.build'],
                'fallback'        => 'link',
            ],
        ];

        $env = new Environment($config);
        $env->addExtension(new CommonMarkCoreExtension());
        $env->addExtension(new FootnoteExtension());
        $env->addExtension(new TableExtension());
        $env->addExtension(new StrikethroughExtension());
        $env->addExtension(new SmartPunctExtension());
        $env->addExtension(new EmbedExtension());
        $env->addRenderer(Embed::class, new HtmlDecorator(new EmbedRenderer(), 'div', ['class' => 'embedded-content']));
        $env->addExtension(new NostrSchemeExtension($this->redisCacheService, $this->nostrClient, $this->twig, $this->nostrKeyUtil, $this->articleFactory, $this->articleRepository));
        $env->addExtension(new RawImageLinkExtension());
        $env->addExtension(new AutolinkExtension());

        if ($headingsCount > 3) {
            $env->addExtension(new HeadingPermalinkExtension());
            $env->addExtension(new TableOfContentsExtension());
        }

        $converter = new MarkdownConverter($env);
        $html = (string) $converter->convert(html_entity_decode($markdown));

        return $this->processNostrLinks($html);
    }

    private function processNostrLinks(string $content): string
    {
        // 1) Collect all nostr refs for batching (anchors + bare text)
        preg_match_all(self::RE_ALL_NOSTR, $content, $mAll);
        if (empty($mAll[0])) {
            return $content;
        }

        $uniqueLinks = array_values(array_unique($mAll[0]));
        [$eventIds, $pubkeyHexes, $naddrCoords] = $this->collectBatchKeys($uniqueLinks);

        // 2) Batch fetch events (map: id => event)
        $eventsById = $this->fetchEventsById($eventIds, $pubkeyHexes);

        // 3) Batch fetch metadata (map: hex => profile)
        $metadataByHex = $this->fetchMetadataByHex(array_keys($pubkeyHexes));

        // 4) Replace anchors (inline by default, card if data-embed or class)
        $content = $this->replaceNostrAnchors($content, $eventsById, $metadataByHex);

        // 5) Replace bare text only in text nodes
        $content = $this->replaceBareTextNostr($content, $eventsById, $metadataByHex);

        return $content;
    }

    /** @return array{0: array<string,int>, 1: array<string,int>, 2: array<string,array>} [$eventIds, $pubkeyHexes, $naddrCoords] */
    private function collectBatchKeys(array $links): array
    {
        $eventIds = [];    // id => 1
        $pubkeyHexes = []; // hex => 1
        $naddrCoords = []; // bech => ['kind' => x, 'pubkey' => hex, 'identifier' => d, 'relays' => [...]]

        foreach ($links as $link) {
            $bech = substr($link, 6);
            try {
                $decoded = new Bech32($bech);
                switch ($decoded->type) {
                    case 'npub':
                        $hex = $this->nostrKeyUtil->npubToHex($bech);
                        $pubkeyHexes[$hex] = 1;
                        break;
                    case 'nprofile':
                        /** @var NProfile $obj */
                        $obj = $decoded->data;
                        $pubkeyHexes[$obj->pubkey] = 1;
                        break;
                    case 'note':
                        /** @var Note $obj */
                        $obj = $decoded->data;
                        $eventIds[$obj->data] = 1;
                        break;
                    case 'nevent':
                        /** @var NEvent $obj */
                        $obj = $decoded->data;
                        $eventIds[$obj->id] = 1;
                        break;
                    case 'naddr':
                        /** @var NAddr $obj */
                        $obj = $decoded->data;
                        $naddrCoords[$bech] = [
                            'kind' => $obj->kind,
                            'pubkey' => $obj->pubkey,
                            'identifier' => $obj->identifier,
                            'relays' => $obj->relays ?? []
                        ];
                        $pubkeyHexes[$obj->pubkey] = 1;
                        break;
                }
            } catch (\Throwable) {
                // skip invalid
            }
        }

        return [$eventIds, $pubkeyHexes, $naddrCoords];
    }

    /** @param array<string,int> $eventIds  @param array<string,int> $pubkeyHexes  @return array<string,object> */
    private function fetchEventsById(array $eventIds, array &$pubkeyHexes): array
    {
        $eventsById = [];
        if (empty($eventIds)) {
            return $eventsById;
        }

        try {
            $list = $this->nostrClient->getEventsByIds(array_keys($eventIds));
            foreach ($list as $event) {
                // expect $event->id and $event->pubkey
                if (!empty($event->id)) {
                    $eventsById[$event->id] = $event;
                }
                if (!empty($event->pubkey)) {
                    $pubkeyHexes[$event->pubkey] = 1;
                }
            }
        } catch (\Throwable) {
            // swallow; fall back to simple links
        }

        return $eventsById;
    }

    /** @param string[] $hexes  @return array<string, mixed|null> */
    private function fetchMetadataByHex(array $hexes): array
    {
        if (empty($hexes)) {
            return [];
        }

        $byHex = [];
        try {
            $fetched = $this->redisCacheService->getMultipleMetadata($hexes);
            foreach ($hexes as $hex) {
                $byHex[$hex] = $fetched[$hex] ?? null;
            }
        } catch (\Throwable) {
            foreach ($hexes as $hex) {
                $byHex[$hex] = null;
            }
        }

        return $byHex;
    }

    /** Replace <a href="nostr:...">…</a> with inline links by default (card if opted in) */
    private function replaceNostrAnchors(string $content, array $eventsById, array $metadataByHex): string
    {
        return preg_replace_callback(self::RE_NOSTR_ANCHOR, function ($m) use ($eventsById, $metadataByHex) {
            $nostrUrl = $m['nostr'];
            $bech     = substr($nostrUrl, 6);
            $attrsAll = trim(($m['attrs'] ?? '') . ' ' . ($m['attrs2'] ?? ''));
            $inner    = $m['inner'];

            // Inline by default for anchors
            $preferInline = true;

            // Opt-in to card if data-embed="1" or class contains "nostr-card" or "embed"
            if (preg_match('~\bdata-embed\s*=\s*("1"|\'1\'|1)\b~i', $attrsAll) ||
                preg_match('~\bclass\s*=\s*("|\')[^"\']*\b(nostr-card|embed)\b[^"\']*\1~i', $attrsAll)) {
                $preferInline = false;
            }

            try {
                $decoded = new Bech32($bech);
                return $this->renderNostrLink($decoded, $bech, $metadataByHex, $eventsById, $inner, $preferInline);
            } catch (\Throwable) {
                return $m[0]; // keep original anchor on error
            }
        }, $content);
    }

    /** Replace bare-text nostr links in text nodes only */
    private function replaceBareTextNostr(string $content, array $eventsById, array $metadataByHex): string
    {
        $parts = preg_split('~(<[^>]+>)~', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return $content;
        }

        foreach ($parts as $i => $part) {
            // Skip tags and empties
            if ($part === '' || $part[0] === '<') {
                continue;
            }

            $parts[$i] = preg_replace_callback(self::RE_BARE_NOSTR, function ($mm) use ($eventsById, $metadataByHex) {
                $nostrUrl = $mm[0];
                $bech     = substr($nostrUrl, 6);
                try {
                    $decoded = new Bech32($bech);
                    // Bare text can render cards (preferInline = false)
                    return $this->renderNostrLink($decoded, $bech, $metadataByHex, $eventsById, null, false);
                } catch (\Throwable) {
                    return $nostrUrl;
                }
            }, $part);
        }

        return implode('', $parts);
    }

    /**
     * Renders a single nostr reference to HTML.
     * - $eventsById: event.id => event
     * - $eventsByNaddr: bech => event
     * - $metadataByHex: authorHex => profile
     * - $displayText: preserve original anchor text if provided
     * - $preferInline: true for inline <a>, false to allow cards
     */
    private function renderNostrLink(
        Bech32 $decoded,
        string $bechEncoded,
        array $metadataByHex,
        array $eventsById,
        array $eventsByNaddr,
        ?string $displayText = null,
        bool $preferInline = false
    ): string {
        switch ($decoded->type) {
            case 'npub': {
                $hex     = $this->nostrKeyUtil->npubToHex($bechEncoded);
                $profile = $metadataByHex[$hex] ?? null;
                $label   = $displayText !== null && $displayText !== ''
                    ? $displayText
                    : (($profile->name ?? null) ?: $this->labelFromKey($bechEncoded));

                return '<a href="/p/' . $this->e($bechEncoded) . '" class="nostr-mention">@' . $this->e($label) . '</a>';
            }

            case 'nprofile': {
                /** @var NProfile $obj */
                $obj     = $decoded->data;
                $hex     = $obj->pubkey;
                $npub    = $this->nostrKeyUtil->hexToNpub($hex);
                $profile = $metadataByHex[$hex] ?? null;
                $label   = $displayText !== null && $displayText !== ''
                    ? $displayText
                    : (($profile->name ?? null) ?: $this->labelFromKey($npub));

                return '<a href="/p/' . $this->e($npub) . '" class="nostr-mention">@' . $this->e($label) . '</a>';
            }

            case 'note': {
                /** @var Note $obj */
                $obj   = $decoded->data;
                $event = $eventsById[$obj->data] ?? null;

                // Card only if allowed and kind 20 (picture)
                if (!$preferInline && $event && (int) $event->kind === 20) {
                    return $this->twig->render('/event/_kind20_picture.html.twig', [
                        'event' => $event,
                        'embed' => true,
                    ]);
                }

                $text = $displayText !== null && $displayText !== '' ? $displayText : $bechEncoded;
                return '<a href="/e/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($text) . '</a>';
            }

            case 'nevent': {
                /** @var NEvent $obj */
                $obj   = $decoded->data;
                $event = $eventsById[$obj->id] ?? null;

                // Inline if requested (anchors) or if we don’t have event data
                if ($preferInline || !$event) {
                    $text = $displayText !== null && $displayText !== '' ? $displayText : $bechEncoded;
                    return '<a href="/e/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($text) . '</a>';
                }

                // Otherwise render a rich card
                $authorMeta = $metadataByHex[$event->pubkey] ?? null;
                return $this->twig->render('components/event_card.html.twig', [
                    'event'  => $event,
                    'author' => $authorMeta,
                    'nevent' => $bechEncoded,
                ]);
            }

            case 'naddr': {
                /** @var NAddr $obj */
                $obj   = $decoded->data;
                $event = $eventsByNaddr[$bechEncoded] ?? null;

                // Inline if requested (anchors) or if we don't have event data
                if ($preferInline || !$event) {
                    $text = $displayText !== null && $displayText !== '' ? $displayText : $bechEncoded;

                    if ((int) $obj->kind === (int) KindsEnum::LONGFORM->value) {
                        return '<a href="/article/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($text) . '</a>';
                    }

                    return '<a href="/e/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($text) . '</a>';
                }

                // Otherwise render a rich card
                $authorMeta = $metadataByHex[$event->pubkey] ?? null;

                // Use article card for longform content (kind 30023)
                if ((int) $event->kind === (int) KindsEnum::LONGFORM->value) {
                    try {
                        // Convert event to Article entity for the Card component
                        $article = $this->articleFactory->createFromLongFormContentEvent($event);

                        // Prepare authors metadata in the format expected by Card component
                        $authorsMetadata = $authorMeta ? [$event->pubkey => $authorMeta] : [];

                        return $this->twig->render('components/Molecules/Card.html.twig', [
                            'article' => $article,
                            'authors_metadata' => $authorsMetadata,
                            'is_author_profile' => false,
                        ]);
                    } catch (\Throwable $e) {
                        // If conversion fails, fall back to simple link
                        return '<a href="/article/' . $this->e($bechEncoded) . '" class="nostr-link">' . $this->e($bechEncoded) . '</a>';
                    }
                }

                // Use generic event card for other addressable events
                return $this->twig->render('components/event_card.html.twig', [
                    'event'  => $event,
                    'author' => $authorMeta,
                    'naddr'  => $bechEncoded,
                ]);
            }

            default:
                return $this->e($bechEncoded);
        }
    }

    private function labelFromKey(string $npub): string
    {
        $start = substr($npub, 0, 5);
        $end   = substr($npub, -5);
        return $start . '...' . $end;
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    }
}
