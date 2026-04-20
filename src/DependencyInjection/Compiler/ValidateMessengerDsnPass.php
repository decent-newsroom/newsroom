<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Fail-fast guard: Symfony's Redis Messenger transport treats the DSN path
 * as the stream name (path segment 1), group (segment 2), and consumer
 * (segment 3). A path like "/0" or "/messages" silently overrides the
 * per-transport `stream:` option in messenger.yaml, collapsing all four
 * queues into one shared Redis stream.
 *
 * This compiler pass logs a build-time warning when the DSN has a non-empty
 * path. The actual hard failure is enforced at runtime by
 * {@see \App\EventListener\MessengerDsnHealthListener}.
 *
 * @see vendor/symfony/redis-messenger/Transport/Connection.php line ~310
 */
class ValidateMessengerDsnPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN']
            ?? $_SERVER['MESSENGER_TRANSPORT_DSN']
            ?? (getenv('MESSENGER_TRANSPORT_DSN') ?: '');

        if ($dsn === '' || str_contains($dsn, '%env(') || str_contains($dsn, '${')) {
            return;
        }

        $parsed = parse_url($dsn);
        if ($parsed === false) {
            return;
        }

        $path = trim($parsed['path'] ?? '', '/');
        if ($path === '') {
            return;
        }

        // Log to stderr so it's visible in docker build output, but don't
        // block the build — the runtime listener will prevent the app from
        // actually serving traffic with a broken DSN.
        $message = sprintf(
            '[WARNING] MESSENGER_TRANSPORT_DSN has a non-empty path "/%s". ' .
            'Symfony\'s Redis transport uses the DSN path as the stream name, ' .
            'which silently overrides the per-transport "stream:" option in ' .
            'messenger.yaml and collapses all queues into one shared Redis stream. ' .
            'Remove the path. For a specific DB index, use ?dbindex=%s instead.',
            $path,
            ctype_digit($path) ? $path : '0',
        );

        // Write to stderr for build visibility
        file_put_contents('php://stderr', "\n\n  ⚠⚠⚠  {$message}\n\n");

        // Also tag the container so the runtime listener can check
        $container->setParameter('app.messenger_dsn_warning', $message);
    }
}


