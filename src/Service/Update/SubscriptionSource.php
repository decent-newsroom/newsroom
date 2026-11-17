<?php

declare(strict_types=1);

namespace App\Service\Update;

use App\Entity\Event;
use App\Enum\UpdateSourceTypeEnum;
use App\Repository\EventRepository;
use App\Service\Nostr\NostrNip19Service;
use Innis\Nostr\Core\Domain\ValueObject\Identity\PublicKey;

final readonly class SubscriptionSource
{
    public const COLLECTION_KINDS = [3, 10000, 10015, 30003, 30004, 30005, 30006, 30015, 30040, 39089];

    public function __construct(
        private NostrNip19Service $nip19,
        private EventRepository $events,
    ) {}

    /** @return array{UpdateSourceTypeEnum, string, ?string} */
    public function parse(string $identifier): array
    {
        $identifier = trim($identifier);
        if (str_starts_with($identifier, 'nostr:')) {
            $identifier = substr($identifier, 6);
        }
        if (preg_match('/^[0-9a-f]{64}$/iD', $identifier) === 1) {
            return [UpdateSourceTypeEnum::NPUB, strtolower($identifier), null];
        }
        if (str_starts_with(strtolower($identifier), 'npub1')) {
            $key = PublicKey::fromBech32(strtolower($identifier));
            if ($key === null) {
                throw new \InvalidArgumentException('Invalid profile key.');
            }
            return [UpdateSourceTypeEnum::NPUB, $key->toHex(), $identifier];
        }
        if (str_starts_with(strtolower($identifier), 'naddr1')) {
            $decoded = $this->nip19->decode(strtolower($identifier));
            if (!isset($decoded['kind'], $decoded['pubkey'], $decoded['identifier'])) {
                throw new \InvalidArgumentException('Invalid address.');
            }
            $identifier = $decoded['kind'] . ':' . $decoded['pubkey'] . ':' . $decoded['identifier'];
        }
        if (strlen($identifier) > 512
            || preg_match('/^(\d+):([0-9a-f]{64}):(.*)$/isD', $identifier, $parts) !== 1) {
            throw new \InvalidArgumentException('Invalid subscription source.');
        }
        $kind = (int) $parts[1];
        if (!in_array($kind, self::COLLECTION_KINDS, true) || ($kind < 30000 && $parts[3] !== '')) {
            throw new \InvalidArgumentException('Unsupported subscription source.');
        }
        return [
            $kind === 30040 ? UpdateSourceTypeEnum::PUBLICATION : UpdateSourceTypeEnum::NIP51_SET,
            $kind . ':' . strtolower($parts[2]) . ':' . $parts[3],
            null,
        ];
    }

    public function currentEvent(UpdateSourceTypeEnum $type, string $value): ?Event
    {
        if ($type === UpdateSourceTypeEnum::NPUB) {
            return null;
        }
        [$kind, $pubkey, $identifier] = explode(':', $value, 3);
        return (int) $kind < 30000
            ? $this->events->findLatestByPubkeyAndKind($pubkey, (int) $kind)
            : $this->events->findByNaddr((int) $kind, $pubkey, $identifier);
    }
}
