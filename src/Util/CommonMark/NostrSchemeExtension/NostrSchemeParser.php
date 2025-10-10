<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\NostrClient;
use App\Service\RedisCacheService;
use App\Util\NostrKeyUtil;
use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\Note;
use nostriphant\NIP19\Data\NProfile;
use nostriphant\NIP19\Data\NPub;
use Twig\Environment;
use swentel\nostr\Key\Key;


class NostrSchemeParser  implements InlineParserInterface
{

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly NostrClient       $nostrClient,
        private readonly Environment       $twig,
        private readonly NostrKeyUtil      $keyUtil
    )
    {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex(
            'nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[^\\s<>()\\[\\]{}"\'`.,;:!?]*'
        );
    }

    public function parse(InlineParserContext $inlineContext): bool
    {
        $cursor = $inlineContext->getCursor();
        // Get the match and extract relevant parts
        $fullMatch = $inlineContext->getFullMatch();
        // The match is a Bech32 encoded string
        // decode it to get the parts
        $bechEncoded = substr($fullMatch, 6);  // Extract the part after "nostr:", i.e., "XXXX"

        try {
            $decoded = new Bech32($bechEncoded);

            switch ($decoded->type) {
                case 'npub':
                    /** @var NPub $object */
                    $object = $decoded->data;
                    $profile = $this->redisCacheService->getMetadata($this->keyUtil->npubToHex($bechEncoded));
                    if (isset($profile->name)) {
                        $inlineContext->getContainer()->appendChild(new NostrMentionLink($profile->name, $bechEncoded));
                    } else {
                        $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $bechEncoded));
                    }
                    break;
                case 'nprofile':
                    /** @var NProfile $decodedProfile */
                    $decodedProfile = $decoded->data;
                    $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $decodedProfile->pubkey));
                    break;
                case 'note':
                    /** @var Note $decodedEvent */
                    $decodedEvent = $decoded->data;
                    // Fetch the actual event data using the same logic as EventController
                    $event = $this->nostrClient->getEventById($decodedEvent->data);
                    // If note is kind 20
                    // Render the embedded picture card
                    if (!$event || $event->kind !== 20) {
                        // Fallback to simple link if event not found or not kind 20
                        $inlineContext->getContainer()->appendChild(new NostrSchemeData('note', $bechEncoded, [], null, null));
                        break;
                    }
                    $pictureCardHtml = $this->twig->render('/event/_kind20_picture.html.twig', [
                        'event' => $event,
                        'embed' => true
                    ]);
                    // Create a new node type for embedded HTML content
                    $inlineContext->getContainer()->appendChild(new NostrEmbeddedCard($pictureCardHtml));
                    break;
                case 'nevent':
                    /** @var NEvent $decodedEvent */
                    $decodedEvent = $decoded->data;

                    // Fetch the actual event data using the same logic as EventController
                    $event = $this->nostrClient->getEventById($decodedEvent->id, $decodedEvent->relays);

                    if ($event) {
                        $authorMetadata = $this->redisCacheService->getMetadata($event->pubkey);

                        // Render the embedded event card
                        $eventCardHtml = $this->twig->render('components/event_card.html.twig', [
                            'event' => $event,
                            'author' => $authorMetadata,
                            'nevent' => $bechEncoded
                        ]);

                        // Create a new node type for embedded HTML content
                        $inlineContext->getContainer()->appendChild(new NostrEmbeddedCard($eventCardHtml));
                    } else {
                        // Fallback to simple link if event not found
                        $inlineContext->getContainer()->appendChild(new NostrSchemeData('nevent', $bechEncoded, $decodedEvent->relays, $decodedEvent->author, $decodedEvent->kind));
                    }
                    break;
                case 'naddr':
                    /** @var NAddr $decodedEvent */
                    $decodedEvent = $decoded->data;
                    $identifier = $decodedEvent->identifier;
                    $pubkey = $decodedEvent->pubkey;
                    $kind = $decodedEvent->kind;
                    $relays = $decodedEvent->relays;
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('naddr', $bechEncoded, $relays, $pubkey, $kind));
                    break;
                case 'nrelay':
                    // deprecated
                default:
                    return false;
            }

        } catch (\Exception $e) {
            dump($e->getMessage());
            return false;
        }

        // Advance the cursor to consume the matched part (important!)
        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
