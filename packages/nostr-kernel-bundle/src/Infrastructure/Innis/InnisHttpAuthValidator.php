<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Auth\NostrHttpAuthValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthResult;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthToken;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;

/**
 * Verifies NIP-98 (kind 27235) HTTP auth events using innis/nostr-core.
 *
 * Verification order matters: the structural/claim checks (kind, timestamp,
 * "u"/"method" tags) are cheap and run first so a malformed request never
 * reaches signature verification; only a structurally valid event pays the
 * cost of (and reveals timing about) cryptographic verification.
 */
final readonly class InnisHttpAuthValidator implements NostrHttpAuthValidatorInterface
{
    public function __construct(private EventSignatureVerifierInterface $signatureVerifier)
    {
    }

    public function validate(NostrHttpAuthToken $token): NostrHttpAuthResult
    {
        $event = $token->event();
        $errors = [];

        if (!$event->kind()->isHttpAuth()) {
            $errors[] = 'Invalid event kind; expected NIP-98 HTTP auth (27235).';
        }

        if (null === $event->id() || null === $event->signature()) {
            $errors[] = 'Missing event id or signature.';
        }

        if (\abs(\time() - $event->createdAt()) > $token->maxAgeSeconds()) {
            $errors[] = 'Authentication event has expired or has an invalid timestamp.';
        }

        $method = $event->tags()->firstValue('method');
        if (null === $method) {
            $errors[] = 'Missing required "method" tag in authentication event.';
        } elseif (!\hash_equals($token->expectedMethod(), $method)) {
            $errors[] = 'Method tag does not match request method.';
        }

        $url = $event->tags()->firstValue('u');
        if (null === $url) {
            $errors[] = 'Missing required "u" (URL) tag in authentication event.';
        } elseif (!\hash_equals($token->expectedUrl(), $url)) {
            $errors[] = 'URL tag does not match request URL.';
        }

        if ([] !== $errors) {
            return NostrHttpAuthResult::invalid($errors);
        }

        try {
            $signatureIsValid = $this->signatureVerifier->verify($event);
        } catch (InvalidNostrEvent $e) {
            return NostrHttpAuthResult::invalid(['Signature verification failed: ' . $e->getMessage()]);
        }

        if (!$signatureIsValid) {
            return NostrHttpAuthResult::invalid(['Invalid event signature.']);
        }

        return NostrHttpAuthResult::valid($event->pubkey());
    }
}

