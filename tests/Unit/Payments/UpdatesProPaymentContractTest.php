<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Command\SubscriptionZapReceiptWorkerCommand;
use App\Entity\UpdateProSubscription;
use App\Enum\UpdateProStatus;
use App\Enum\UpdateProTier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Attribute\AsCommand;

final class UpdatesProPaymentContractTest extends TestCase
{
    public function testStatusBackingValuesRemainCompatibleWithStoredRows(): void
    {
        self::assertSame(
            ['pending', 'active', 'grace', 'expired'],
            array_map(static fn (UpdateProStatus $status): string => $status->value, UpdateProStatus::cases()),
        );

        $subscription = new UpdateProSubscription('npub1example', UpdateProTier::MONTHLY);
        self::assertSame('pending', $subscription->getStatus()->value);
        $subscription->activate();
        self::assertSame('active', $subscription->getStatus()->value);
    }

    public function testReceiptWorkerUsesUpdatesProCommandName(): void
    {
        $attribute = (new \ReflectionClass(SubscriptionZapReceiptWorkerCommand::class))
            ->getAttributes(AsCommand::class)[0]
            ->newInstance();

        self::assertSame('updates-pro:check-receipts', $attribute->name);
    }
}