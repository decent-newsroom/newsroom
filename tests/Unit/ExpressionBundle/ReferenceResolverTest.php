<?php

declare(strict_types=1);

namespace App\Tests\Unit\ExpressionBundle;

use App\Entity\Event;
use App\ExpressionBundle\Source\PubkeyListSourceResolver;
use App\ExpressionBundle\Source\ReferenceResolver;
use App\Repository\EventRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ReferenceResolverTest extends TestCase
{
    public function testResolvePubkeyDomainForKind3CoordinateWithEmptyD(): void
    {
        $owner = str_repeat('aa', 32);
        $memberOne = str_repeat('11', 32);
        $memberTwo = str_repeat('22', 32);

        $contacts = new Event();
        $contacts->setId('contacts');
        $contacts->setKind(3);
        $contacts->setPubkey($owner);
        $contacts->setCreatedAt(1_700_000_000);
        $contacts->setContent('');
        $contacts->setSig('');
        $contacts->setTags([
            ['p', $memberOne],
            ['p', $memberTwo],
            ['p', $memberOne],
        ]);

        $repo = $this->createMock(EventRepository::class);
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


