<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Nostr;

use App\Enum\RelayPurpose;
use App\Service\Nostr\RelayRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for RelayRegistry — purpose resolution, deduplication,
 * LOCAL/PROJECT overlap handling, and URL normalization.
 *
 * @see documentation/Nostr/relay-management-review.md §9
 */
class RelayRegistryTest extends TestCase
{
    private function createRegistry(
        ?string $localRelay = 'ws://strfry:7777',
        array $profileRelays = ['wss://purplepag.es'],
        array $contentRelays = ['wss://relay.damus.io', 'wss://nos.lol'],
        array $projectRelays = ['wss://relay.decentnewsroom.com'],
        array $signerRelays = ['wss://relay.nsec.app'],
        array $chatRelays = [],
    ): RelayRegistry {
        return new RelayRegistry(
            new NullLogger(),
            $localRelay,
            $profileRelays,
            $contentRelays,
            $projectRelays,
            $signerRelays,
            $chatRelays,
        );
    }

    // --- getLocalRelay / getProjectRelay ---

    public function testGetLocalRelayReturnsConfiguredUrl(): void
    {
        $reg = $this->createRegistry('ws://strfry:7777');
        $this->assertSame('ws://strfry:7777', $reg->getLocalRelay());
    }

    public function testGetLocalRelayReturnsNullWhenNotConfigured(): void
    {
        $reg = $this->createRegistry(null);
        $this->assertNull($reg->getLocalRelay());
    }

    public function testGetProjectRelayReturnsFirstProjectUrl(): void
    {
        $reg = $this->createRegistry();
        $this->assertSame('wss://relay.decentnewsroom.com', $reg->getProjectRelay());
    }

    public function testGetPublicUrlPrefersProjectOverLocal(): void
    {
        $reg = $this->createRegistry();
        $this->assertSame('wss://relay.decentnewsroom.com', $reg->getPublicUrl());
    }

    public function testGetPublicUrlFallsBackToLocalWhenNoProject(): void
    {
        $reg = $this->createRegistry(localRelay: 'ws://strfry:7777', projectRelays: []);
        $this->assertSame('ws://strfry:7777', $reg->getPublicUrl());
    }

    // --- getForPurposes: LOCAL/PROJECT deduplication ---

    public function testGetForPurposesDeduplicatesLocalAndProject(): void
    {
        $reg = $this->createRegistry();
        $urls = $reg->getForPurposes([RelayPurpose::LOCAL, RelayPurpose::PROJECT, RelayPurpose::CONTENT]);

        // LOCAL is configured, so PROJECT should be skipped entirely
        $this->assertContains('ws://strfry:7777', $urls);
        $this->assertNotContains('wss://relay.decentnewsroom.com', $urls);
        // Content relays should still be present
        $this->assertContains('wss://relay.damus.io', $urls);
    }

    public function testGetForPurposesIncludesProjectWhenNoLocal(): void
    {
        $reg = $this->createRegistry(localRelay: null);
        $urls = $reg->getForPurposes([RelayPurpose::LOCAL, RelayPurpose::PROJECT, RelayPurpose::CONTENT]);

        // No LOCAL, so PROJECT should be included
        $this->assertContains('wss://relay.decentnewsroom.com', $urls);
    }

    public function testGetForPurposesNoDuplicates(): void
    {
        // Put the same relay in content and profile
        $reg = $this->createRegistry(
            profileRelays: ['wss://relay.damus.io'],
            contentRelays: ['wss://relay.damus.io', 'wss://nos.lol'],
        );
        $urls = $reg->getForPurposes([RelayPurpose::PROFILE, RelayPurpose::CONTENT]);

        // relay.damus.io should appear only once
        $count = array_count_values($urls)['wss://relay.damus.io'] ?? 0;
        $this->assertSame(1, $count);
    }

    // --- getDefaultRelays / getProfileRelays / getContentRelays ---

    public function testGetDefaultRelaysStartsWithLocal(): void
    {
        $reg = $this->createRegistry();
        $defaults = $reg->getDefaultRelays();

        $this->assertNotEmpty($defaults);
        $this->assertSame('ws://strfry:7777', $defaults[0]);
    }

    public function testGetProfileRelaysIncludesLocalAndProfile(): void
    {
        $reg = $this->createRegistry();
        $relays = $reg->getProfileRelays();

        $this->assertContains('ws://strfry:7777', $relays);
        $this->assertContains('wss://purplepag.es', $relays);
    }

    // --- isProjectRelay ---

    public function testIsProjectRelayMatchesExact(): void
    {
        $reg = $this->createRegistry();
        $this->assertTrue($reg->isProjectRelay('wss://relay.decentnewsroom.com'));
    }

    public function testIsProjectRelayMatchesTrailingSlash(): void
    {
        $reg = $this->createRegistry();
        $this->assertTrue($reg->isProjectRelay('wss://relay.decentnewsroom.com/'));
    }

    public function testIsProjectRelayMatchesCaseInsensitive(): void
    {
        $reg = $this->createRegistry();
        $this->assertTrue($reg->isProjectRelay('wss://Relay.DecentNewsroom.com'));
    }

    public function testIsProjectRelayReturnsFalseForOtherUrls(): void
    {
        $reg = $this->createRegistry();
        $this->assertFalse($reg->isProjectRelay('wss://relay.damus.io'));
    }

    public function testIsProjectRelayReturnsFalseWhenNoProject(): void
    {
        $reg = $this->createRegistry(projectRelays: []);
        $this->assertFalse($reg->isProjectRelay('wss://relay.decentnewsroom.com'));
    }

    // --- isConfiguredRelay ---

    public function testIsConfiguredRelayMatchesContentRelay(): void
    {
        $reg = $this->createRegistry();
        $this->assertTrue($reg->isConfiguredRelay('wss://relay.damus.io'));
    }

    public function testIsConfiguredRelayMatchesCaseInsensitiveWithSlash(): void
    {
        $reg = $this->createRegistry();
        $this->assertTrue($reg->isConfiguredRelay('wss://Relay.Damus.IO/'));
    }

    public function testIsConfiguredRelayReturnsFalseForUnknown(): void
    {
        $reg = $this->createRegistry();
        $this->assertFalse($reg->isConfiguredRelay('wss://unknown-relay.example.com'));
    }

    // --- resolveToLocalUrl ---

    public function testResolveToLocalUrlRewritesProjectToLocal(): void
    {
        $reg = $this->createRegistry();
        $resolved = $reg->resolveToLocalUrl('wss://relay.decentnewsroom.com');
        $this->assertSame('ws://strfry:7777', $resolved);
    }

    public function testResolveToLocalUrlPassesThroughOtherUrls(): void
    {
        $reg = $this->createRegistry();
        $resolved = $reg->resolveToLocalUrl('wss://relay.damus.io');
        $this->assertSame('wss://relay.damus.io', $resolved);
    }

    // --- ensureLocalRelayInList ---

    public function testEnsureLocalRelayInListPrependsWhenMissing(): void
    {
        $reg = $this->createRegistry();
        $list = $reg->ensureLocalRelayInList(['wss://relay.damus.io']);
        $this->assertSame('ws://strfry:7777', $list[0]);
        $this->assertCount(2, $list);
    }

    public function testEnsureLocalRelayInListDoesNotDuplicate(): void
    {
        $reg = $this->createRegistry();
        $list = $reg->ensureLocalRelayInList(['ws://strfry:7777', 'wss://relay.damus.io']);
        $count = array_count_values($list)['ws://strfry:7777'] ?? 0;
        $this->assertSame(1, $count);
    }

    public function testEnsureLocalRelayInListHandlesTrailingSlash(): void
    {
        $reg = $this->createRegistry();
        $list = $reg->ensureLocalRelayInList(['ws://strfry:7777/', 'wss://relay.damus.io']);
        // Should recognize it as already present despite trailing slash
        $this->assertCount(2, $list);
    }

    public function testEnsureLocalRelayInListNoOpWhenNoLocal(): void
    {
        $reg = $this->createRegistry(localRelay: null);
        $input = ['wss://relay.damus.io'];
        $list = $reg->ensureLocalRelayInList($input);
        $this->assertSame($input, $list);
    }

    // --- getFallbackRelays ---

    public function testGetFallbackRelaysReturnsLocalOnly(): void
    {
        $reg = $this->createRegistry();
        $fallbacks = $reg->getFallbackRelays();
        $this->assertSame(['ws://strfry:7777'], $fallbacks);
    }

    public function testGetFallbackRelaysReturnsProjectWhenNoLocal(): void
    {
        $reg = $this->createRegistry(localRelay: null);
        $fallbacks = $reg->getFallbackRelays();
        $this->assertSame(['wss://relay.decentnewsroom.com'], $fallbacks);
    }

    // --- getAllUrls ---

    public function testGetAllUrlsReturnsUniqueUrls(): void
    {
        $reg = $this->createRegistry();
        $all = $reg->getAllUrls();
        $this->assertSame(array_values(array_unique($all)), $all);
    }

    public function testGetAllUrlsContainsEveryConfiguredRelay(): void
    {
        $reg = $this->createRegistry();
        $all = $reg->getAllUrls();
        $this->assertContains('ws://strfry:7777', $all);
        $this->assertContains('wss://purplepag.es', $all);
        $this->assertContains('wss://relay.damus.io', $all);
        $this->assertContains('wss://relay.decentnewsroom.com', $all);
        $this->assertContains('wss://relay.nsec.app', $all);
    }
}

