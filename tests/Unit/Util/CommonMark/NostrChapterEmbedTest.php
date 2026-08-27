<?php

declare(strict_types=1);

namespace App\Tests\Unit\Util\CommonMark;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Factory\ArticleFactory;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Util\CommonMark\Converter;
use nostriphant\NIP19\Bech32;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;

final class NostrChapterEmbedTest extends TestCase
{
    public function testPublicationContentNaddrRendersChapterCard(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'intro';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::PUBLICATION_CONTENT->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );
        $event = (object) [
            'id' => str_repeat('b', 64),
            'kind' => KindsEnum::PUBLICATION_CONTENT->value,
            'pubkey' => $pubkey,
            'content' => "= Intro\n\nChapter body",
            'created_at' => 123,
            'tags' => [['d', $identifier], ['title', 'Intro chapter'], ['summary', 'Chapter summary']],
            'sig' => str_repeat('c', 128),
        ];

        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with('components/Molecules/ChapterCard.html.twig', self::callback(
                fn (array $parameters): bool => $parameters['chapter'] === $event
                    && $parameters['naddr'] === $naddr
                    && $parameters['link'] === '/chapter/' . $naddr
                    && $parameters['title'] === 'Intro chapter'
                    && $parameters['summary'] === 'Chapter summary'
            ))
            ->willReturn('<article class="chapter-card"><a href="/chapter/' . $naddr . '">Intro chapter</a></article>');

        $html = $this->renderNostrLink($twig, $naddr, [
            KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':' . $identifier => $event,
        ]);

        self::assertStringContainsString('chapter-card', $html);
        self::assertStringContainsString('/chapter/' . $naddr, $html);
    }

    public function testPublicationContentInlineNaddrLinksToChapterRoute(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = 'intro';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::PUBLICATION_CONTENT->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );

        $html = $this->renderNostrLink(
            $this->createMock(TwigEnvironment::class),
            $naddr,
            [],
            'read chapter',
            true,
        );

        self::assertSame('<a href="/chapter/' . $naddr . '" class="nostr-link">read chapter</a>', $html);
    }

    public function testLongformNaddrStillRendersArticleCard(): void
    {
        $pubkey = str_repeat('d', 64);
        $identifier = 'article';
        $naddr = (string) Bech32::naddr(
            kind: KindsEnum::LONGFORM->value,
            pubkey: $pubkey,
            identifier: $identifier,
        );
        $event = (object) [
            'id' => str_repeat('e', 64),
            'kind' => KindsEnum::LONGFORM->value,
            'pubkey' => $pubkey,
            'content' => 'Article body',
            'created_at' => 456,
            'tags' => [['d', $identifier], ['title', 'Article title']],
            'sig' => str_repeat('f', 128),
        ];
        $article = new Article();

        $twig = $this->createMock(TwigEnvironment::class);
        $twig->expects(self::once())
            ->method('render')
            ->with('components/Molecules/Card.html.twig', self::callback(
                fn (array $parameters): bool => $parameters['article'] === $article
            ))
            ->willReturn('<article class="article-card">Article title</article>');

        $articleFactory = $this->createMock(ArticleFactory::class);
        $articleFactory->expects(self::once())
            ->method('createFromLongFormContentEvent')
            ->with($event)
            ->willReturn($article);

        $html = $this->renderNostrLink($twig, $naddr, [
            KindsEnum::LONGFORM->value . ':' . $pubkey . ':' . $identifier => $event,
        ], null, false, $articleFactory);

        self::assertStringContainsString('article-card', $html);
    }

    /**
     * @param array<string, object> $eventsByNaddr
     */
    private function renderNostrLink(
        TwigEnvironment $twig,
        string $naddr,
        array $eventsByNaddr,
        ?string $displayText = null,
        bool $preferInline = false,
        ?ArticleFactory $articleFactory = null,
    ): string {
        $converter = new Converter(
            $this->createMock(RedisCacheService::class),
            $twig,
            $articleFactory ?? $this->createMock(ArticleFactory::class),
            $this->createMock(\AsciiDocConverter::class),
            $this->createMock(EventRepository::class),
        );

        $method = (new \ReflectionClass(Converter::class))->getMethod('renderNostrLink');

        return $method->invoke(
            $converter,
            new Bech32($naddr),
            $naddr,
            [],
            [],
            $displayText,
            $preferInline,
            $eventsByNaddr,
        );
    }
}
