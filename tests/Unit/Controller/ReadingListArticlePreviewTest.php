<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Newsroom\ReadingListController;
use App\Service\Nostr\NostrNip19Service;
use DecentNewsroom\UnfoldBundle\Contract\ContentPreviewProviderInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;

final class ReadingListArticlePreviewTest extends TestCase
{
    public function testEndpointUsesSharedPreviewAndPreservesSignedIdentifier(): void
    {
        $coordinate = '30041:' . str_repeat('A', 64) . ': Signed:Identifier ';
        $preview = ['title' => 'Publication content', 'author' => 'Author'];
        $provider = $this->createMock(ContentPreviewProviderInterface::class);
        $provider->expects(self::once())->method('findByCoordinates')->with([$coordinate])
            ->willReturn([$coordinate => $preview]);

        self::assertSame($preview, $this->response(json_encode(['coordinate' => $coordinate]), $provider));
    }

    public function testMissingPreviewRetainsExistingJsonShape(): void
    {
        $provider = $this->createMock(ContentPreviewProviderInterface::class);
        $provider->method('findByCoordinates')->willReturn([]);

        self::assertSame([
            'title' => null, 'author' => null, 'error' => 'Article not found locally',
        ], $this->response(json_encode(['coordinate' => '30023:' . str_repeat('a', 64) . ':missing']), $provider));
    }

    public function testNaddrTransportWhitespaceIsTrimmedButIdentifierIsNot(): void
    {
        $pubkey = str_repeat('a', 64);
        $identifier = ' Signed:Identifier ';
        $coordinate = '30818:' . $pubkey . ':' . $identifier;
        $naddr = (new NostrNip19Service())->encodeAddr($pubkey, $identifier, 30818);
        $preview = ['title' => null, 'author' => 'Author'];
        $provider = $this->createMock(ContentPreviewProviderInterface::class);
        $provider->expects(self::once())->method('findByCoordinates')->with([$coordinate])
            ->willReturn([$coordinate => $preview]);

        self::assertSame($preview, $this->response(json_encode(['coordinate' => '  nostr:' . $naddr . '  ']), $provider));
    }

    /** @dataProvider invalidRequests */
    public function testInvalidRequestsDoNotReachProvider(string $body, string $error): void
    {
        $provider = $this->createMock(ContentPreviewProviderInterface::class);
        $provider->expects(self::never())->method('findByCoordinates');

        self::assertSame(['error' => $error], $this->response($body, $provider));
    }

    public function invalidRequests(): array
    {
        return [
            ['{', 'No coordinate provided'],
            ['null', 'No coordinate provided'],
            ['{"coordinate":[]}', 'No coordinate provided'],
            ['{"coordinate":0}', 'No coordinate provided'],
            ['{"coordinate":"  "}', 'No coordinate provided'],
            ['{"coordinate":"foo:bar:baz"}', 'Invalid coordinate format'],
            ['{"coordinate":"30023:invalid:slug"}', 'Invalid coordinate format'],
            ['{"coordinate":" nostr:naddr1invalid "}', 'Invalid naddr format'],
        ];
    }

    private function response(string $body, ContentPreviewProviderInterface $provider): array
    {
        $controller = new ReadingListController();
        $controller->setContainer(new Container());
        $request = Request::create('/api/reading-list/article-preview', 'POST', content: $body);

        return json_decode($controller->articlePreview($request, $provider)->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
