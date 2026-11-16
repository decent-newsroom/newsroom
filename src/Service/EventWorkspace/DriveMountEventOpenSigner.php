<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs a directory occurrence's exact-event opening URL.
 *
 * A signature protects URL integrity only. Drive/path/source/target policies
 * are re-evaluated by the controller on every request.
 */
final readonly class DriveMountEventOpenSigner
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('A framework secret is required for drive event opening signatures.');
        }
    }

    /**
     * @param list<string> $path Drive ID followed by every directory through the source.
     * @param array<mixed> $rawTag
     */
    public function occurrenceId(
        string $driveId,
        array $path,
        string $targetId,
        int $position,
        array $rawTag,
    ): string {
        return hash('sha256', $this->payload($driveId, $path, $targetId, $position, $rawTag));
    }

    /**
     * @param list<string> $path
     * @param array<mixed> $rawTag
     */
    public function sign(
        string $driveId,
        array $path,
        string $targetId,
        int $position,
        array $rawTag,
        string $occurrenceId,
    ): string {
        return hash_hmac(
            'sha256',
            $this->payload($driveId, $path, $targetId, $position, $rawTag) . "\0" . strtolower($occurrenceId),
            $this->secret,
        );
    }

    /**
     * @param list<string> $path
     * @param array<mixed> $rawTag
     */
    public function isValid(
        string $driveId,
        array $path,
        string $targetId,
        int $position,
        array $rawTag,
        string $occurrenceId,
        ?string $signature,
    ): bool {
        return preg_match('/^[0-9a-f]{64}$/D', $occurrenceId) === 1
            && is_string($signature)
            && preg_match('/^[0-9a-f]{64}$/D', $signature) === 1
            && hash_equals(
                $this->occurrenceId($driveId, $path, $targetId, $position, $rawTag),
                strtolower($occurrenceId),
            )
            && hash_equals(
                $this->sign($driveId, $path, $targetId, $position, $rawTag, $occurrenceId),
                $signature,
            );
    }

    /**
     * @param list<string> $path
     * @param array<mixed> $rawTag
     */
    private function payload(
        string $driveId,
        array $path,
        string $targetId,
        int $position,
        array $rawTag,
    ): string {
        return json_encode([
            'version' => 1,
            'drive' => strtolower($driveId),
            'path' => array_map('strtolower', $path),
            'target' => strtolower($targetId),
            'position' => $position,
            // Bind the signature to the complete source occurrence, including
            // exact a-tag pins and relay/tag-slot data.
            'tag' => $rawTag,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
