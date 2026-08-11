<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Fixtures;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventNormalizerInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;

final readonly class ServiceProbe
{
    public function __construct(
        public EventNormalizerInterface $normalizer,
        public EventSignatureVerifierInterface $signatureVerifier,
        public EventValidatorInterface $validator,
        public Nip19DecoderInterface $decoder,
        public Nip19EncoderInterface $encoder,
    ) {
    }
}
