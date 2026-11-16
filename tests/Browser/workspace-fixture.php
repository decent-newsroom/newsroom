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
    echo "Synthetic schema and events created.\n";
    exit(0);
}

$dispatcher = $container->get('event_dispatcher');
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
