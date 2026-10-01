<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Tests\UnfoldBundle\Config;

use DecentNewsroom\UnfoldBundle\Config\PublicationDraft;
use PHPUnit\Framework\TestCase;

final class PublicationDraftTest extends TestCase
{
    public function testNormalizesOwnerAndDraftFields(): void
    {
        $draft = PublicationDraft::create(
            str_repeat('AB', 32),
            '  Magazine:Draft  ',
            '  A title  ',
            '  A summary  ',
            ' https://images.example/cover.jpg ',
            ' EN-GB ',
            [' news ', 'nostr', 'news'],
            ' paper ',
            2,
        );

        self::assertSame(str_repeat('ab', 32), $draft->ownerPubkey);
        self::assertSame('Magazine:Draft', $draft->dtag);
        self::assertSame('A title', $draft->title);
        self::assertSame('A summary', $draft->summary);
        self::assertSame('https://images.example/cover.jpg', $draft->imageUrl);
        self::assertSame('en-gb', $draft->language);
        self::assertSame(['news', 'nostr'], $draft->tags);
        self::assertSame('paper', $draft->theme);
        self::assertSame(2, $draft->currentStep);
    }

    public function testRoundTripsScalarAndListStorageRepresentation(): void
    {
        $draft = PublicationDraft::create(
            str_repeat('a', 64),
            'magazine',
            'Title',
            'Summary',
            null,
            null,
            ['news'],
        );

        $data = $draft->toArray();

        self::assertEquals($draft, PublicationDraft::reconstitute($data));
        self::assertSame(
            [
                'owner_pubkey' => str_repeat('a', 64),
                'dtag' => 'magazine',
                'title' => 'Title',
                'summary' => 'Summary',
                'image_url' => null,
                'language' => null,
                'tags' => ['news'],
                'theme' => 'default',
                'current_step' => 1,
            ],
            $data,
        );
    }

    public function testDerivesOwnerScopedProvisionalAndCanonicalKeys(): void
    {
        $owner = str_repeat('AB', 32);
        $draft = PublicationDraft::create($owner, 'Magazine:Draft');

        self::assertSame(str_repeat('ab', 32) . ':Magazine:Draft', $draft->provisionalKey());
        self::assertSame('30040:' . str_repeat('ab', 32) . ':Magazine:Draft', $draft->canonicalKey());
        self::assertSame($draft->canonicalKey(), $draft->rootCoordinate());
        self::assertSame(
            ['provisional' => $draft->provisionalKey(), 'canonical' => $draft->canonicalKey()],
            PublicationDraft::migrationKeys(
                str_repeat('AB', 32) . ':Magazine:Draft',
                '30040:' . str_repeat('AB', 32) . ':Magazine:Draft',
            ),
        );
    }

    /** @dataProvider invalidDraftInputs */
    public function testRejectsInvalidDraftInput(string $owner, string $dtag, string $title, ?string $imageUrl, array $tags, string $theme, int $step): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unfold_setup.invalid_publication_draft');

        PublicationDraft::create($owner, $dtag, $title, imageUrl: $imageUrl, tags: $tags, theme: $theme, currentStep: $step);
    }

    /** @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string, 4: array<mixed>, 5: string, 6: int}> */
    public static function invalidDraftInputs(): iterable
    {
        $owner = str_repeat('a', 64);

        yield 'invalid owner' => ['not-a-pubkey', 'magazine', '', null, [], 'default', 1];
        yield 'empty d tag' => [$owner, ' ', '', null, [], 'default', 1];
        yield 'control character in title' => [$owner, 'magazine', "bad\ntitle", null, [], 'default', 1];
        yield 'invalid image URL' => [$owner, 'magazine', '', 'https://user:pass@example.com/cover.jpg', [], 'default', 1];
        yield 'non-list tags' => [$owner, 'magazine', '', null, ['tag' => 'news'], 'default', 1];
        yield 'theme traversal' => [$owner, 'magazine', '', null, [], '../default', 1];
        yield 'invalid step' => [$owner, 'magazine', '', null, [], 'default', 0];
    }

    public function testRejectsNonScalarStorageData(): void
    {
        $data = PublicationDraft::create(str_repeat('a', 64), 'magazine')->toArray();
        $data['current_step'] = '1';

        $this->expectException(\InvalidArgumentException::class);
        PublicationDraft::reconstitute($data);
    }

    /** @dataProvider invalidMigrationKeys */
    public function testRejectsUnsafeMigrationKeys(string $provisional, string $canonical): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PublicationDraft::migrationKeys($provisional, $canonical);
    }

    /** @return iterable<string, array{0: string, 1: string}> */
    public static function invalidMigrationKeys(): iterable
    {
        $owner = str_repeat('a', 64);

        yield 'wrong root kind' => [$owner . ':magazine', '30023:' . $owner . ':magazine'];
        yield 'different owner' => [$owner . ':magazine', '30040:' . str_repeat('b', 64) . ':magazine'];
        yield 'different d tag' => [$owner . ':magazine', '30040:' . $owner . ':other'];
        yield 'missing d tag' => [$owner . ':magazine', '30040:' . $owner . ':'];
    }
}
