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
                'allowed_domains' => ['youtube.com', 'twitter.com', 'github.com'],
                'fallback' => 'link'
            ],
        ];
        $environment = new Environment($config);
        // Add the extensions
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FootnoteExtension());
        $environment->addExtension(new TableExtension());
        $environment->addExtension(new StrikethroughExtension());
        // create a custom extension, that handles nostr mentions
        $environment->addExtension(new NostrSchemeExtension($this->redisCacheService, $this->nostrClient, $this->twig, $this->nostrKeyUtil));
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

}
