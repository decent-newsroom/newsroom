<?php

declare(strict_types=1);

namespace App\Service\EventWorkspace;

use App\Dto\EventWorkspace\EventReferenceRole;
use App\Dto\EventWorkspace\EventReferenceStatus;
use App\Entity\Event;
use App\Enum\KindsEnum;
use App\Service\Nostr\NostrNip19Service;

/**
 * Read-only npub pages over an already-authorized follow pack's public members.
 *
 * The caller must enforce source access, deletion, suppression and privacy.
 * This service neither authorizes nor resolves members or their profiles.
 */
final readonly class FollowPackMemberPage
{
    public const PAGE_SIZE = 50;

    public function __construct(
        private EventWorkspaceSourceAdapter $sources,
        private EventPresentationFactory $presentations,
        private NostrNip19Service $nip19,
    ) {}

    /**
     * @return array{
     *     eventId: string,
     *     members: list<array{position: int, rawValue: ?string, npub: ?string}>,
     *     page: int,
     *     hasNext: bool,
     *     hasPrevious: bool
     * }
     */
    public function fromAuthorizedEvent(Event $event, int $page = 1): array
    {
        if ($event->getKind() !== KindsEnum::FOLLOW_PACK->value) {
            throw new \InvalidArgumentException('Member pages require a kind 39089 follow pack.');
        }
        if ($page < 1) {
            throw new \InvalidArgumentException('Member page numbers must be positive.');
        }

        $source = $this->sources->fromOwnedCollectionEvent($event);
        $result = [
            'eventId' => $source->eventId,
            'members' => [],
            'page' => $page,
            'hasNext' => false,
            'hasPrevious' => $page > 1,
        ];

        // Avoid converting an overflowing offset to float, even for PHP_INT_MAX.
        if ($page - 1 > intdiv(PHP_INT_MAX, self::PAGE_SIZE)) {
            return $result;
        }
        $offset = ($page - 1) * self::PAGE_SIZE;
        $memberIndex = 0;
        foreach ($this->presentations->fromAuthorizedEvent($event)->references as $reference) {
            if ($reference->role !== EventReferenceRole::FollowPackMember) {
                continue;
            }
            if ($memberIndex++ < $offset) {
                continue;
            }
            if (count($result['members']) === self::PAGE_SIZE) {
                $result['hasNext'] = true;
                break;
            }

            $result['members'][] = [
                'position' => $reference->position,
                'rawValue' => $reference->rawValue,
                'npub' => $reference->status === EventReferenceStatus::Unresolved && $reference->target !== null
                    ? $this->nip19->encodeNpub($reference->target)
                    : null,
            ];
        }

        return $result;
    }
}
