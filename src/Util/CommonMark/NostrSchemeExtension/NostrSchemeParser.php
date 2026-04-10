<?php

namespace App\Util\CommonMark\NostrSchemeExtension;

use App\Service\Cache\RedisCacheService;
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


/**
 * CommonMark inline parser for `nostr:` URI scheme references.
 *
 * This parser only uses pre-fetched data and local DB — it never makes
 * relay calls.  When event data is not available, it emits a
 * NostrSchemeData node which the renderer turns into `nostr:bech…` text
 * for processNostrLinks() to handle as a deferred embed.
 */
class NostrSchemeParser implements InlineParserInterface
{

    public function __construct(
        private readonly RedisCacheService $redisCacheService,
        private readonly NostrKeyUtil      $keyUtil,
        private readonly ?NostrPrefetchedData $prefetchedData = null,
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
        $fullMatch = $inlineContext->getFullMatch();
        $bechEncoded = substr($fullMatch, 6);

        try {
            $decoded = new Bech32($bechEncoded);

            switch ($decoded->type) {
                case 'npub':
                    /** @var NPub $object */
                    $object = $decoded->data;
                    $hex = $this->keyUtil->npubToHex($bechEncoded);
                    if ($this->prefetchedData !== null && $this->prefetchedData->hasMetadata($hex)) {
                        $profile = $this->prefetchedData->getMetadata($hex);
                    } else {
                        $profile = $this->redisCacheService->getMetadata($hex);
                    }
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
                    // Fall through to NostrSchemeData — processNostrLinks() will
                    // either render a card (if data is locally available) or a
                    // deferred embed placeholder.
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('note', $bechEncoded, [], null, null));
                    break;
                case 'nevent':
                    /** @var NEvent $decodedEvent */
                    $decodedEvent = $decoded->data;
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('nevent', $bechEncoded, $decodedEvent->relays, $decodedEvent->author, $decodedEvent->kind));
                    break;
                case 'naddr':
                    /** @var NAddr $decodedEvent */
                    $decodedEvent = $decoded->data;
                    $inlineContext->getContainer()->appendChild(new NostrSchemeData('naddr', $bechEncoded, $decodedEvent->relays, $decodedEvent->pubkey, $decodedEvent->kind));
                    break;
                case 'nrelay':
                    // deprecated
                default:
                    return false;
            }

        } catch (\Throwable $e) {
            return false;
        }

        $cursor->advanceBy(strlen($fullMatch));

        return true;
    }
}
