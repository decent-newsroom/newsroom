<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\Controller\User\SettingsController;
use App\Dto\UserMetadata;
use App\Entity\Event;
use App\Entity\User;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\Nostr\PaymentTargetService;
use App\Service\Nostr\RelayRegistry;
use App\Service\Nostr\RelayUserActivityStore;
use App\Service\Nostr\UserProfileService;
use App\Service\PublicationSubdomainService;
use App\Service\VanityNameService;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class SettingsFollowPackControllerTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function creationModes(): iterable
    {
        yield 'normal entry edits selected pack' => [false];
        yield 'new shortcut starts an empty pack' => [true];
    }

    /** @dataProvider creationModes */
    public function testNewPackDoesNotOverwriteSelectedPack(bool $new): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            self::markTestSkipped('The isolated follow-pack query requires pdo_sqlite.');
        }
        $config = ORMSetup::createAttributeMetadataConfig([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        } else {
            $config->setProxyDir(sys_get_temp_dir());
            $config->setProxyNamespace('Tests\DoctrineProxies');
        }
        $database = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        $database->getClassMetadata(Event::class)->setCustomRepositoryClass(\Doctrine\ORM\EntityRepository::class);
        (new SchemaTool($database))->createSchema([$database->getClassMetadata(Event::class)]);
        $owner = str_repeat('a', 64);
        $member = str_repeat('b', 64);
        $coordinate = '39089:' . $owner . ':writers';
        $pack = new Event();
        $pack->setId(str_repeat('c', 64));
        $pack->setPubkey($owner);
        $pack->setKind(39089);
        $pack->setCreatedAt(123);
        $pack->setTags([
            ['d', 'writers'], ['title', 'My writers'], ['image', 'https://example.com/pack.jpg'],
            ['description', 'Recommended writers'], ['p', $member],
        ]);
        $pack->setDTag('writers');
        $database->persist($pack);
        $database->flush();

        $repository = $this->createMock(EventRepository::class);
        $repository->method('findLatestByPubkeyAndKind')->willReturn(null);
        $repository->expects(self::once())->method('createQueryBuilder')->with('e')
            ->willReturn($database->createQueryBuilder()->select('e')->from(Event::class, 'e'));
        $profiles = $this->createMock(UserProfileService::class);
        $profiles->expects(self::never())->method('fetchUserContext');
        $cache = $this->createMock(RedisCacheService::class);
        $cache->expects($new ? self::never() : self::once())->method('getMetadata')
            ->with($member)->willReturn(new UserMetadata(name: 'Writer'));
        $controller = $this->getMockBuilder(SettingsController::class)
            ->setConstructorArgs([
                $repository, $database, $this->createMock(CacheItemPoolInterface::class),
                $this->createMock(VanityNameService::class),
                $this->createMock(PublicationSubdomainService::class),
                $profiles, $cache, $this->createMock(RelayRegistry::class),
                new NullLogger(), $this->createMock(RelayUserActivityStore::class),
                new PaymentTargetService($repository),
            ])
            ->onlyMethods(['getUser', 'render'])
            ->getMock();
        $user = new User();
        $user->setNpub(PublicKey::fromHex($owner)->toBech32());
        $user->setFollowPackCoordinate($coordinate);
        $controller->method('getUser')->willReturn($user);
        $controller->expects(self::once())->method('render')
            ->willReturnCallback(static function (string $template, array $parameters) use ($new, $coordinate): Response {
                self::assertSame('settings/follow-pack.html.twig', $template);
                self::assertSame($coordinate, $parameters['selectedFollowPackCoordinate']);
                self::assertCount(1, $parameters['existingFollowPacks']);
                self::assertSame($new ? '' : 'writers', $parameters['existingPackDtag']);
                self::assertSame($new ? '' : 'My writers', $parameters['existingPackTitle']);
                self::assertSame($new ? '' : 'https://example.com/pack.jpg', $parameters['existingPackImage']);
                self::assertSame($new ? '' : 'Recommended writers', $parameters['existingPackDescription']);
                self::assertCount($new ? 0 : 1, $parameters['existingPackMembers']);

                return new Response();
            });

        self::assertSame(200, $controller->followPackSetup(new Request($new ? ['new' => '1'] : []))->getStatusCode());
        self::assertSame($coordinate, $user->getFollowPackCoordinate());
        $database->close();
    }
}
