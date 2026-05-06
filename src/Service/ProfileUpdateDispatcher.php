<?php

declare(strict_types=1);

namespace App\Service;

use App\Message\BatchUpdateProfileProjectionMessage;
use App\Message\UpdateProfileProjectionMessage;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Throttled dispatcher for profile update messages.
 *
 * Prevents the same pubkey from flooding the async_profiles queue by tracking
 * recent dispatches in Redis with a short TTL.  All four historic dispatch
 * sites now route through here instead of calling $messageBus->dispatch()
 * directly.
 *
 * TTL is intentionally short (5 min) — just enough to coalesce bursts from
 * relay ingestion workers while still allowing genuine refreshes.
 */
class ProfileUpdateDispatcher
{
    /**
     * How long (seconds) to suppress duplicate dispatches for the same pubkey.
     * Matches the ProfileRefreshWorkerCommand default cycle interval.
     */
    private const THROTTLE_TTL = 300; // 5 minutes

    private const KEY_PREFIX = 'profile:dispatch:';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly \Redis $redis,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Dispatch an individual profile update, unless the pubkey was recently
     * dispatched (within THROTTLE_TTL seconds).
     *
     * Returns true if the message was dispatched, false if throttled.
     */
    public function dispatch(string $pubkeyHex): bool
    {
        if (!$this->acquireSlot($pubkeyHex)) {
            $this->logger->debug('Profile dispatch throttled', [
                'pubkey' => substr($pubkeyHex, 0, 8),
            ]);
            return false;
        }

        try {
            $this->messageBus->dispatch(new UpdateProfileProjectionMessage($pubkeyHex));
            return true;
        } catch (\Throwable $e) {
            // Release the slot so a retry can proceed
            $this->releaseSlot($pubkeyHex);
            $this->logger->error('Failed to dispatch profile update', [
                'pubkey' => substr($pubkeyHex, 0, 8),
                'error'  => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Dispatch a batch profile update message.
     *
     * Filters out any pubkeys that were recently dispatched, and only sends
     * the message if at least one new pubkey remains.
     *
     * Returns the number of pubkeys actually queued (0 means fully throttled).
     */
    public function dispatchBatch(array $pubkeyHexList): int
    {
        $fresh = array_values(array_filter(
            $pubkeyHexList,
            fn(string $pk) => $this->acquireSlot($pk),
        ));

        if (empty($fresh)) {
            return 0;
        }

        try {
            $this->messageBus->dispatch(new BatchUpdateProfileProjectionMessage($fresh));
            return count($fresh);
        } catch (\Throwable $e) {
            // Release slots so a retry can proceed
            foreach ($fresh as $pk) {
                $this->releaseSlot($pk);
            }
            $this->logger->error('Failed to dispatch batch profile update', [
                'count' => count($fresh),
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    // -------------------------------------------------------------------------

    /**
     * Attempt to claim a dispatch slot for $pubkeyHex.
     * Uses Redis SET NX to atomically check-and-set.
     *
     * Returns true when the slot was newly acquired (caller should dispatch).
     * Returns false when a slot already exists (caller should skip).
     */
    private function acquireSlot(string $pubkeyHex): bool
    {
        try {
            $key = self::KEY_PREFIX . $pubkeyHex;
            // SET key 1 NX EX ttl — returns true only when the key did not exist
            $result = $this->redis->set($key, '1', ['NX', 'EX' => self::THROTTLE_TTL]);
            return $result !== false;
        } catch (\RedisException $e) {
            // Redis unavailable — allow the dispatch so profile updates still work
            $this->logger->warning('Redis unavailable in ProfileUpdateDispatcher, bypassing throttle', [
                'error' => $e->getMessage(),
            ]);
            return true;
        }
    }

    private function releaseSlot(string $pubkeyHex): void
    {
        try {
            $this->redis->del(self::KEY_PREFIX . $pubkeyHex);
        } catch (\RedisException) {
            // best-effort
        }
    }
}

