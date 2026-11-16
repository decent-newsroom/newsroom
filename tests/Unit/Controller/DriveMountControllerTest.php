<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\Reader\DriveMountController;
use App\Entity\Event;
use App\Entity\Article;
use App\Entity\User;
use App\Repository\ArticleRepository;
use App\Repository\DeletedEventRepository;
use App\Repository\EventRepository;
use App\Repository\HiddenCoordinateRepository;
use App\Service\EventWorkspace\DriveMountAccess;
use App\Service\EventWorkspace\DriveMountEventAccess;
use App\Service\EventWorkspace\DriveMountEventOpenSigner;
use App\Service\EventWorkspace\DriveMountPage;
use App\Service\EventWorkspace\EventPreviewAccessPolicy;
use App\Service\EventWorkspace\EventPresentationFactory;
use App\Service\EventWorkspace\EventWorkspaceSourceAdapter;
use App\Service\EventWorkspace\PublicationTocTraversalSigner;
use App\Service\EventWorkspace\TargetPreviewResolver;
use App\Service\Graph\RecordIdentityService;
use App\Service\Nostr\NostrNip19Service;
use App\Service\Nostr\NostrIdentityService;
use App\Service\Nostr\NostrKeyService;
use App\Service\Reader\ArticleAccessService;
use App\Service\Reader\ContentAuthorAccessPolicy;
use App\Service\UserMuteListService;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Route as RoutingRoute;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Twig\Environment;

final class DriveMountControllerTest extends TestCase
{
    private const READER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OWNER = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const DRIVE_ID = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const DIRECTORY_ID = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const CHAPTER_ID = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';
    private const DIRECTORY_COORDINATE = '30045:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:root';
    private const CHAPTER_COORDINATE = '30041:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb:chapter';

    public function testForeignDriveAndPinnedDirectoryTraversalRenderReadOnlyPrivateResponses(): void
    {
        $fixture = $this->fixture();
        $root = $fixture['controller']->mount(
            self::DRIVE_ID,
            new Request(),
            $fixture['access'],
            $fixture['pages'],
        );

        self::assertSame('rendered', $root->getContent());
        self::assertSame('no-store, private', $root->headers->get('Cache-Control'));
        $rootPage = $fixture['contexts'][0]['mountPage'];
        self::assertSame(self::DIRECTORY_ID, $rootPage['entries'][0]['directory']['eventId']);
        self::assertNotEmpty($rootPage['entries'][0]['nestedSignature']);
        self::assertArrayNotHasKey('targetUrl', $rootPage['entries'][0]);

        $directory = $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootPage['entries'][0]['nestedOccurrenceId'],
                'signature' => $rootPage['entries'][0]['nestedSignature']]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );

        self::assertSame('rendered', $directory->getContent());
        self::assertSame('no-store, private', $directory->headers->get('Cache-Control'));
        $chapterEntry = $fixture['contexts'][1]['mountPage']['entries'][0];
        self::assertSame(self::CHAPTER_ID, $chapterEntry['rawVersionPin']);
        self::assertSame(self::CHAPTER_ID, $chapterEntry['targetResolution']->presentation->eventId);
        self::assertArrayNotHasKey('targetUrl', $chapterEntry);
        self::assertSame(self::CHAPTER_ID, $chapterEntry['openEvent']['targetId']);
        self::assertSame([self::DRIVE_ID, self::DIRECTORY_ID], $chapterEntry['openEvent']['path']);
    }

    public function testZeroPaddedDirectoryKindResolvesAndTraversesConsistently(): void
    {
        $fixture = $this->fixture(driveAddress: '030045:' . self::OWNER . ':root');
        $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $entry = $fixture['contexts'][0]['mountPage']['entries'][0];
        self::assertSame(self::DIRECTORY_ID, $entry['directory']['eventId']);

        $response = $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request([
                'path' => [self::DRIVE_ID],
                'occurrenceId' => $entry['nestedOccurrenceId'],
                'signature' => $entry['nestedSignature'],
            ]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
        self::assertSame('rendered', $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
    }

    public function testDeniedForeignRootReturnsNotFoundBeforeRendering(): void
    {
        $fixture = $this->fixture(['30042:' . self::OWNER . ':main']);
        $this->expectException(NotFoundHttpException::class);

        $fixture['controller']->mount(
            self::DRIVE_ID,
            new Request(),
            $fixture['access'],
            $fixture['pages'],
        );
    }

    public function testCommunityScopedForeignRootIsDeniedBeforeRendering(): void
    {
        $fixture = $this->fixture(communityScopedDrive: true);
        $this->expectException(NotFoundHttpException::class);

        $fixture['controller']->mount(
            self::DRIVE_ID,
            new Request(),
            $fixture['access'],
            $fixture['pages'],
        );
    }

    public function testDirectoryWithoutValidRawDTagCannotBePreviewedOrTraversed(): void
    {
        foreach ([[], [['d', 42], ['title', 'Malformed directory']]] as $directoryIdentityTags) {
            $fixture = $this->fixture(directoryTags: [
                ...$directoryIdentityTags,
                ['title', 'Directory identity'],
            ]);
            $root = $fixture['controller']->mount(
                self::DRIVE_ID,
                new Request(),
                $fixture['access'],
                $fixture['pages'],
            );
            $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
            self::assertSame('unavailable', $rootEntry['targetResolution']->status->value);
            self::assertNull($rootEntry['nestedSignature']);

            $signature = $fixture['signer']->sign(
                self::DIRECTORY_ID,
                1,
                [self::DRIVE_ID],
                str_repeat('f', 64),
                '30045:' . self::OWNER . ':',
            );
            try {
                $fixture['controller']->directory(
                    self::DRIVE_ID,
                    self::DIRECTORY_ID,
                    new Request([
                        'path' => [self::DRIVE_ID],
                        'occurrenceId' => str_repeat('f', 64),
                        'signature' => $signature,
                    ]),
                    $fixture['access'],
                    $fixture['pages'],
                    $fixture['signer'],
                );
                self::fail('A directory without a valid raw d tag must not be traversable.');
            } catch (NotFoundHttpException) {
                self::assertCount(1, $fixture['contexts']);
            }
        }
    }

    public function testDeniedForeignDirectoryCannotBeTraversedWithAValidSignedPath(): void
    {
        $fixture = $this->fixture(['30045:' . self::OWNER . ':root']);
        $occurrenceId = str_repeat('f', 64);
        $signature = $fixture['signer']->sign(
            self::DIRECTORY_ID,
            1,
            [self::DRIVE_ID],
            $occurrenceId,
            self::DIRECTORY_COORDINATE,
        );
        $this->expectException(NotFoundHttpException::class);

        $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request([
                'path' => [self::DRIVE_ID],
                'occurrenceId' => $occurrenceId,
                'signature' => $signature,
            ]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
    }

    public function testTamperedSignedPathIsRejectedAfterRootAndDirectoryReauthorization(): void
    {
        $fixture = $this->fixture();
        $root = $fixture['controller']->mount(
            self::DRIVE_ID,
            new Request(),
            $fixture['access'],
            $fixture['pages'],
        );
        $rootPage = $fixture['contexts'][0]['mountPage'];
        $tampered = str_repeat('0', 64);
        self::assertNotSame($rootPage['entries'][0]['nestedSignature'], $tampered);

        try {
            $fixture['controller']->directory(
                self::DRIVE_ID,
                self::DIRECTORY_ID,
                new Request(['path' => [self::DRIVE_ID],
                    'occurrenceId' => $rootPage['entries'][0]['nestedOccurrenceId'],
                    'signature' => $tampered]),
                $fixture['access'],
                $fixture['pages'],
                $fixture['signer'],
            );
            self::fail('A tampered traversal signature must not render a directory.');
        } catch (BadRequestHttpException) {
            self::assertCount(1, $fixture['contexts'], 'Only the initial root response may render.');
        }
    }

    public function testRoutesAreAuthenticatedGetOnlyAndRejectForgedPostActions(): void
    {
        $routes = new RouteCollection();
        foreach (['mount', 'directory', 'openEvent'] as $action) {
            $method = new \ReflectionMethod(DriveMountController::class, $action);
            $route = $method->getAttributes(Route::class)[0]->newInstance();
            self::assertSame(['GET'], $route->methods);
            self::assertSame('ROLE_USER', $method->getAttributes(IsGranted::class)[0]->newInstance()->attribute);
            $routes->add($route->name, new RoutingRoute($route->path, [], [], [], '', [], $route->methods));
        }

        $context = new RequestContext();
        $context->setMethod('POST');
        $matcher = new UrlMatcher($routes, $context);
        $this->expectException(MethodNotAllowedException::class);
        $matcher->match('/reading-nook/drives/' . self::DRIVE_ID . '/events/' . self::CHAPTER_ID);
    }

    public function testExactOpenReauthorizesSignedDirectoryOccurrenceAndRendersPrivateGenericBody(): void
    {
        $fixture = $this->fixture();
        $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
        $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request([
                'path' => [self::DRIVE_ID],
                'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                'signature' => $rootEntry['nestedSignature'],
            ]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
        $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];
        $response = $fixture['controller']->openEvent(
            self::DRIVE_ID,
            self::CHAPTER_ID,
            new Request([
                'path' => $open['path'],
                'position' => $open['position'],
                'occurrenceId' => $open['occurrenceId'],
                'signature' => $open['signature'],
            ]),
            $fixture['access'],
            $fixture['targetAccess'],
            $fixture['openSigner'],
            $fixture['presentations'],
            $fixture['events'],
        );

        self::assertSame('rendered', $response->getContent());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertCount(3, $fixture['contexts']);
        self::assertSame(self::CHAPTER_ID, $fixture['contexts'][2]['presentation']->eventId);
        self::assertSame('Chapter body', $fixture['contexts'][2]['presentation']->textContent);
    }

    public function testMediaOccurrencesOpenExactEventsWithSafeMetadataAndRecheckSensitiveAndScope(): void
    {
        foreach ([20, 21, 22] as $kind) {
            $media = $this->event(self::CHAPTER_ID, self::OWNER, $kind, [
                ['title', '<Media>'],
                ['imeta', 'url https://media.invalid/image', 'm image/png', 'alt <unsafe>', 'secret ignored'],
            ], '<script>body</script>');
            $fixture = $this->fixture(
                directoryTags: [['d', 'root'], ['e', self::CHAPTER_ID]],
                target: $media,
            );
            $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
            $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
            $fixture['controller']->directory(
                self::DRIVE_ID, self::DIRECTORY_ID,
                new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                    'signature' => $rootEntry['nestedSignature']]),
                $fixture['access'], $fixture['pages'], $fixture['signer'],
            );
            $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];
            self::assertSame(self::CHAPTER_ID, $open['targetId']);
            $request = new Request(['path' => $open['path'], 'position' => $open['position'],
                'occurrenceId' => $open['occurrenceId'], 'signature' => $open['signature']]);
            $response = $fixture['controller']->openEvent(
                self::DRIVE_ID, self::CHAPTER_ID, $request, $fixture['access'], $fixture['targetAccess'],
                $fixture['openSigner'], $fixture['presentations'], $fixture['events'],
            );
            self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
            self::assertSame($kind, $fixture['contexts'][2]['presentation']->kind);
            self::assertSame(self::CHAPTER_ID, $fixture['contexts'][2]['presentation']->eventId);
            self::assertSame('<script>body</script>', $fixture['contexts'][2]['mediaTextContent']);
            self::assertSame(['url https://media.invalid/image', 'm image/png', 'alt <unsafe>'],
                $fixture['contexts'][2]['mediaMetadata']);

            foreach ([['content-warning', 'warning'], ['h', 'private']] as $denial) {
                $media->setTags([$denial]);
                try {
                    $fixture['controller']->openEvent(
                        self::DRIVE_ID, self::CHAPTER_ID, $request, $fixture['access'], $fixture['targetAccess'],
                        $fixture['openSigner'], $fixture['presentations'], $fixture['events'],
                    );
                    self::fail('A target whose policy changed must not open.');
                } catch (NotFoundHttpException) {
                    self::assertCount(3, $fixture['contexts']);
                }
                $freshPage = $fixture['pages']->fromAuthorizedEvent(
                    $fixture['directory'], ancestorPath: [self::DRIVE_ID],
                    driveId: self::DRIVE_ID, readerPubkey: self::READER,
                );
                self::assertArrayNotHasKey('openEvent', $freshPage['entries'][0]);
            }
        }
    }

    public function testPublicationAudienceChangeInvalidatesAFormerlyReadableOpenLink(): void
    {
        foreach ([30040, 30041] as $kind) {
            $target = $this->event(self::CHAPTER_ID, self::OWNER, $kind, [['d', 'public']], 'body');
            $fixture = $this->fixture(
                directoryTags: [['d', 'root'], ['e', self::CHAPTER_ID]],
                target: $target,
            );
            $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
            $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
            $fixture['controller']->directory(
                self::DRIVE_ID, self::DIRECTORY_ID,
                new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                    'signature' => $rootEntry['nestedSignature']]),
                $fixture['access'], $fixture['pages'], $fixture['signer'],
            );
            $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];
            $target->setTags([['d', 'public'], ['s', '30879:' . self::OWNER . ':members']]);
            try {
                $fixture['controller']->openEvent(
                    self::DRIVE_ID, self::CHAPTER_ID,
                    new Request(['path' => $open['path'], 'position' => $open['position'],
                        'occurrenceId' => $open['occurrenceId'], 'signature' => $open['signature']]),
                    $fixture['access'], $fixture['targetAccess'], $fixture['openSigner'],
                    $fixture['presentations'], $fixture['events'],
                );
                self::fail('A newly audience-scoped publication must not open.');
            } catch (NotFoundHttpException) {
                self::assertCount(2, $fixture['contexts']);
            }
        }
    }

    public function testUnpinnedAddressOpensTheCurrentLocalCoordinateWinner(): void
    {
        $fixture = $this->fixture(directoryTags: [
            ['d', 'root'],
            ['title', 'Shared directory'],
            ['a', self::CHAPTER_COORDINATE, 'wss://relay.example'],
        ]);
        $fixture['events']->expects(self::once())->method('findWorkspaceDriveOpenTargets')
            ->with([self::CHAPTER_COORDINATE], [])
            ->willReturn(['coordinates' => [self::CHAPTER_COORDINATE => $this->event(
                self::CHAPTER_ID,
                self::OWNER,
                30041,
                [['d', 'chapter'], ['title', 'Pinned chapter']],
                'Chapter body',
            )], 'eventIds' => []]);
        $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
        $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                'signature' => $rootEntry['nestedSignature']]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
        $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];
        $response = $fixture['controller']->openEvent(
            self::DRIVE_ID,
            self::CHAPTER_ID,
            new Request(['path' => $open['path'], 'position' => $open['position'],
                'occurrenceId' => $open['occurrenceId'], 'signature' => $open['signature']]),
            $fixture['access'],
            $fixture['targetAccess'],
            $fixture['openSigner'],
            $fixture['presentations'],
            $fixture['events'],
        );

        self::assertSame('rendered', $response->getContent());
        self::assertSame(self::CHAPTER_ID, $fixture['contexts'][2]['presentation']->eventId);
    }

    public function testMissingExactPinDoesNotFallBackToCurrentCoordinateWinner(): void
    {
        $fixture = $this->fixture();
        $fixture['events']->expects(self::never())->method('findWorkspaceDriveOpenTargets');
        $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $missingPin = str_repeat('9', 64);
        $rawTag = ['a', self::CHAPTER_COORDINATE, 'wss://relay.example', $missingPin];
        $fixture['directory']->setTags([
            ['d', 'root'],
            ['title', 'Shared directory'],
            $rawTag,
        ]);
        $path = [self::DRIVE_ID, self::DIRECTORY_ID];
        $occurrenceId = $fixture['openSigner']->occurrenceId(
            self::DRIVE_ID,
            $path,
            $missingPin,
            2,
            $rawTag,
        );
        $signature = $fixture['openSigner']->sign(
            self::DRIVE_ID,
            $path,
            $missingPin,
            2,
            $rawTag,
            $occurrenceId,
        );
        $this->expectException(NotFoundHttpException::class);

        $fixture['controller']->openEvent(
            self::DRIVE_ID,
            $missingPin,
            new Request(['path' => $path, 'position' => 2,
                'occurrenceId' => $occurrenceId, 'signature' => $signature]),
            $fixture['access'],
            $fixture['targetAccess'],
            $fixture['openSigner'],
            $fixture['presentations'],
            $fixture['events'],
        );
    }

    public function testTamperedOccurrenceSignatureIsRejectedAndNoTargetPageIsRendered(): void
    {
        $fixture = $this->fixture();
        $root = $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
        $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                'signature' => $rootEntry['nestedSignature']]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
        $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];

        try {
            $fixture['controller']->openEvent(
                self::DRIVE_ID,
                self::CHAPTER_ID,
                new Request(['path' => $open['path'], 'position' => $open['position'],
                    'occurrenceId' => $open['occurrenceId'], 'signature' => str_repeat('0', 64)]),
                $fixture['access'],
                $fixture['targetAccess'],
                $fixture['openSigner'],
                $fixture['presentations'],
                $fixture['events'],
            );
            self::fail('A forged open signature must be rejected.');
        } catch (BadRequestHttpException) {
            self::assertCount(2, $fixture['contexts']);
        }
    }

    public function testChangedSourceOccurrenceInvalidatesPreviouslySignedOpenLink(): void
    {
        $fixture = $this->fixture();
        $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
        $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                'signature' => $rootEntry['nestedSignature']]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
        $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];
        $fixture['directory']->setTags([
            ['d', 'root'],
            ['title', 'Shared directory'],
            ['a', self::CHAPTER_COORDINATE, 'wss://relay.example', str_repeat('8', 64)],
        ]);

        try {
            $fixture['controller']->openEvent(
                self::DRIVE_ID,
                self::CHAPTER_ID,
                new Request(['path' => $open['path'], 'position' => $open['position'],
                    'occurrenceId' => $open['occurrenceId'], 'signature' => $open['signature']]),
                $fixture['access'],
                $fixture['targetAccess'],
                $fixture['openSigner'],
                $fixture['presentations'],
                $fixture['events'],
            );
            self::fail('A source occurrence change must invalidate the old link.');
        } catch (BadRequestHttpException) {
            self::assertCount(2, $fixture['contexts']);
        }
    }

    public function testPolicyChangeAfterLinkCreationHidesExactTarget(): void
    {
        $fixture = $this->fixture(deleteTarget: true);
        $fixture['controller']->mount(self::DRIVE_ID, new Request(), $fixture['access'], $fixture['pages']);
        $rootEntry = $fixture['contexts'][0]['mountPage']['entries'][0];
        $fixture['controller']->directory(
            self::DRIVE_ID,
            self::DIRECTORY_ID,
            new Request(['path' => [self::DRIVE_ID], 'occurrenceId' => $rootEntry['nestedOccurrenceId'],
                'signature' => $rootEntry['nestedSignature']]),
            $fixture['access'],
            $fixture['pages'],
            $fixture['signer'],
        );
        $open = $fixture['contexts'][1]['mountPage']['entries'][0]['openEvent'];
        $this->expectException(NotFoundHttpException::class);

        $fixture['controller']->openEvent(
            self::DRIVE_ID,
            self::CHAPTER_ID,
            new Request(['path' => $open['path'], 'position' => $open['position'],
                'occurrenceId' => $open['occurrenceId'], 'signature' => $open['signature']]),
            $fixture['access'],
            $fixture['targetAccess'],
            $fixture['openSigner'],
            $fixture['presentations'],
            $fixture['events'],
        );
    }

    /**
     * @param list<string> $hiddenCoordinates
     * @return array{
     *   controller: DriveMountController,
     *   access: DriveMountAccess,
     *   pages: DriveMountPage,
     *   signer: PublicationTocTraversalSigner,
     *   contexts: list<array<string, mixed>>
     * }
     */
    private function fixture(
        array $hiddenCoordinates = [],
        bool $communityScopedDrive = false,
        ?array $directoryTags = null,
        string $driveAddress = self::DIRECTORY_COORDINATE,
        bool $deleteTarget = false,
        ?Event $target = null,
    ): array
    {
        $driveTags = [
            ['d', 'main'],
            // NKBIP drive tag slot 3 is not a directory version pin.
            ['a', $driveAddress, 'wss://relay.example', str_repeat('9', 64)],
        ];
        if ($communityScopedDrive) {
            $driveTags[] = ['a', '34550:' . self::OWNER . ':private-community'];
        }
        $drive = $this->event(self::DRIVE_ID, self::OWNER, 30042, $driveTags);
        $directory = $this->event(self::DIRECTORY_ID, self::OWNER, 30045, $directoryTags ?? [
                ['d', 'root'],
                ['title', 'Shared directory'],
                ['a', self::CHAPTER_COORDINATE, 'wss://relay.example', self::CHAPTER_ID],
            ]);
        $chapter = $target ?? $this->event(self::CHAPTER_ID, self::OWNER, 30041, [
            ['d', 'chapter'],
            ['title', 'Pinned chapter'],
        ], 'Chapter body');
        $events = $this->createMock(EventRepository::class);
        $events->method('findOneBy')->willReturnCallback(static function (array $criteria) use ($drive, $directory): ?Event {
            return match ($criteria['id'] ?? '') {
                self::DRIVE_ID => $drive,
                self::DIRECTORY_ID => $directory,
                default => null,
            };
        });
        $events->method('findById')->willReturnCallback(
            static fn (string $id): ?Event => $id === self::CHAPTER_ID ? $chapter : null,
        );
        $events->method('findWorkspaceDirectoryTargets')->willReturnCallback(
            static function (array $coordinates, array $eventIds) use ($directory): array {
                return [
                    'coordinates' => in_array(self::DIRECTORY_COORDINATE, $coordinates, true)
                        ? [self::DIRECTORY_COORDINATE => $directory] : [],
                    'eventIds' => in_array(self::DIRECTORY_ID, $eventIds, true)
                        ? [self::DIRECTORY_ID => $directory] : [],
                ];
            },
        );
        $events->method('findWorkspacePreviewTargets')->willReturnCallback(
            static function (array $coordinates, array $eventIds) use ($chapter): array {
                return [
                    'coordinates' => in_array(self::CHAPTER_COORDINATE, $coordinates, true)
                        ? [self::CHAPTER_COORDINATE => $chapter] : [],
                    'eventIds' => in_array(self::CHAPTER_ID, $eventIds, true)
                        ? [self::CHAPTER_ID => $chapter] : [],
                ];
            },
        );
        $events->method('findWorkspaceDriveOpenTargets')->willReturnCallback(
            static fn (array $coordinates, array $eventIds): array => [
                'coordinates' => in_array(self::CHAPTER_COORDINATE, $coordinates, true)
                    ? [self::CHAPTER_COORDINATE => $chapter] : [],
                'eventIds' => [],
            ],
        );
        $deletions = $this->createMock(DeletedEventRepository::class);
        $deletions->method('isSuppressed')->willReturnCallback(
            static fn (string $eventId): bool => $deleteTarget && strtolower($eventId) === self::CHAPTER_ID,
        );
        $deletions->method('findSuppressedEventIds')->willReturn([]);
        $hidden = $this->createMock(HiddenCoordinateRepository::class);
        $hidden->method('findHiddenCoordinates')->willReturnCallback(
            static fn (): array => $hiddenCoordinates,
        );
        $authors = $this->createMock(ContentAuthorAccessPolicy::class);
        $authors->method('suppressedPubkeys')->willReturn([]);
        $mutes = $this->createMock(UserMuteListService::class);
        $mutes->method('getMutedPubkeysStrict')->willReturn([]);
        $access = new DriveMountAccess($events, $deletions, $hidden, $authors, $mutes, new NullLogger());

        $factory = new EventPresentationFactory(new RecordIdentityService(), new NostrNip19Service());
        $user = new User();
        $user->setNpub(PublicKey::fromHex(self::READER)->toBech32());
        $security = $this->createMock(\Symfony\Bundle\SecurityBundle\Security::class);
        $security->method('getUser')->willReturn($user);
        $targets = new TargetPreviewResolver($events, $deletions, $hidden, $authors, $factory, $security, $mutes);
        $signer = new PublicationTocTraversalSigner('controller-test-secret');
        $pages = new DriveMountPage(
            new EventWorkspaceSourceAdapter(new RecordIdentityService()),
            $factory,
            $targets,
            $signer,
            $openSigner = new DriveMountEventOpenSigner('drive-open-test-secret'),
        );
        $keys = new NostrKeyService();
        $identityDecoder = $this->createMock(\DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface::class);
        $identityEncoder = $this->createMock(\DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface::class);
        $articleAccess = new ArticleAccessService(new NostrIdentityService($identityDecoder, $identityEncoder));
        $eventAccess = new EventPreviewAccessPolicy(
            $authors,
            $deletions,
            $hidden,
            new RecordIdentityService(),
            $security,
            $mutes,
            $keys,
            new NullLogger(),
            $articleAccess,
        );
        $articleRepository = $this->createMock(ArticleRepository::class);
        $targetAccess = new DriveMountEventAccess($events, $articleRepository, $eventAccess);

        $contexts = [];
        $twig = $this->createMock(Environment::class);
        $twig->method('render')->willReturnCallback(
            static function (string $template, array $context) use (&$contexts): string {
                $contexts[] = $context;

                return 'rendered';
            },
        );
        $storage = new TokenStorage();
        $storage->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
        $container = new Container();
        $container->set('security.token_storage', $storage);
        $container->set('twig', $twig);
        $controller = new DriveMountController();
        $controller->setContainer($container);

        return [
            'controller' => $controller,
            'access' => $access,
            'pages' => $pages,
            'signer' => $signer,
            'openSigner' => $openSigner,
            'targetAccess' => $targetAccess,
            'presentations' => $factory,
            'events' => $events,
            'directory' => $directory,
            'contexts' => &$contexts,
        ];
    }

    /** @param list<array<mixed>> $tags */
    private function event(string $id, string $pubkey, int $kind, array $tags, string $content = ''): Event
    {
        $event = new Event();
        $event->setId($id);
        $event->setPubkey($pubkey);
        $event->setKind($kind);
        $event->setCreatedAt(123);
        $event->setTags($tags);
        $event->setContent($content);
        $event->setSig(str_repeat('f', 128));

        return $event;
    }
}
