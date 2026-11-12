<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs the browser's bounded TOC traversal state. This is UI-integrity state,
 * not an authorization grant: source and target access policies still run on
 * every request.
 */
final readonly class PublicationTocTraversalSigner
{
    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('A framework secret is required for TOC traversal signatures.');
        }
    }

    /**
     * @param list<string> $ancestorEventIds
     */
    public function sign(
        string $eventId,
        int $page,
        array $ancestorEventIds,
        string $occurrenceId,
        string $coordinate,
    ): string {
        return hash_hmac('sha256', $this->payload(
            $eventId,
            $page,
            $ancestorEventIds,
            $occurrenceId,
            $coordinate,
        ), $this->secret);
    }

    /**
     * @param list<string> $ancestorEventIds
     */
    public function isValid(
        string $eventId,
        int $page,
        array $ancestorEventIds,
        string $occurrenceId,
        string $coordinate,
        ?string $signature,
    ): bool {
        return is_string($signature)
            && preg_match('/^[0-9a-f]{64}$/', $signature) === 1
            && hash_equals(
                $this->sign($eventId, $page, $ancestorEventIds, $occurrenceId, $coordinate),
                $signature,
            );
    }

    /**
     * @param list<string> $ancestorEventIds
     */
    private function payload(
        string $eventId,
        int $page,
        array $ancestorEventIds,
        string $occurrenceId,
        string $coordinate,
    ): string {
        return json_encode([
            'version' => 1,
            'eventId' => strtolower($eventId),
            'page' => $page,
            'path' => array_map('strtolower', $ancestorEventIds),
            'occurrenceId' => strtolower($occurrenceId),
            'coordinate' => $coordinate,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
