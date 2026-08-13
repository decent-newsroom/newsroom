<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrClientBundle\Contract;

use Innis\Nostr\Client\Application\Port\NostrClientInterface;
use Innis\Nostr\Client\Domain\Service\RelayHealthCheckerInterface;
use Innis\Nostr\Client\Domain\ValueObject\ConnectionConfig;

/**
 * Application-facing seam for obtaining Nostr relay clients.
 *
 * The host application should depend on this interface (or the underlying
 * {@see NostrClientInterface}/{@see RelayHealthCheckerInterface} contracts it
 * produces) rather than on the concrete innis/nostr-client factory, so the
 * transport implementation can be swapped later without touching call sites.
 */
interface NostrClientFactoryInterface
{
    /**
     * Create a new relay client. One client can manage multiple concurrent
     * relay connections, so a single instance is typically shared per request
     * or worker lifecycle.
     */
    public function create(): NostrClientInterface;

    /**
     * Create a standalone health checker that does not require an active
     * connection to be established first.
     */
    public function createHealthChecker(): RelayHealthCheckerInterface;

    /**
     * Build the default connection configuration derived from the bundle's
     * `nostr_client` configuration (timeouts, reconnect behaviour, user agent).
     */
    public function createDefaultConnectionConfig(): ConnectionConfig;
}
