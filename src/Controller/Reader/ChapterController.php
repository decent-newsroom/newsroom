<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
use App\Service\ChapterParentPublicationResolver;
use App\Service\Nostr\EventLookupKey;
use App\Util\CommonMark\Converter;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

class ChapterController extends AbstractController
{
    #[Route('/chapter/{naddr}', name: 'chapter', requirements: ['naddr' => '^naddr1.*'])]
    public function show(
        string $naddr,
        EventRepository $eventRepository,
        MessageBusInterface $messageBus,
        Converter $converter,
        LoggerInterface $logger,
    ): Response {
        $data = $this->decodeAddress($naddr);
        if ($data === null) {
            throw new NotFoundHttpException('Invalid chapter address.');
        }

        $kind = $data['kind'];
        $pubkey = $data['pubkey'];
        $identifier = $data['identifier'];
        $relays = $data['relays'];

        if ($kind !== KindsEnum::PUBLICATION_CONTENT->value) {
            return $this->redirectToRoute('nevent', ['nevent' => $naddr]);
        }

        if ($pubkey === '' || $identifier === '') {
            throw new NotFoundHttpException('Invalid chapter address.');
        }

        $chapter = $eventRepository->findByNaddr(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, $identifier);
        if (!$chapter instanceof Event) {
            $lookupKey = EventLookupKey::forNaddr(KindsEnum::PUBLICATION_CONTENT->value, $pubkey, $identifier);
            $messageBus->dispatch(new FetchEventFromRelaysMessage(
                lookupKey: $lookupKey,
                type: 'naddr',
                kind: KindsEnum::PUBLICATION_CONTENT->value,
                pubkey: $pubkey,
                identifier: $identifier,
                relays: $relays,
            ));

            return $this->render('chapter/loading.html.twig', [
                'naddr' => $naddr,
                'lookupKey' => $lookupKey,
                'lookupTopic' => EventLookupKey::topic($lookupKey),
                'hasRelayHints' => $relays !== [],
            ]);
        }

        try {
            $content = $converter->convertAsciiDocToHTML($chapter->getContent());
        } catch (\Throwable $e) {
            $logger->error('Failed to convert standalone chapter content', [
                'chapter_id' => $chapter->getId(),
                'error' => $e->getMessage(),
            ]);
            $content = '<pre>' . htmlspecialchars($chapter->getContent(), ENT_QUOTES, 'UTF-8') . '</pre>';
        }

        $coordinate = KindsEnum::PUBLICATION_CONTENT->value . ':' . $pubkey . ':' . $identifier;

        return $this->render('chapter/show.html.twig', [
            'chapter' => $chapter,
            'content' => $content,
            'title' => $this->chapterTitle($chapter, $identifier),
            'summary' => $chapter->getSummary(),
            'naddr' => $naddr,
            'coordinate' => $coordinate,
        ]);
    }

    #[Route('/chapter/{naddr}/parent', name: 'chapter-parent-frame', requirements: ['naddr' => '^naddr1.*'], methods: ['GET'], priority: 10)]
    public function parentFrame(string $naddr, ChapterParentPublicationResolver $parentResolver): Response
    {
        $data = $this->decodeAddress($naddr);
        $parentPublication = null;
        if (
            $data !== null
            && $data['kind'] === KindsEnum::PUBLICATION_CONTENT->value
            && $data['pubkey'] !== ''
            && $data['identifier'] !== ''
        ) {
            $coordinate = KindsEnum::PUBLICATION_CONTENT->value . ':' . $data['pubkey'] . ':' . $data['identifier'];
            $parentPublication = $parentResolver->resolve($coordinate);
        }

        return $this->render('chapter/_parent_frame.html.twig', [
            'parentPublication' => $parentPublication,
        ]);
    }

    /**
     * @return array{kind: int, pubkey: string, identifier: string, relays: string[]}|null
     */
    private function decodeAddress(string $naddr): ?array
    {
        try {
            $decoded = new Bech32($naddr);
        } catch (\Throwable) {
            return null;
        }

        if ($decoded->type !== 'naddr' || !$decoded->data instanceof NAddr) {
            return null;
        }

        /** @var NAddr $data */
        $data = $decoded->data;

        return [
            'kind' => (int) $data->kind,
            'pubkey' => (string) $data->pubkey,
            'identifier' => trim((string) $data->identifier),
            'relays' => is_array($data->relays ?? null) ? $data->relays : [],
        ];
    }

    private function chapterTitle(Event $chapter, string $identifier): string
    {
        $title = trim((string) ($chapter->getTitle() ?? ''));

        return $title !== '' ? $title : $identifier;
    }
}
