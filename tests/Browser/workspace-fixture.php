<?php

declare(strict_types=1);

// This entry point is only for the isolated Docker browser fixture. It is not
// under public/ and must never be used by the normal web server.
if (PHP_SAPI !== 'cli-server' && (PHP_SAPI !== 'cli' || !in_array($argv[1] ?? '', ['seed', 'compile'], true))) {
    exit(1);
}
if (getenv('WORKSPACE_BROWSER_FIXTURE') !== 'isolated') {
    exit('The isolated browser fixture must be explicitly enabled.');
}

use App\Entity\User;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$fixtureEnv = [
    'APP_ENV' => 'test',
    'APP_DEBUG' => '0',
    'APP_SECRET' => 'workspace-browser-fixture-secret',
    'DATABASE_URL' => 'postgresql://fixture:fixture@newsroom-workspace-browser-db:5432/workspace_fixture?serverVersion=17&charset=utf8',
    'REDIS_HOST' => 'newsroom-workspace-browser-redis',
    'REDIS_PORT' => '6379',
    'REDIS_PASSWORD' => '',
    'MESSENGER_TRANSPORT_DSN' => 'in-memory://',
];
foreach ($fixtureEnv as $key => $value) {
    $_ENV[$key] = $_SERVER[$key] = $value;
    putenv($key . '=' . $value);
}

if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = realpath(dirname(__DIR__, 2) . '/public' . $path);
    if ($file !== false && str_starts_with($file, realpath(dirname(__DIR__, 2) . '/public') . DIRECTORY_SEPARATOR) && is_file($file)) {
        return false;
    }
}

$kernel = new Kernel('test', false);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(EntityManagerInterface::class);
$connection = $em->getConnection();
if ($connection->fetchOne('SELECT current_database()') !== 'workspace_fixture'
    || $connection->getParams()['host'] !== 'newsroom-workspace-browser-db') {
    throw new RuntimeException('Refusing to use a non-fixture database.');
}

$owner = str_repeat('a', 64);
if (PHP_SAPI === 'cli' && $argv[1] === 'compile') {
    $application = new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    $application->setAutoExit(false);
    exit($application->run(new \Symfony\Component\Console\Input\ArrayInput(['command' => 'asset-map:compile'])));
}
if (PHP_SAPI === 'cli') {
    if ($connection->createSchemaManager()->listTableNames() !== []) {
        throw new RuntimeException('Fixture database must be empty.');
    }
    (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());
    $connection->insert('app_user', [
        'npub' => PublicKey::fromHex($owner)->toBech32(),
        'roles' => '[]',
    ]);

    $blocked = str_repeat('c', 64);
    $connection->insert('banned_pubkey', [
        'pubkey' => $blocked, 'reason' => 'synthetic fixture', 'added_at' => '2026-01-01 00:00:00',
        'added_by' => 'fixture',
    ]);
    $root = str_repeat('1', 64);
    $child = str_repeat('2', 64);
    $leaf = str_repeat('3', 64);
    $chapter = str_repeat('4', 64);
    $hidden = str_repeat('5', 64);
    $items = [
        [$root, $owner, 30040, 'root', 'Synthetic root', [
            ['a', '30040:' . $owner . ':child'],
            ['a', '30040:' . $owner . ':child'],
            ['a', '30040:' . $owner . ':missing'],
            ['a', '30040:' . $blocked . ':restricted'],
            ['a', '30041:' . $owner . ':chapter', '', $chapter],
            ['a', '30041:' . $owner . ':missing-pinned', '', str_repeat('f', 64)],
            ['a', '30040:' . $owner . ':depth-1'],
        ]],
        [$child, $owner, 30040, 'child', 'Synthetic shared child', [
            ['a', '30040:' . $owner . ':root'],
            ['a', '30040:' . $owner . ':leaf'],
        ]],
        [$leaf, $owner, 30040, 'leaf', 'Synthetic leaf', []],
        [$chapter, $owner, 30041, 'chapter', 'Synthetic chapter', []],
        [$hidden, $blocked, 30040, 'restricted', 'NEVER EXPOSE RESTRICTED TITLE', []],
    ];
    for ($i = 6; $i <= 11; ++$i) {
        $items[] = [str_repeat(dechex($i), 64), $owner, 30040, 'extra-' . $i, 'Synthetic extra ' . $i, []];
    }
    for ($i = 1; $i <= 6; ++$i) {
        $next = $i < 6 ? [['a', '30040:' . $owner . ':depth-' . ($i + 1)]] : [];
        $items[] = [str_pad(dechex(32 + $i), 64, '0', STR_PAD_LEFT), $owner, 30040, 'depth-' . $i, 'Synthetic depth ' . $i, $next];
    }
    $members = [];
    for ($i = 1; $i <= 1000; ++$i) {
        $members[] = ['p', str_pad(dechex($i), 64, '0', STR_PAD_LEFT)];
    }
    $items[] = [str_repeat('e', 64), $owner, 39089, 'members', 'Synthetic thousand members', $members];
    foreach ($items as [$id, $author, $kind, $d, $title, $references]) {
        $connection->insert('event', [
            'id' => $id,
            'event_id' => $id,
            'kind' => $kind,
            'pubkey' => $author,
            'content' => $kind === 30041 ? 'Synthetic chapter body' : '',
            'created_at' => 1700000000,
            'tags' => json_encode([['d', $d], ['title', $title], ...($id === $root ? [['t', 'fixture']] : []), ...$references], JSON_THROW_ON_ERROR),
            'sig' => str_repeat('0', 128),
            'd_tag' => $d,
        ]);
    }
    $connection->insert('event', [
        'id' => str_repeat('0', 64), 'event_id' => str_repeat('0', 64),
        'pubkey' => $owner, 'kind' => 3, 'created_at' => 1700000000,
        'content' => '', 'tags' => '[]', 'sig' => str_repeat('0', 128), 'd_tag' => null,
    ]);
    $writer = str_repeat('b', 64);
    foreach ([
        ['e1', 'book', 'Synthetic book', [], 1800000005],
        ['e2', 'magazine', 'Synthetic magazine', [['a', '30040:' . $writer . ':section']], 1800000004],
        ['e3', 'scoped', 'NEVER SCOPED', [['s', 'private']], 1800000003],
        ['e4', 'scoped', 'NEVER OLD SCOPED', [], 1800000002],
        ['e5', 'hidden', 'NEVER HIDDEN', [], 1800000001],
        ['e6', 'sensitive', 'NEVER SENSITIVE', [['content-warning']], 1800000000],
    ] as [$suffix, $slug, $title, $extraTags, $created]) {
        $id = str_pad($suffix, 64, '0', STR_PAD_LEFT);
        $connection->insert('event', [
            'id' => $id, 'event_id' => $id, 'pubkey' => $writer, 'kind' => 30040,
            'created_at' => $created, 'content' => '',
            'tags' => json_encode([['d', $slug], ['title', $title], ...$extraTags], JSON_THROW_ON_ERROR),
            'sig' => str_repeat('0', 128), 'd_tag' => $slug,
        ]);
    }
    $connection->insert('hidden_coordinate', [
        'coordinate' => '30040:' . $writer . ':hidden', 'reason' => 'fixture',
        'created_at' => '2026-01-01 00:00:00',
    ]);
    foreach ([
        [str_repeat('d', 64), 30023, 'article', 'Synthetic article', [
            ['t', 'nostr'], ['t', 'fixture'], ['p', $owner],
            ['r', 'https://example.com/' . str_repeat('long-source-', 20)],
            ['lang', 'sl'], ['client', 'Newsroom'],
        ]],
        [str_repeat('f', 64), 30024, 'draft', 'Synthetic draft', [['t', 'draft-topic']]],
        [str_pad('f1', 64, '0', STR_PAD_LEFT), 30023, 'sensitive', 'Synthetic sensitive article', [
            ['t', 'NEVER-EXPOSE-SENSITIVE-TOPIC'], ['content-warning', 'Sensitive reason'],
        ]],
    ] as [$id, $kind, $slug, $title, $metadataTags]) {
        $raw = [
            'id' => $id, 'pubkey' => $owner, 'kind' => $kind, 'created_at' => 1700000000,
            'tags' => [['d', $slug], ['title', $title], ...$metadataTags], 'content' => 'Synthetic article body',
        ];
        $connection->insert('article', [
            'event_id' => $id, 'pubkey' => $owner, 'kind' => $kind, 'slug' => $slug,
            'title' => $title, 'summary' => 'Synthetic article summary',
            'created_at' => gmdate('Y-m-d H:i:s', 1700000000),
            'sig' => str_repeat('0', 128), 'content' => 'Synthetic article body',
            'raw' => json_encode($raw, JSON_THROW_ON_ERROR),
        ]);
    }
    echo "Synthetic schema and events created.\n";
    exit(0);
}

$dispatcher = $container->get('event_dispatcher');
$dispatcher->addListener(KernelEvents::REQUEST, static function (\Symfony\Component\HttpKernel\Event\RequestEvent $event) use ($container, $owner): void {
    if ($event->getRequest()->getPathInfo() !== '/__media-gallery-fixture') {
        return;
    }
    $twig = $container->get('twig');
    $twig->setLoader(new \Twig\Loader\ChainLoader([$twig->getLoader(), new \Twig\Loader\FilesystemLoader(__DIR__)]));
    $event->setResponse(new Response($twig->render('media-gallery.html.twig', [
        'media' => (object) [
            'id' => str_repeat('9', 64),
            'noteId' => $container->get(\App\Service\Nostr\NostrNip19Service::class)->encodeNote(str_repeat('9', 64)),
            'pubkey' => $owner, 'kind' => 20,
            'content' => "A **photo** mentioned in the text: https://gallery.example/one.png\n\n![Second photo](https://gallery.example/two.png)",
            'tags' => [
                ['imeta', 'url https://gallery.example/one.png', 'alt First photo', 'dim 800x600'],
                ['imeta', 'url https://gallery.example/broken.png', 'alt Second photo', 'fallback https://gallery.example/two.png'],
                ['imeta', 'url https://gallery.example/unavailable.png', 'alt Unavailable photo'],
            ],
        ],
    ])));
}, 100);
$dispatcher->addListener(KernelEvents::REQUEST, static function (\Symfony\Component\HttpKernel\Event\RequestEvent $event) use ($container, $owner): void {
    if ($event->getRequest()->getPathInfo() !== '/__stream-collection-fixture') {
        return;
    }

    $container->get('router')->getContext()->fromRequest($event->getRequest());
    $twig = $container->get('twig');
    $twig->setLoader(new \Twig\Loader\ChainLoader([$twig->getLoader(), new \Twig\Loader\FilesystemLoader(__DIR__)]));
    $article = (new \App\Entity\Article())
        ->setPubkey($owner)->setSlug('layout-article')->setTitle('Layout article')
        ->setKind(\App\Enum\KindsEnum::LONGFORM)->setCreatedAt(1700000000)
        ->setSummary('Article summary')->setImage('https://stream-covers.example/article.svg');
    $rows = [[
        'key' => 'layout-article', 'type' => 'article', 'item' => $article,
        'labels' => [], 'timestamp' => 1700000000,
    ]];
    foreach (['magazine', 'reading_list', 'follow_pack'] as $type) {
        foreach ([true, false] as $hasImage) {
            $key = $type . '-' . ($hasImage ? 'image' : 'no-image');
            $item = [
                'pubkey' => $owner, 'slug' => $key, 'title' => $key,
                'description' => 'Collection description', 'memberCount' => 3,
            ];
            if ($type === 'magazine') {
                $item['indexEventId'] = str_repeat('1', 64);
            }
            if ($hasImage) {
                $item['image'] = 'https://stream-covers.example/collection.svg';
            }
            $rows[] = [
                'key' => $key, 'type' => $type, 'item' => $item,
                'labels' => [], 'timestamp' => 1700000000,
            ];
        }
    }
    $event->setResponse(new Response($twig->render('stream-collection-layout.html.twig', ['rows' => $rows])));
}, 100);
$dispatcher->addListener(KernelEvents::REQUEST, static function () use ($container, $owner): void {
    if (isset($_COOKIE['workspace_fixture_signed_out'])) {
        return;
    }
    $user = $container->get(EntityManagerInterface::class)->getRepository(User::class)
        ->findOneBy(['npub' => PublicKey::fromHex($owner)->toBech32()]);
    $container->get('security.token_storage')->setToken(new UsernamePasswordToken($user, 'main', ['ROLE_USER']));
}, 0);
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
if ($request->getPathInfo() === '/logout') {
    $response->headers->setCookie(\Symfony\Component\HttpFoundation\Cookie::create('workspace_fixture_signed_out', '1'));
}
$response->send();
$kernel->terminate($request, $response);
