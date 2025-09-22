<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use League\CommonMark\Parser\Inline\InlineParserInterface;
use League\CommonMark\Parser\Inline\InlineParserMatch;
use League\CommonMark\Parser\InlineParserContext;
use nostriphant\NIP19\Bech32;
use nostriphant\NIP19\Data\NAddr;
use nostriphant\NIP19\Data\NEvent;
use nostriphant\NIP19\Data\NProfile;
use nostriphant\NIP19\Data\NPub;


class NostrSchemeParser  implements InlineParserInterface
{

    public function __construct()
    {
    }

    public function getMatchDefinition(): InlineParserMatch
    {
        return InlineParserMatch::regex('nostr:[0-9a-zA-Z]+');
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
                    $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $object->data));
                    break;
                case 'nprofile':
                    /** @var NProfile $decodedProfile */
                    $decodedProfile = $decoded->data;
                    $inlineContext->getContainer()->appendChild(new NostrMentionLink(null, $decodedProfile->pubkey));
                    break;
                case 'nevent':
                    /** @var NEvent $decodedNpub */
                    $decodedEvent = $decoded->data;
                    $eventId = $decodedEvent->id;
                    $relays = $decodedEvent->relays;
                    $author = $decodedEvent->author;
                    $kind = $decodedEvent->kind;
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('nevent', $bechEncoded, $relays, $author, $kind));
                    break;
                case 'naddr':
                    /** @var NAddr $decodedNpub */
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
            // dump($e->getMessage());
            return false;
        }

        // Advance the cursor to consume the matched part (important!)
        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
