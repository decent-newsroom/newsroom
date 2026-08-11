<?php

declare(strict_types=1);

namespace DecentNewsroom\ExpressionBundle\Tests\Unit;

use DecentNewsroom\ExpressionBundle\Contract\EventStoreInterface;
use DecentNewsroom\ExpressionBundle\Model\ArrayEvent;
use DecentNewsroom\ExpressionBundle\Source\PubkeyListSourceResolver;
use DecentNewsroom\ExpressionBundle\Source\ReferenceResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReferenceResolverTest extends TestCase
{
    public function testResolvePubkeyDomainForKind3CoordinateWithEmptyD(): void
    {
        $owner = str_repeat('aa', 32);
        $memberOne = str_repeat('11', 32);
        $memberTwo = str_repeat('22', 32);

        $contacts = new ArrayEvent(
            id: 'contacts',
            kind: 3,
            pubkey: $owner,
            content: '',
            createdAt: 1_700_000_000,
            tags: [
                ['p', $memberOne],
                ['p', $memberTwo],
                ['p', $memberOne],
            ],
        );

        $repo = $this->createMock(EventStoreInterface::class);
        $repo
            ->expects($this->once())
            ->method('findLatestByPubkeyAndKind')
            ->with($owner, 3)
            ->willReturn($contacts);

        $pubkeyListResolver = new PubkeyListSourceResolver($repo, new NullLogger());
        $resolver = new ReferenceResolver($repo, $pubkeyListResolver);
        $resolved = $resolver->resolveForDomain('3:' . $owner . ':', 'pubkey');

        $this->assertSame([$memberOne, $memberTwo], $resolved);
    }
}
