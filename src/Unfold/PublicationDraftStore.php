<?php

declare(strict_types=1);

namespace App\Unfold;

use DecentNewsroom\UnfoldBundle\Config\PublicationDraft;
use DecentNewsroom\UnfoldBundle\Contract\PublicationDraftStoreInterface;
use Psr\Log\LoggerInterface;

final readonly class PublicationDraftStore implements PublicationDraftStoreInterface
{
    private const KEY_PREFIX = 'unfold:draft:';

    /**
     * RENAMENX retains the source key's expiry while ensuring no canonical draft is replaced.
     * The surrounding checks distinguish a missing provisional draft from an occupied destination.
     */
    private const MIGRATE_SCRIPT = <<<'LUA'
if redis.call('EXISTS', KEYS[2]) == 1 then
    return 0
end
if redis.call('EXISTS', KEYS[1]) == 0 then
    return -1
end
if redis.call('RENAMENX', KEYS[1], KEYS[2]) == 1 then
    return 1
end
return -2
LUA;

    public function __construct(
        private \Redis $redis,
        private LoggerInterface $logger,
        private int $ttlSeconds,
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Publication draft TTL must be positive.');
        }
    }

    public function findByProvisionalKey(string $provisionalKey): ?PublicationDraft
    {
        $key = $this->key($this->normalizeProvisionalKey($provisionalKey));

        try {
            $payload = $this->redis->get($key);
        } catch (\RedisException $exception) {
            $this->throwRedisFailure('read publication draft', $key, $exception);
        }

        if ($payload === false) {
            return null;
        }
        if (!is_string($payload)) {
            return $this->discardCorruptPayload($key, 'Stored draft payload is not a string.');
        }

        try {
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data)) {
                throw new \UnexpectedValueException('Stored draft payload is not an object.');
            }

            $draft = PublicationDraft::reconstitute($data);
            if ($this->key($draft->provisionalKey()) !== $key) {
                throw new \UnexpectedValueException('Stored draft identity does not match its key.');
            }

            return $draft;
        } catch (\JsonException|\InvalidArgumentException|\UnexpectedValueException $exception) {
            return $this->discardCorruptPayload($key, $exception->getMessage());
        }
    }

    public function save(PublicationDraft $draft): void
    {
        $keys = $this->migrationKeysFor($draft);
        $key = $this->key($keys['provisional']);

        try {
            $payload = json_encode($draft->toArray(), JSON_THROW_ON_ERROR);
            $stored = $this->redis->set($key, $payload, ['ex' => $this->ttlSeconds]);
        } catch (\RedisException $exception) {
            $this->throwRedisFailure('save publication draft', $key, $exception);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Unable to encode publication draft.', 0, $exception);
        }

        if ($stored !== true) {
            $this->throwRedisFailure('save publication draft', $key);
        }
    }

    public function discardByProvisionalKey(string $provisionalKey): void
    {
        $this->deleteKey($this->key($this->normalizeProvisionalKey($provisionalKey)), 'discard publication draft');
    }

    public function migrateProvisionalToCanonical(PublicationDraft $draft): void
    {
        $keys = $this->migrationKeysFor($draft);
        $provisionalKey = $this->key($keys['provisional']);
        $canonicalKey = $this->key($keys['canonical']);

        try {
            $result = $this->redis->eval(self::MIGRATE_SCRIPT, [$provisionalKey, $canonicalKey], 2);
        } catch (\RedisException $exception) {
            $this->throwRedisFailure('migrate publication draft', $provisionalKey, $exception);
        }

        if ($result === 1) {
            return;
        }

        $reason = match ($result) {
            0 => 'canonical draft already exists',
            -1 => 'provisional draft does not exist',
            default => 'atomic migration was not completed',
        };
        $this->throwRedisFailure('migrate publication draft: ' . $reason, $provisionalKey);
    }

    private function discardCorruptPayload(string $key, string $error): ?PublicationDraft
    {
        $this->deleteKey($key, 'discard corrupt publication draft');
        $this->logger->warning('Discarded corrupt publication draft.', [
            'key' => $key,
            'error' => $error,
        ]);

        return null;
    }

    private function deleteKey(string $key, string $operation): void
    {
        try {
            $deleted = $this->redis->del($key);
        } catch (\RedisException $exception) {
            $this->throwRedisFailure($operation, $key, $exception);
        }

        if ($deleted === false) {
            $this->throwRedisFailure($operation, $key);
        }
    }

    private function normalizeProvisionalKey(string $provisionalKey): string
    {
        [$ownerPubkey, $dtag] = PublicationDraft::parseProvisionalKey($provisionalKey);

        return $ownerPubkey . ':' . $dtag;
    }

    /** @return array{provisional: string, canonical: string} */
    private function migrationKeysFor(PublicationDraft $draft): array
    {
        $keys = PublicationDraft::migrationKeys($draft->provisionalKey(), $draft->canonicalKey());
        if ($keys['provisional'] !== $draft->provisionalKey() || $keys['canonical'] !== $draft->canonicalKey()) {
            throw new \InvalidArgumentException('Publication draft identity is not normalized.');
        }

        return $keys;
    }

    private function key(string $draftKey): string
    {
        return self::KEY_PREFIX . $draftKey;
    }

    private function throwRedisFailure(string $operation, string $key, ?\RedisException $exception = null): never
    {
        $this->logger->error('Redis failure while attempting to ' . $operation . '.', [
            'key' => $key,
            'error' => $exception?->getMessage(),
        ]);

        throw new \RuntimeException('Unable to ' . $operation . '.', 0, $exception);
    }
}
