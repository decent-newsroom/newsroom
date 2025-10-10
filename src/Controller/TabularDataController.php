<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\KindsEnum;
use App\Form\TabularDataType;
use App\Service\NostrClient;
use swentel\nostr\Event\Event;
use swentel\nostr\Sign\Sign;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class TabularDataController extends AbstractController
{
    #[Route('/tabular-data', name: 'tabular_data_publish')]
    public function publish(Request $request, NostrClient $nostrClient): Response
    {

        $form = $this->createForm(TabularDataType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            // Create the event
            $event = new Event();
            $event->setKind(KindsEnum::TABULAR_DATA->value);
            $event->setContent($data['csvContent']);

            // Add tags
            $tags = [
                ['title', $data['title']],
                ['m', 'text/csv'],
                ['M', 'text/csv; charset=utf-8'],
            ];

            if (!empty($data['license'])) {
                $tags[] = ['license', $data['license']];
            }

            if (!empty($data['units'])) {
                // Parse units, e.g., "col=2,EH/s" -> ['unit', 'col=2', 'EH/s']
                $unitParts = explode(',', $data['units'], 2);
                if (count($unitParts) == 2) {
                    $tags[] = ['unit', trim($unitParts[0]), trim($unitParts[1])];
                }
            }

            foreach ($tags as $tag) {
                $event->addTag($tag);
            }

            // For now, just render the event JSON
            return $this->render('tabular_data/preview.html.twig', [
                'event' => $event,
                'data' => $data,
            ]);
        }

        return $this->render('tabular_data/publish.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/tabular-data/publish', name: 'tabular_data_publish_event', methods: ['POST'])]
    public function publishEvent(Request $request, NostrClient $nostrClient): Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$data || !isset($data['event'])) {
            return $this->json(['error' => 'Invalid data'], 400);
        }

        $signedEvent = $data['event'];

        // Validate the event
        if ($signedEvent['kind'] !== KindsEnum::TABULAR_DATA->value) {
            return $this->json(['error' => 'Invalid event kind'], 400);
        }

        // Publish the event
        try {
            $nostrClient->publishEvent($signedEvent, ['wss://relay.damus.io', 'wss://nos.lol']);
            return $this->json(['success' => true, 'eventId' => $signedEvent['id']]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Publishing failed: ' . $e->getMessage()], 500);
        }
    }
}
