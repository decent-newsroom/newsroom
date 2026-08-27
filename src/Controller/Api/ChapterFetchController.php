<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Service\Magazine\MagazineStructureService;
use App\Service\Nostr\EventLookupKey;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class ChapterFetchController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly MagazineStructureService $magazineStructure,
        private readonly LoggerInterface $logger,
    ) {}

    #[Route('/fetch-chapter', name: 'api_fetch_chapter', methods: ['POST'])]
    public function fetchChapter(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!is_array($data)) {
            return new JsonResponse([
                'queued' => false,
                'error' => 'Invalid request data',
            ], 400);
        }

        $coordinate = $data['coordinate'] ?? null;
        if (!is_string($coordinate) || $coordinate === '') {
            return new JsonResponse([
                'queued' => false,
                'error' => 'Missing required parameter: coordinate',
            ], 400);
        }

        $parsed = $this->parseChapterCoordinate($coordinate);
        if ($parsed === null) {
            return new JsonResponse([
                'queued' => false,
                'error' => 'Invalid coordinate. Expected kind 30041 coordinate: 30041:pubkey:d-tag',
            ], 400);
        }

        $mag = $data['mag'] ?? null;
        $mag = is_string($mag) && $mag !== '' ? $mag : null;
        $relayHints = $this->normalizeRelayHints($data['relayHints'] ?? []);
        if ($relayHints === [] && $mag !== null) {
            $relayHints = $this->relayHintsFromMagazine($mag, $coordinate);
        }

        $lookupKey = EventLookupKey::forNaddr($parsed['kind'], $parsed['pubkey'], $parsed['identifier']);

        $this->messageBus->dispatch(new FetchEventFromRelaysMessage(
            lookupKey: $lookupKey,
            type: 'naddr',
            kind: $parsed['kind'],
            pubkey: $parsed['pubkey'],
            identifier: $parsed['identifier'],
            relays: $relayHints,
            mag: $mag,
        ));

        $this->logger->info('Queued async chapter fetch', [
            'coordinate' => $coordinate,
            'lookup_key' => $lookupKey,
            'mag' => $mag,
            'relay_hints' => $relayHints,
        ]);

        return new JsonResponse([
            'queued' => true,
            'success' => true,
            'lookupKey' => $lookupKey,
            'lookupTopic' => EventLookupKey::topic($lookupKey),
        ], 202);
    }

    /**
     * @return array{kind: int, pubkey: string, identifier: string}|null
     */
    private function parseChapterCoordinate(string $coordinate): ?array
    {
        $parts = explode(':', $coordinate, 3);
        if (count($parts) !== 3 || !ctype_digit($parts[0])) {
            return null;
        }

        $kind = (int) $parts[0];
        if (
            $kind !== KindsEnum::PUBLICATION_CONTENT->value
            || strlen($parts[1]) !== 64
            || !ctype_xdigit($parts[1])
            || $parts[2] === ''
        ) {
            return null;
        }

        return [
            'kind' => $kind,
            'pubkey' => $parts[1],
            'identifier' => $parts[2],
        ];
    }

    /**
     * @param mixed $relayHints
     * @return string[]
     */
    private function normalizeRelayHints(mixed $relayHints): array
    {
        if (!is_array($relayHints)) {
            return [];
        }

        $normalized = [];
        foreach ($relayHints as $relayHint) {
            if (!is_string($relayHint)) {
                continue;
            }

            $relayHint = rtrim(trim($relayHint), '/');
            if ($relayHint === '' || !preg_match('#^wss?://#i', $relayHint)) {
                continue;
            }

            if (!in_array($relayHint, $normalized, true)) {
                $normalized[] = $relayHint;
            }
        }

        return $normalized;
    }

    /**
     * @return string[]
     */
    private function relayHintsFromMagazine(string $mag, string $coordinate): array
    {
        try {
            $magazine = $this->magazineStructure->findLatestIndexBySlug($mag);
            if (!$magazine instanceof Event) {
                return [];
            }

            $structure = $this->magazineStructure->parseStructure($magazine);
            return $structure->chapterRelayHints[$coordinate] ?? [];
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to resolve chapter relay hints from magazine index', [
                'mag' => $mag,
                'coordinate' => $coordinate,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
