<?php

declare(strict_types=1);

namespace App\Controller\Reader;

use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Message\FetchEventFromRelaysMessage;
use App\Repository\EventRepository;
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
        try {
            $decoded = new Bech32($naddr);
        } catch (\Throwable $e) {
            throw new NotFoundHttpException('Invalid chapter address.', $e);
        }

        if ($decoded->type !== 'naddr' || !$decoded->data instanceof NAddr) {
            throw new NotFoundHttpException('Invalid chapter address.');
        }

        /** @var NAddr $data */
        $data = $decoded->data;
        $kind = (int) $data->kind;
        $pubkey = (string) $data->pubkey;
        $identifier = trim((string) $data->identifier);
        $relays = is_array($data->relays ?? null) ? $data->relays : [];

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
        $parentPublication = $this->findParentPublication($eventRepository, $coordinate);

        return $this->render('chapter/show.html.twig', [
            'chapter' => $chapter,
            'content' => $content,
            'title' => $this->chapterTitle($chapter, $identifier),
            'summary' => $chapter->getSummary(),
            'naddr' => $naddr,
            'coordinate' => $coordinate,
            'parentPublication' => $parentPublication,
        ]);
    }

    /**
     * @return array{title: string, slug: ?string}|null
     */
    private function findParentPublication(EventRepository $eventRepository, string $coordinate): ?array
    {
        $parents = $eventRepository->findReferencingEvents(
            'a',
            $coordinate,
            [KindsEnum::PUBLICATION_INDEX->value],
            1,
        );

        $parent = $parents[0] ?? null;
        if (!$parent instanceof Event) {
            return null;
        }

        $slug = $parent->getDTag() ?: $parent->getSlug();
        $title = trim((string) ($parent->getTitle() ?? ''));
        if ($title === '') {
            $title = $slug ?: substr($parent->getId(), 0, 12);
        }

        return [
            'title' => $title,
            'slug' => $slug,
        ];
    }

    private function chapterTitle(Event $chapter, string $identifier): string
    {
        $title = trim((string) ($chapter->getTitle() ?? ''));

        return $title !== '' ? $title : $identifier;
    }
}
