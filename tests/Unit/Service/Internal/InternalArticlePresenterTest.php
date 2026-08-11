<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Internal;

use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Service\Internal\InternalArticlePresenter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class InternalArticlePresenterTest extends TestCase
{
    private const HEX = '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2';

    public function testPresentBuildsNormalizedContractWithNpubAndCoordinate(): void
    {
        $presenter = new InternalArticlePresenter($this->urlGenerator('https://example.com/article'));

        $article = (new Article())
            ->setPubkey(self::HEX)
            ->setSlug('my-slug')
            ->setTitle('My Title')
            ->setSummary('A summary')
            ->setKind(KindsEnum::LONGFORM)
            ->setTopics(['bitcoin', 'nostr'])
            ->setCreatedAt(new \DateTimeImmutable('2024-01-02T03:04:05+00:00'));

        $result = $presenter->present($article, true);

        self::assertNotNull($result);
        self::assertSame('30023:' . self::HEX . ':my-slug', $result['coordinate']);
        self::assertSame(30023, $result['kind']);
        self::assertSame('My Title', $result['title']);
        self::assertSame('A summary', $result['summary']);
        self::assertSame(self::HEX, $result['pubkey']);
        self::assertIsString($result['npub']);
        self::assertStringStartsWith('npub1', $result['npub']);
        self::assertSame(['bitcoin', 'nostr'], $result['topics']);
        self::assertSame('https://example.com/article', $result['url']);
        self::assertArrayHasKey('content', $result);
        self::assertArrayNotHasKey('_createdTs', $result);
    }

    public function testPresentExcludesContentByDefault(): void
    {
        $presenter = new InternalArticlePresenter($this->urlGenerator('https://example.com/article'));

        $article = (new Article())
            ->setPubkey(self::HEX)
            ->setSlug('my-slug')
            ->setTitle('My Title')
            ->setContent('secret body');

        $result = $presenter->present($article);

        self::assertNotNull($result);
        self::assertArrayNotHasKey('content', $result);
    }

    public function testPresentReturnsNullWhenRequiredFieldsMissing(): void
    {
        $presenter = new InternalArticlePresenter($this->urlGenerator('https://example.com/article'));

        $article = (new Article())
            ->setPubkey(self::HEX)
            ->setSlug('my-slug'); // no title

        self::assertNull($presenter->present($article));
    }

    public function testPresentManyDeduplicatesByCoordinateKeepingNewest(): void
    {
        $presenter = new InternalArticlePresenter($this->urlGenerator('https://example.com/article'));

        $older = (new Article())
            ->setPubkey(self::HEX)
            ->setSlug('dup')
            ->setTitle('Old')
            ->setKind(KindsEnum::LONGFORM)
            ->setCreatedAt(new \DateTimeImmutable('2024-01-01T00:00:00+00:00'));

        $newer = (new Article())
            ->setPubkey(self::HEX)
            ->setSlug('dup')
            ->setTitle('New')
            ->setKind(KindsEnum::LONGFORM)
            ->setCreatedAt(new \DateTimeImmutable('2024-06-01T00:00:00+00:00'));

        $results = $presenter->presentMany([$older, $newer]);

        self::assertCount(1, $results);
        self::assertSame('New', $results[0]['title']);
    }

    private function urlGenerator(string $url): UrlGeneratorInterface
    {
        $generator = $this->createMock(UrlGeneratorInterface::class);
        $generator->method('generate')->willReturn($url);

        return $generator;
    }
}
