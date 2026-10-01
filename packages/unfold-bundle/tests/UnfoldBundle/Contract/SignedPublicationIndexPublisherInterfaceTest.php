<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Contract;

use DecentNewsroom\UnfoldBundle\Contract\SignedPublicationIndexPublisherInterface;
use PHPUnit\Framework\TestCase;

final class SignedPublicationIndexPublisherInterfaceTest extends TestCase
{
    public function testDeclaresTheRootPublicationBoundary(): void
    {
        $method = new \ReflectionMethod(SignedPublicationIndexPublisherInterface::class, 'publishRoot');

        self::assertSame(['signedEvent', 'publicationCoordinate'], array_map(
            static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
            $method->getParameters(),
        ));
        self::assertSame('array', $method->getParameters()[0]->getType()?->getName());
        self::assertSame('string', $method->getParameters()[1]->getType()?->getName());
        self::assertSame('array', $method->getReturnType()?->getName());
    }
}
