<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Application\Event;

use DecentNewsroom\NostrKernelBundle\Application\Event\ClassifyEventKind;
use DecentNewsroom\NostrKernelBundle\Application\Event\ValidateAndNormalizeEvent;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventValidationResult;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;
use PHPUnit\Framework\TestCase;

final class ValidateAndNormalizeEventTest extends TestCase
{
    public function testItRejectsEventsBeyondTheConfiguredFutureWindow(): void
    {
        $normalizer = $this->createNormalizer(
            verifySignatures: false,
            allowFutureEventsSeconds: 30,
        );

        $this->expectException(InvalidNostrEvent::class);
        $this->expectExceptionMessage('too far in the future');

        $normalizer->normalize([
            'pubkey' => str_repeat('a', 64),
            'created_at' => time() + 31,
            'kind' => 1,
            'tags' => [],
            'content' => '',
        ]);
    }

    public function testItRejectsProtectedEventsWhenDisabled(): void
    {
        $normalizer = $this->createNormalizer(
            verifySignatures: false,
            allowProtectedEvents: false,
        );

        $this->expectException(InvalidNostrEvent::class);
        $this->expectExceptionMessage('Protected events are not allowed');

        $normalizer->normalize([
            'pubkey' => str_repeat('a', 64),
            'created_at' => time(),
            'kind' => 1,
            'tags' => [['-']],
            'content' => '',
        ]);
    }

    private function createNormalizer(
        bool $verifySignatures,
        int $allowFutureEventsSeconds = 300,
        bool $allowProtectedEvents = true,
    ): ValidateAndNormalizeEvent {
        $validator = $this->createMock(EventValidatorInterface::class);
        $validator->method('validate')->willReturn(EventValidationResult::valid());

        return new ValidateAndNormalizeEvent(
            validator: $validator,
            kindClassifier: new ClassifyEventKind(),
            signatureVerifier: $this->createMock(EventSignatureVerifierInterface::class),
            strictValidation: true,
            allowFutureEventsSeconds: $allowFutureEventsSeconds,
            verifySignatures: $verifySignatures,
            allowProtectedEvents: $allowProtectedEvents,
        );
    }
}
