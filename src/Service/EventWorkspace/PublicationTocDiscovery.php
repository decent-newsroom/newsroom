<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReference;
use App\Message\FetchEventFromRelaysMessage;
use App\Service\Nostr\EventLookupKey;
use App\Service\Nostr\RelayRegistry;
use App\Service\EventWorkspace\Metrics\WorkspaceMetrics;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Redis reservations fail closed and are atomic across browsers/app replicas.
 * No target identity comes from the browser. Status is deliberately bounded:
 * local availability is authoritative; no worker/SSE result grants visibility.
 */
final readonly class PublicationTocDiscovery
{
    public function __construct(
        private \Redis $redis,
        private MessageBusInterface $bus,
        private RelayRegistry $relays,
        private LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private string $environment = 'prod',
        private ?WorkspaceMetrics $metrics = null,
    ) {}

    /** @return array{status: string, retryAfter: int} */
    public function status(string $reader, EventReference $reference): array
    {
        return $this->observe(fn (): array => $this->statusInternal($reader, $reference));
    }

    /** @return array{status: string, retryAfter: int} */
    private function statusInternal(string $reader, EventReference $reference): array
    {
        if ($reference->versionEventId !== null) {
            return ['status' => 'unavailable', 'retryAfter' => 0];
        }

        $ttl = $this->redis->ttl($this->keys($reader, $reference)[0]);
        return ['status' => $ttl > 0 ? 'loading' : 'failed', 'retryAfter' => max(0, $ttl)];
    }

    /** @return array{status: string, retryAfter: int} */
    public function request(string $reader, EventReference $reference): array
    {
        return $this->observe(fn (): array => $this->requestInternal($reader, $reference));
    }

    /** @return array{status: string, retryAfter: int} */
    private function requestInternal(string $reader, EventReference $reference): array
    {
        if ($reference->type->value !== 'address'
            || !in_array($reference->targetKind, [30040, 30041], true)
            || $reference->target === null
            || $reference->status->value !== 'unresolved') {
            throw new \InvalidArgumentException('Only public publication addresses can be discovered.');
        }
        if ($reference->versionEventId !== null) {
            return ['status' => 'unavailable', 'retryAfter' => 0];
        }

        // One reservation per coordinate; two attempts per reader/target
        // in ten minutes; at most four new requests per reader per minute.
        $reservationToken = bin2hex(random_bytes(16));
        $result = $this->redis->eval(<<<'LUA'
local ttl = redis.call('TTL', KEYS[1])
if ttl > 0 then return {0, ttl} end
if tonumber(redis.call('GET', KEYS[2]) or '0') >= 2 then
    return {-1, math.max(1, redis.call('TTL', KEYS[2]))}
end
if tonumber(redis.call('GET', KEYS[3]) or '0') >= 4 then
    return {-1, math.max(1, redis.call('TTL', KEYS[3]))}
end
redis.call('SET', KEYS[1], ARGV[1], 'EX', 60)
if redis.call('INCR', KEYS[2]) == 1 then redis.call('EXPIRE', KEYS[2], 600) end
if redis.call('INCR', KEYS[3]) == 1 then redis.call('EXPIRE', KEYS[3], 60) end
return {1, 60}
LUA, [...$this->keys($reader, $reference), $reservationToken], 3);
        if (!is_array($result)) {
            throw new \RuntimeException('Discovery reservation unavailable.');
        }
        if ($result[0] === 1) {
            try {
                $this->bus->dispatch(new FetchEventFromRelaysMessage(
                    lookupKey: EventLookupKey::forNaddr($reference->targetKind, $reference->targetPubkey, $reference->targetIdentifier),
                    type: 'naddr',
                    kind: $reference->targetKind,
                    pubkey: $reference->targetPubkey,
                    identifier: $reference->targetIdentifier,
                    // Ignore even signed relay hints: only configured content relays.
                    relays: array_slice($this->relays->getContentRelays(), 0, 4),
                    workspaceDiscovery: true,
                ));
            } catch (\Throwable $e) {
                try {
                    $this->redis->eval(<<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then return 0 end
redis.call('DEL', KEYS[1])
for i = 2, 3 do
    local value = tonumber(redis.call('GET', KEYS[i]) or '0')
    if value <= 1 then
        redis.call('DEL', KEYS[i])
    else
        redis.call('DECR', KEYS[i])
    end
end
return 1
LUA, [...$this->keys($reader, $reference), $reservationToken], 3);
                } catch (\Throwable $rollbackError) {
                    $this->logger->error('Could not release TOC discovery reservation after dispatch failure', [
                        'error' => $rollbackError->getMessage(),
                    ]);
                }

                throw $e;
            }
        }
        return ['status' => $result[0] < 0 ? 'limited' : 'loading', 'retryAfter' => (int) $result[1]];
    }

    /** @param callable(): array{status: string, retryAfter: int} $operation @return array{status: string, retryAfter: int} */
    private function observe(callable $operation): array
    {
        try {
            $result = $operation();
        } catch (\Throwable $error) {
            $this->metrics?->resolution('failed');
            throw $error;
        }
        $this->metrics?->resolution($result['status']);

        return $result;
    }

    /** @return list<string> */
    private function keys(string $reader, EventReference $reference): array
    {
        $target = hash('sha256', $reference->target);
        $prefix = 'workspace:toc:' . $this->environment . ':';
        return [
            $prefix . 'pending:' . $target,
            $prefix . 'attempts:' . hash('sha256', $reader) . ':' . $target,
            $prefix . 'budget:' . hash('sha256', $reader),
        ];
    }
}
