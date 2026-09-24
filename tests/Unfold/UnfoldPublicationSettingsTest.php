<?php

declare(strict_types=1);

namespace App\Tests\Unfold;

use App\Entity\UnfoldPublicationSettings;
use DecentNewsroom\UnfoldBundle\Config\PublicationSettings;
use PHPUnit\Framework\TestCase;

final class UnfoldPublicationSettingsTest extends TestCase
{
    private const COORDINATE = '30040:' . 'a234567890123456789012345678901234567890123456789012345678901234' . ':main';

    public function testFooterLinksRoundTripAndUpdate(): void
    {
        $initial = [['label' => 'About', 'url' => 'https://example.com/about']];
        $record = new UnfoldPublicationSettings(new PublicationSettings(self::COORDINATE, 'default', $initial));

        self::assertSame($initial, $record->toSettings()->footerLinks);

        $updated = [['label' => 'Support', 'url' => 'https://example.com/support']];
        $record->update(new PublicationSettings(self::COORDINATE, 'casper', $updated));

        self::assertSame($updated, $record->toSettings()->footerLinks);
        self::assertSame('casper', $record->toSettings()->theme);
    }

    public function testLegacySettingsHaveNoFooterLinks(): void
    {
        $record = new UnfoldPublicationSettings(new PublicationSettings(self::COORDINATE));

        self::assertSame([], $record->toSettings()->footerLinks);
    }
}
