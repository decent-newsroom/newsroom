<?php

declare(strict_types=1);

namespace DecentNewsroom\NostrKernelBundle\Tests\Infrastructure\Innis;

use DecentNewsroom\NostrKernelBundle\Contract\Event\EventSignatureVerifierInterface;
use DecentNewsroom\NostrKernelBundle\Domain\Auth\NostrHttpAuthToken;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventId;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventKind;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventSignature;
use DecentNewsroom\NostrKernelBundle\Domain\Event\EventTags;
use DecentNewsroom\NostrKernelBundle\Domain\Event\NostrEvent;
use DecentNewsroom\NostrKernelBundle\Domain\Identity\Pubkey;
use DecentNewsroom\NostrKernelBundle\Exception\InvalidNostrEvent;
use DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisHttpAuthValidator;
use Innis\Nostr\Core\Domain\Entity\Event as InnisEvent;
use Innis\Nostr\Core\Domain\ValueObject\Identity\KeyPair;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PrivateKey;
use Innis\Nostr\Core\Infrastructure\Adapter\Secp256k1SignatureAdapter;
use PHPUnit\Framework\TestCase;

final class InnisHttpAuthValidatorTest extends TestCase
{
    private const PUBKEY = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testValidTokenReturnsPubkey(): void
    {
        $event = $this->makeEvent(method: 'GET', url: 'https://example.test/login', createdAt: \time());
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->expects(self::once())->method('verify')->with($event)->willReturn(true);

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertTrue($result->isValid());
        self::assertSame(self::PUBKEY, $result->pubkey()?->toHex());
    }

    public function testWrongKindIsRejected(): void
    {
        $event = $this->makeEvent(method: 'GET', url: 'https://example.test/login', createdAt: \time(), kind: 1);
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertFalse($result->isValid());
        self::assertNotEmpty($result->errors());
    }

    public function testExpiredEventIsRejected(): void
    {
        $event = $this->makeEvent(method: 'GET', url: 'https://example.test/login', createdAt: \time() - 3600);
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertFalse($result->isValid());
    }

    public function testMismatchedMethodIsRejected(): void
    {
        $event = $this->makeEvent(method: 'POST', url: 'https://example.test/login', createdAt: \time());
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertFalse($result->isValid());
    }

    public function testMismatchedUrlIsRejected(): void
    {
        $event = $this->makeEvent(method: 'GET', url: 'https://example.test/other', createdAt: \time());
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertFalse($result->isValid());
    }

    public function testInvalidSignatureIsRejected(): void
    {
        $event = $this->makeEvent(method: 'GET', url: 'https://example.test/login', createdAt: \time());
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->expects(self::once())->method('verify')->willReturn(false);

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertFalse($result->isValid());
        self::assertSame(['Invalid event signature.'], $result->errors());
    }

    public function testSignatureVerifierExceptionIsSurfacedAsInvalidResult(): void
    {
        $event = $this->makeEvent(method: 'GET', url: 'https://example.test/login', createdAt: \time());
        $verifier = $this->createMock(EventSignatureVerifierInterface::class);
        $verifier->method('verify')->willThrowException(new InvalidNostrEvent('boom'));

        $validator = new InnisHttpAuthValidator($verifier);
        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));

        self::assertFalse($result->isValid());
    }

    /**
     * End-to-end check against the real innis/nostr-core Schnorr implementation:
     * a genuinely signed NIP-98 event must verify, and tampering with the
     * signed content (URL) after signing must be caught even though `id`/`sig`
     * are left untouched — proving the id is recomputed, not merely trusted.
     */
    public function testRealSignatureRoundTripAndTamperDetection(): void
    {
        $signatureService = Secp256k1SignatureAdapter::create();
        $privateKey = PrivateKey::generate();
        $keyPair = KeyPair::fromPrivateKey($privateKey, $signatureService);

        $innisEvent = InnisEvent::fromArray([
            'pubkey' => $keyPair->getPublicKey()->toHex(),
            'created_at' => \time(),
            'kind' => 27235,
            'tags' => [['u', 'https://example.test/login'], ['method', 'GET']],
            'content' => '',
        ])->sign($keyPair, $signatureService);

        $signedArray = $innisEvent->toArray();
        $event = new NostrEvent(
            kind: new EventKind(27235),
            pubkey: new Pubkey($signedArray['pubkey']),
            tags: EventTags::fromRaw($signedArray['tags']),
            content: $signedArray['content'],
            createdAt: $signedArray['created_at'],
            id: new EventId($signedArray['id']),
            signature: new EventSignature($signedArray['sig']),
        );

        $realVerifier = new \DecentNewsroom\NostrKernelBundle\Infrastructure\Innis\InnisSignatureVerifier($signatureService);
        $validator = new InnisHttpAuthValidator($realVerifier);

        $result = $validator->validate(new NostrHttpAuthToken($event, 'GET', 'https://example.test/login'));
        self::assertTrue($result->isValid());

        // Tamper: swap the "u" tag after signing, keep the original id/sig.
        $tamperedTags = EventTags::fromRaw([['u', 'https://evil.test/login'], ['method', 'GET']]);
        $tamperedEvent = new NostrEvent(
            kind: $event->kind(),
            pubkey: $event->pubkey(),
            tags: $tamperedTags,
            content: $event->content(),
            createdAt: $event->createdAt(),
            id: $event->id(),
            signature: $event->signature(),
        );

        $tamperedResult = $validator->validate(
            new NostrHttpAuthToken($tamperedEvent, 'GET', 'https://evil.test/login')
        );
        self::assertFalse($tamperedResult->isValid());
    }

    private function makeEvent(string $method, string $url, int $createdAt, int $kind = 27235): NostrEvent
    {
        return new NostrEvent(
            kind: new EventKind($kind),
            pubkey: new Pubkey(self::PUBKEY),
            tags: EventTags::fromRaw([['u', $url], ['method', $method]]),
            content: '',
            createdAt: $createdAt,
            id: new EventId(\str_repeat('b', 64)),
            signature: new EventSignature(\str_repeat('c', 128)),
        );
    }
}
