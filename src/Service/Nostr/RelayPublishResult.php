<?php

declare(strict_types=1);

namespace App\Service\Nostr;

use JsonSerializable;

/**
 * Typed publish outcome for callers that do not need the legacy array adapter.
 */
final readonly class RelayPublishResult implements JsonSerializable
{
    public function __construct(
        public bool $ok,
        public ?string $message = null,
        public ?int $latencyMs = null,
    ) {
    }

    /** @return array{ok: bool, message: ?string, latency_ms: ?int} */
    public function toArray(): array
    {
        return ['ok' => $this->ok, 'message' => $this->message, 'latency_ms' => $this->latencyMs];
    }

    /** @return array{ok: bool, message: ?string, latency_ms: ?int} */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Accept the typed result and the temporary legacy response shapes used by
     * callers during the relay-client migration.
     */
    public static function isSuccessful(mixed $result): bool
    {
        if ($result instanceof self) {
            return $result->ok;
        }

        if (is_array($result)) {
            return (bool) ($result['ok'] ?? false);
        }

        if ($result === true) {
            return true;
        }

        if (is_object($result)) {
            if (isset($result->ok)) {
                return (bool) $result->ok;
            }

            if (isset($result->isSuccess)) {
                return (bool) $result->isSuccess;
            }

            if (isset($result->status)) {
                return (bool) $result->status;
            }

            return ($result->type ?? null) === 'OK';
        }

        return false;
    }
}
