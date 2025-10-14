<?php

namespace App\Util\CommonMark;

use App\Service\NostrClient;
use App\Service\RedisCacheService;
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
use Twig\Environment as TwigEnvironment;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\Note;
use nostriphant\NIP19\Data\NProfile;
use nostriphant\NIP19\Data\NPub;

readonly class Converter
{
    public function __construct(
        private RedisCacheService $redisCacheService,
        private NostrClient $nostrClient,
        private TwigEnvironment $twig,
        private NostrKeyUtil $nostrKeyUtil
    ){}

    /**
     * @throws CommonMarkException
     */
    public function convertToHTML(string $markdown): string
    {
        // Preprocess nostr: links for batching
        $markdown = $this->preprocessNostrLinks($markdown);

        // Check if the article has more than three headings
        // Match all headings (from level 1 to 6)
        preg_match_all('/^#+\s.*$/m', $markdown, $matches);
        $headingsCount = count($matches[0]);

        // Configure the Environment with all the CommonMark parsers/renderers
        $config = [
            'table_of_contents' => [
                'min_heading_level' => 1,
                'max_heading_level' => 2,
            ],
            'heading_permalink' => [
                'symbol' => '§',
            ],
            'autolink' => [
                'allowed_protocols' => ['https'], // defaults to ['https', 'http', 'ftp']
                'default_protocol' => 'https', // defaults to 'http'
            ],
            'embed' => [
                'adapter' => new OscaroteroEmbedAdapter(), // See the "Adapter" documentation below
                'allowed_domains' => ['youtube.com', 'x.com', 'github.com', 'fountain.fm'],
                'fallback' => 'link'
            ],
        ];
        $environment = new Environment($config);
        // Add the extensions
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FootnoteExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        $environment->addExtension(new SmartPunctExtension());
        $environment->addExtension(new EmbedExtension());
        $environment->addRenderer(Embed::class, new HtmlDecorator(new EmbedRenderer(), 'div', ['class' => 'embedded-content']));
        $environment->addExtension(new RawImageLinkExtension());
        $environment->addExtension(new AutolinkExtension());
        if ($headingsCount > 3) {
            $environment->addExtension(new HeadingPermalinkExtension());
            $environment->addExtension(new TableOfContentsExtension());
        }

        // Instantiate the converter engine and start converting some Markdown!
        $converter = new MarkdownConverter($environment);
        $content = html_entity_decode($markdown);

        return $converter->convert($content);
    }

    private function preprocessNostrLinks(string $markdown): string
    {
        // Find all nostr: links
        preg_match_all('/nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^\\s<>()\\[\\]{}"\'`.,;:!?]*/', $markdown, $matches);

        if (empty($matches[0])) {
            return $markdown;
        }

        $links = array_unique($matches[0]);
        $replacements = [];

        // Collect data for batching
        $pubkeys = [];
        $eventIds = [];

        foreach ($links as $link) {
            $bechEncoded = substr($link, 6); // Remove "nostr:"
            try {
                $decoded = new Bech32($bechEncoded);
                switch ($decoded->type) {
                    case 'npub':
                        /** @var NPub $object */
                        $object = $decoded->data;
                        $hex = $this->nostrKeyUtil->npubToHex($bechEncoded);
                        $pubkeys[$hex] = $bechEncoded;
                        break;
                    case 'nprofile':
                        /** @var NProfile $object */
                        $object = $decoded->data;
                        $pubkeys[$object->pubkey] = $bechEncoded;
                        break;
                    case 'note':
                        /** @var Note $object */
                        $object = $decoded->data;
                        $eventIds[$object->data] = $bechEncoded;
                        break;
                    case 'nevent':
                        /** @var NEvent $object */
                        $object = $decoded->data;
                        $eventIds[$object->id] = $bechEncoded;
                        break;
                    case 'naddr':
                        // For naddr, we might need to fetch the event, but for now, handle as simple link
                        break;
                }
            } catch (\Exception $e) {
                // Invalid link, skip
                continue;
            }
        }

        // Fetch metadata in batch (actually, getMetadata is cached, so just prepare)
        $metadata = [];
        foreach (array_keys($pubkeys) as $hex) {
            try {
                $metadata[$hex] = $this->redisCacheService->getMetadata($hex);
            } catch (\Exception $e) {
                $metadata[$hex] = null;
            }
        }

        // Fetch events in batch
        $events = [];
        if (!empty($eventIds)) {
            try {
                $events = $this->nostrClient->getEventsByIds(array_keys($eventIds));
            } catch (\Exception $e) {
                // If batch fails, events remain empty
            }
        }

        // Now, render each link
        foreach ($links as $link) {
            $bechEncoded = substr($link, 6);
            try {
                $decoded = new Bech32($bechEncoded);
                $html = $this->renderNostrLink($decoded, $bechEncoded, $metadata, $events);
                $replacements[$link] = $html;
            } catch (\Exception $e) {
                // Keep original link if error
                $replacements[$link] = $link;
            }
        }

        // Replace in markdown
        return str_replace(array_keys($replacements), array_values($replacements), $markdown);
    }

    private function renderNostrLink(Bech32 $decoded, string $bechEncoded, array $metadata, array $events): string
    {
        switch ($decoded->type) {
            case 'npub':
                $hex = $this->nostrKeyUtil->npubToHex($bechEncoded);
                $profile = $metadata[$hex] ?? null;
                if ($profile && isset($profile->name)) {
                    return '<a href="/profile/' . $bechEncoded . '" class="nostr-mention">@' . htmlspecialchars($profile->name) . '</a>';
                } else {
                    return '<a href="/profile/' . $bechEncoded . '" class="nostr-mention">@' . substr($bechEncoded, 5, 8) . '…</a>';
                }
            case 'nprofile':
                $object = $decoded->data;
                return '<a href="/profile/' . $bechEncoded . '" class="nostr-mention">@' . substr($bechEncoded, 9, 8) . '…</a>';
            case 'note':
                $object = $decoded->data;
                $event = $events[$object->data] ?? null;
                if ($event && $event->kind === 20) {
                    $pictureCardHtml = $this->twig->render('/event/_kind20_picture.html.twig', [
                        'event' => $event,
                        'embed' => true
                    ]);
                    return $pictureCardHtml;
                } else {
                    return '<a href="/event/' . $bechEncoded . '" class="nostr-link">' . $bechEncoded . '</a>';
                }
            case 'nevent':
                $object = $decoded->data;
                $event = $events[$object->id] ?? null;
                if ($event) {
                    $authorMetadata = $metadata[$event->pubkey] ?? null;
                    $eventCardHtml = $this->twig->render('components/event_card.html.twig', [
                        'event' => $event,
                        'author' => $authorMetadata,
                        'nevent' => $bechEncoded
                    ]);
                    return $eventCardHtml;
                } else {
                    return '<a href="/event/' . $bechEncoded . '" class="nostr-link">' . $bechEncoded . '</a>';
                }
            case 'naddr':
                return '<a href="/event/' . $bechEncoded . '" class="nostr-link">' . $bechEncoded . '</a>';
            default:
                return $bechEncoded;
        }
    }

}
