<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Reader;

use App\Dto\UserMetadata;
use App\Entity\Article;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\ArticleRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\EmbedReferenceExtractor;
use App\Service\Nostr\NostrEventParser;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ArticlePageLoader;
use App\Util\CommonMark\Converter;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class ArticlePageLoaderTest extends TestCase
{
    private const HEX = '82341f882b6eabcd2ba7f1ef90aad961cf074af15b9ef44a09f9d2a8fbfbe6a2';

    public function testMissingArticleQueuesRelayFetchAndReturnsLoadingResult(): void
    {
        $repository = $this->createMock(ArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'missing slug', 'pubkey' => self::HEX], ['createdAt' => 'DESC'])
            ->willReturn(null);

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(function (object $message): bool {
                self::assertInstanceOf(FetchEventFromRelaysMessage::class, $message);
                self::assertSame('article:' . md5(self::HEX . ':missing slug'), $message->lookupKey);
                self::assertSame('naddr', $message->type);
                self::assertSame(KindsEnum::LONGFORM->value, $message->kind);
                self::assertSame(self::HEX, $message->pubkey);
                self::assertSame('missing slug', $message->identifier);

                return true;
            }))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('author-article-slug', ['npub' => self::HEX, 'slug' => 'missing slug'])
            ->willReturn('/p/' . self::HEX . '/d/missing%20slug');

        $loader = $this->loader($repository, $bus, $urlGenerator);

        $result = $loader->loadPublicArticle(self::HEX, 'missing%20slug', null);

        self::assertTrue($result->isLoading());
        self::assertSame('article:' . md5(self::HEX . ':missing slug'), $result->lookupKey);
        self::assertSame('/p/' . self::HEX . '/d/missing%20slug', $result->reloadUrl);
    }

    public function testReadyArticleUsesCachedHtmlAndBuildsTemplateParameters(): void
    {
        $article = (new Article())
            ->setPubkey(self::HEX)
            ->setSlug('ready-slug')
            ->setKind(KindsEnum::LONGFORM)
            ->setContent('raw markdown')
            ->setProcessedHtml('<p>Cached</p>');
        $article->setRaw(['tags' => [['-']]]);

        $repository = $this->createMock(ArticleRepository::class);
        $repository->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'ready-slug', 'pubkey' => self::HEX], ['createdAt' => 'DESC'])
            ->willReturn($article);

        $converter = $this->createMock(Converter::class);
        $converter->expects(self::never())->method('convertToHTML');

        $redis = $this->createMock(RedisCacheService::class);
        $redis->expects(self::once())
            ->method('getMetadata')
            ->with(self::HEX)
            ->willReturn(new UserMetadata(name: 'Ada'));

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->expects(self::once())
            ->method('generate')
            ->with('author-article-slug', ['npub' => self::HEX, 'slug' => 'ready-slug'], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://example.test/p/' . self::HEX . '/d/ready-slug');

        $loader = $this->loader($repository, $bus, $urlGenerator, $redis, $converter);

        $result = $loader->loadPublicArticle(self::HEX, 'ready-slug', $this->viewer(self::HEX));
        $params = $result->articleTemplateParameters();

        self::assertTrue($result->isReady());
        self::assertSame($article, $params['article']);
        self::assertSame('<p>Cached</p>', $params['content']);
        self::assertTrue($params['canEdit']);
        self::assertSame('https://example.test/p/' . self::HEX . '/d/ready-slug', $params['canonical']);
        self::assertSame('Ada', $params['author']->name);
        self::assertTrue($params['advancedMetadata']->isProtected);
    }

    private function loader(
        ArticleRepository&MockObject $repository,
        MessageBusInterface&MockObject $bus,
        UrlGeneratorInterface&MockObject $urlGenerator,
        ?RedisCacheService $redis = null,
        ?Converter $converter = null,
    ): ArticlePageLoader {
        $identityService = new NostrIdentityService(
            $this->createMock(Nip19DecoderInterface::class),
            $this->createMock(Nip19EncoderInterface::class),
        );

        return new ArticlePageLoader(
            $repository,
            $redis ?? $this->createMock(RedisCacheService::class),
            $converter ?? $this->createMock(Converter::class),
            new NullLogger(),
            new NostrEventParser(),
            new EmbedReferenceExtractor(new NullLogger()),
            $bus,
            $identityService,
            $urlGenerator,
            new ArticleAccessService($identityService),
        );
    }

    private function viewer(string $identifier): UserInterface
    {
        return new class($identifier) implements UserInterface {
            public function __construct(private readonly string $identifier)
            {
            }

            public function getRoles(): array
            {
                return [];
            }

            public function eraseCredentials(): void
            {
            }

            public function getUserIdentifier(): string
            {
                return $this->identifier;
            }
        };
    }
}