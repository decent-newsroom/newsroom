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
 * This compiler pass rejects any MESSENGER_TRANSPORT_DSN with a non-empty
 * path at container build time — before a broken config can reach production.
 *
 * @see vendor/symfony/redis-messenger/Transport/Connection.php line ~310
 */
class ValidateMessengerDsnPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        // Try to resolve the DSN from the environment at compile time.
        // In dev, dotenv is loaded; in prod with compiled containers the env
        // may not be available — in that case the runtime check in
        // ResetMessengerStreamsCommand catches it.
        $dsn = $_ENV['MESSENGER_TRANSPORT_DSN']
            ?? $_SERVER['MESSENGER_TRANSPORT_DSN']
            ?? (getenv('MESSENGER_TRANSPORT_DSN') ?: '');

        if ($dsn === '' || str_contains($dsn, '%env(') || str_contains($dsn, '${')) {
            return; // unresolved placeholder — cannot validate at compile time
        }

        $parsed = parse_url($dsn);
        if ($parsed === false) {
            return;
        }

        $path = trim($parsed['path'] ?? '', '/');
        if ($path === '') {
            return;
        }

        throw new \RuntimeException(sprintf(
            'MESSENGER_TRANSPORT_DSN has a non-empty path "/%s". ' .
            'Symfony\'s Redis transport uses the DSN path as the stream name, ' .
            'which silently overrides the per-transport "stream:" option in ' .
            'messenger.yaml and collapses all queues into one shared Redis stream. ' .
            'Remove the path. For a specific DB index, use ?dbindex=%s instead.',
            $path,
            ctype_digit($path) ? $path : '0',
        ));
    }
}


