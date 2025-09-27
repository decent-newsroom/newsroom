<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\KindsEnum;
use App\Service\NostrClient;
use App\Service\NostrLinkParser;
use App\Service\RedisCacheService;
use Exception;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data;
use nostriphant\NIP19\Data\Note;
use Psr\Log\LoggerInterface;
use swentel\nostr\Key\Key;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

class EventController extends AbstractController
{
    /**
     * @throws Exception
     */
    #[Route('/e/{nevent}', name: 'nevent', requirements: ['nevent' => '^nevent1.*'])]
    public function index($nevent, NostrClient $nostrClient, RedisCacheService $redisCacheService, NostrLinkParser $nostrLinkParser, LoggerInterface $logger): Response
    {
        $logger->info('Accessing event page', ['nevent' => $nevent]);

        try {
            // Decode nevent - nevent1... is a NIP-19 encoded event identifier
            $decoded = new Bech32($nevent);
            $logger->info('Decoded event', ['decoded' => json_encode($decoded)]);

            // Get the event using the event ID
            /** @var Data $data */
            $data = $decoded->data;
            $logger->info('Event data', ['data' => json_encode($data)]);

            // Sort which event type this is using $data->type
            switch ($decoded->type) {
                case 'note':
                    // Handle note (regular event)
                    $event = $nostrClient->getEventById($data->data);
                    break;

                case 'nprofile':
                    // Redirect to author profile if it's a profile identifier
                    $logger->info('Redirecting to author profile', ['pubkey' => $data->pubkey]);
                    return $this->redirectToRoute('author-redirect', ['pubkey' => $data->pubkey]);

                case 'nevent':
                    // Handle nevent identifier (event with additional metadata)
                    $relays = $data->relays ?? null;
                    $event = $nostrClient->getEventById($data->id, $relays);
                    break;

                case 'naddr':
                    // Handle naddr (parameterized replaceable event)
                    $decodedData = [
                        'kind' => $data->kind,
                        'pubkey' => $data->pubkey,
                        'identifier' => $data->identifier,
                        'relays' => $data->relays ?? []
                    ];
                    $event = $nostrClient->getEventByNaddr($decodedData);
                    if ($data->kind === KindsEnum::LONGFORM->value) {
                        // If it's a long-form content, redirect to the article page
                        $logger->info('Redirecting to article', ['identifier' => $data->identifier]);
                        return $this->redirectToRoute('article-slug', ['slug' => $data->identifier]);
                    }
                    break;

                default:
                    $logger->error('Unsupported event type', ['type' => $decoded->type]);
                    throw new NotFoundHttpException('Unsupported event type: ' . $decoded->type);
            }

            if (!$event) {
                $logger->warning('Event not found', ['data' => $data]);
                throw new NotFoundHttpException('Event not found');
            }

            // Parse event content for Nostr links
            $nostrLinks = [];
            if (isset($event->content)) {
                $nostrLinks = $nostrLinkParser->parseLinks($event->content);
                $logger->info('Parsed Nostr links from content', ['count' => count($nostrLinks)]);
            }

            // If author is included in the event, get metadata
            $authorMetadata = null;
            if (isset($event->pubkey)) {
                $key = new Key();
                $npub = $key->convertPublicKeyToBech32($event->pubkey);
                $authorMetadata = $redisCacheService->getMetadata($npub);
            }

            // Render template with the event data and extracted Nostr links
            return $this->render('event/index.html.twig', [
                'event' => $event,
                'author' => $authorMetadata,
                'nostrLinks' => $nostrLinks
            ]);

        } catch (Exception $e) {
            $logger->error('Error processing event', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
