<?php

declare(strict_types=1);

namespace App\Tests\Unit\Twig\Components\Molecules;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Repository\EventRepository;
use App\Service\Cache\RedisCacheService;
use App\Service\LNURLResolver;
use App\Service\Nostr\NostrSigner;
use App\Service\Nostr\PaymentTargetService;
use App\Service\QRGenerator;
use App\Twig\Components\Molecules\TipButton;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class TipButtonTest extends TestCase
{
    public function testSelectTargetWithoutLiveArgShowsErrorInsteadOfCrashing(): void
    {
        $component = $this->createComponentWithTargets([]);

        $component->selectTarget();

        self::assertSame('error', $component->phase);
        self::assertSame('', $component->selectedTargetKey);
        self::assertSame('Invalid payment target.', $component->error);
    }

    public function testSelectTargetOpensGenericPaytoFlowForNonLightningTargets(): void
    {
        $component = $this->createComponentWithTargets([
            ['payto', 'monero', '48f1example'],
        ]);

        $target = $component->getTargets()[0];

        $component->selectTarget($target['key']);

        self::assertSame('payto', $component->phase);
        self::assertSame($target['key'], $component->selectedTargetKey);
        self::assertSame('payto://monero/48f1example', $component->paytoUri);
        self::assertSame('<svg>qr</svg>', $component->paytoQrSvg);
        self::assertSame('', $component->error);
    }

    public function testDebugTargetEventPreviewReturnsNullWhenNoEventExists(): void
    {
        $component = $this->createComponentWithTargets([]);

        self::assertNull($component->getDebugTargetEventPreview());
    }

    public function testOpenDialogStartsModalInTargetsLoadingPhase(): void
    {
        $component = $this->createComponentWithTargets([
            ['payto', 'monero', '48f1example'],
        ]);

        $component->openDialog();

        self::assertTrue($component->open);
        self::assertSame('targets_loading', $component->phase);
        self::assertFalse($component->targetsHydrated);
        self::assertSame([], $component->resolvedTargets);
    }

    public function testLoadTargetsHydratesSnapshotThatSelectionUses(): void
    {
        $component = $this->createComponentWithTargets([
            ['payto', 'monero', '48f1example'],
        ]);

        $component->openDialog();
        $component->loadTargets();

        self::assertSame('select', $component->phase);
        self::assertTrue($component->targetsHydrated);
        self::assertCount(1, $component->resolvedTargets);

        $component->selectTarget($component->resolvedTargets[0]['key']);

        self::assertSame('payto', $component->phase);
        self::assertSame('payto://monero/48f1example', $component->paytoUri);
    }

    public function testGetTargetsIncludesKind0Lud16AndLud06Targets(): void
    {
        $component = $this->createComponentWithTargets([]);
        $component->recipientLud16 = 'alice@getalby.com';
        $component->recipientLud06 = 'lnurl1dp68gurn8ghj7mrww4exctnrdakj7';

        $targets = $component->getTargets();

        self::assertCount(2, $targets);
        self::assertSame('lightning', $targets[0]['type']);
        self::assertSame('alice@getalby.com', $targets[0]['authority']);
        self::assertSame('lightning', $targets[1]['type']);
        self::assertSame('lnurl1dp68gurn8ghj7mrww4exctnrdakj7', $targets[1]['authority']);
    }

    public function testGetTargetsDeduplicatesKind0Lud16AgainstExistingPaytoTarget(): void
    {
        $component = $this->createComponentWithTargets([
            ['payto', 'lightning', 'alice@getalby.com'],
        ]);
        $component->recipientLud16 = 'alice@getalby.com';

        $targets = $component->getTargets();

        self::assertCount(1, $targets);
        self::assertSame('lightning', $targets[0]['type']);
        self::assertSame('alice@getalby.com', $targets[0]['authority']);
    }

    public function testDebugTargetEventPreviewSerializesSourceEvent(): void
    {
        $component = $this->createComponentWithTargets([
            ['payto', 'lightning', 'alice@getalby.com'],
        ]);

        $preview = $component->getDebugTargetEventPreview();

        self::assertNotNull($preview);
        self::assertStringContainsString('"kind": 10133', $preview);
        self::assertStringContainsString('"payto"', $preview);
        self::assertStringContainsString('"alice@getalby.com"', $preview);
    }

    public function testDebugTargetEventPreviewFallsBackToLiveSnapshotWhenDbEventMissing(): void
    {
        $component = $this->createComponentWithTargets([]);
        $component->resolvedTargets = [
            [
                'type' => 'monero',
                'authority' => '48f1example',
                'uri' => 'payto://monero/48f1example',
                'href' => 'payto://monero/48f1example',
                'key' => hash('sha256', 'monero' . "\0" . '48f1example'),
                'recognized' => true,
                'label' => 'Monero',
                'symbol' => 'ɱ',
                'shortLabel' => 'XMR',
                'extra' => [],
            ],
        ];
        $component->targetsHydrated = true;

        $preview = $component->getDebugTargetEventPreview();

        self::assertNotNull($preview);
        self::assertStringContainsString('"source": "live_component_snapshot"', $preview);
        self::assertStringContainsString('"monero"', $preview);
        self::assertStringContainsString('"48f1example"', $preview);
    }

    /**
     * @param array<int, array<int, string>> $paymentTags
     */
    private function createComponentWithTargets(array $paymentTags): TipButton
    {
        $eventRepository = $this->createMock(EventRepository::class);
        $eventRepository
            ->method('findLatestByPubkeyAndKind')
            ->with(str_repeat('a', 64), KindsEnum::PAYMENT_TARGETS->value)
            ->willReturn($paymentTags === [] ? null : $this->createPaymentTargetEvent($paymentTags));

        $qrGenerator = $this->createMock(QRGenerator::class);
        $qrGenerator
            ->method('svg')
            ->willReturn('<svg>qr</svg>');

        $component = new TipButton(
            new PaymentTargetService($eventRepository),
            $this->createMock(LNURLResolver::class),
            $this->createMock(NostrSigner::class),
            $qrGenerator,
            $this->createMock(RedisCacheService::class),
            $this->createMock(LoggerInterface::class),
            []
        );

        $component->recipientPubkey = str_repeat('a', 64);

        return $component;
    }

    /**
     * @param array<int, array<int, string>> $paymentTags
     */
    private function createPaymentTargetEvent(array $paymentTags): Event
    {
        $event = new Event();
        $event->setId(str_repeat('b', 64));
        $event->setKind(KindsEnum::PAYMENT_TARGETS->value);
        $event->setPubkey(str_repeat('a', 64));
        $event->setTags($paymentTags);
        $event->setCreatedAt(1);
        $event->setSig(str_repeat('c', 128));

        return $event;
    }

    public function testTargetGroupsGroupSameTypesAndExposeGeyserLinks(): void
    {
        $component = $this->createComponentWithTargets([
            ['payto', 'lightning', 'alice@getalby.com'],
            ['payto', 'lightning', 'bob@getalby.com'],
            ['payto', 'geyser', 'gitcitadel'],
        ]);

        $groups = $component->getTargetGroups();

        self::assertCount(2, $groups);
        self::assertSame('lightning', $groups[0]['type']);
        self::assertCount(2, $groups[0]['targets']);
        self::assertSame('geyser', $groups[1]['type']);
        self::assertSame('https://geyser.fund/project/gitcitadel', $groups[1]['targets'][0]['href']);
        self::assertTrue($groups[1]['recognized']);
    }
}
