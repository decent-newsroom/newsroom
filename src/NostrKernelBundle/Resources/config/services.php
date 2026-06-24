<?php

declare(strict_types=1);

use DecentNewsroom\NostrKernelBundle\Application\Auth\ValidateNostrHttpAuth;
use DecentNewsroom\NostrKernelBundle\Application\Event\ClassifyEventKind;
use DecentNewsroom\NostrKernelBundle\Application\Event\ParseEventReferences;
use DecentNewsroom\NostrKernelBundle\Application\Event\ResolveEventCoordinate;
use DecentNewsroom\NostrKernelBundle\Application\Event\ValidateAndNormalizeEvent;
use DecentNewsroom\NostrKernelBundle\Contract\Auth\NostrHttpAuthValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventCoordinateResolverInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventIdCalculatorInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventKindClassifierInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventNormalizerInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventReferenceParserInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Event\EventValidatorInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19DecoderInterface;
use DecentNewsroom\NostrKernelBundle\Contract\Nip19\Nip19EncoderInterface;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisEventIdCalculator;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisEventMapper;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisEventValidator;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisExceptionMapper;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisHttpAuthValidator;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisNip19Decoder;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisNip19Encoder;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisSignatureVerifier;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->defaults()
        ->autowire(true)
        ->autoconfigure(true)
        ->private();

    $services->set(ClassifyEventKind::class);
    $services->set(ParseEventReferences::class);
    $services->set(ResolveEventCoordinate::class);
    $services->set(ValidateNostrHttpAuth::class);

    $services->set(ValidateAndNormalizeEvent::class)
        ->arg('$strictValidation', param('nostr_kernel.strict_validation'))
        ->arg('$allowFutureEventsSeconds', param('nostr_kernel.allow_future_events_seconds'))
        ->arg('$verifySignatures', param('nostr_kernel.verify_signatures'))
        ->arg('$allowProtectedEvents', param('nostr_kernel.allow_protected_events'));

    $services->set(InnisEventMapper::class);
    $services->set(InnisExceptionMapper::class);
    $services->set(InnisEventValidator::class);
    $services->set(InnisSignatureVerifier::class);
    $services->set(InnisEventIdCalculator::class);
    $services->set(InnisNip19Decoder::class);
    $services->set(InnisNip19Encoder::class);
    $services->set(InnisHttpAuthValidator::class);

    $services->alias(EventKindClassifierInterface::class, ClassifyEventKind::class);
    $services->alias(EventCoordinateResolverInterface::class, ResolveEventCoordinate::class);
    $services->alias(EventReferenceParserInterface::class, ParseEventReferences::class);
    $services->alias(EventNormalizerInterface::class, ValidateAndNormalizeEvent::class);

    $services->alias(EventValidatorInterface::class, InnisEventValidator::class);
    $services->alias(EventIdCalculatorInterface::class, InnisEventIdCalculator::class);
    $services->alias(EventSignatureVerifierInterface::class, InnisSignatureVerifier::class);
    $services->alias(Nip19DecoderInterface::class, InnisNip19Decoder::class);
    $services->alias(Nip19EncoderInterface::class, InnisNip19Encoder::class);
    $services->alias(NostrHttpAuthValidatorInterface::class, InnisHttpAuthValidator::class);
};

