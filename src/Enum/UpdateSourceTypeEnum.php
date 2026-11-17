<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Persisted local source identities, independent of payment entitlements.
 */
enum UpdateSourceTypeEnum: string
{
    /** Single author pubkey. `source_value` = 64-char hex pubkey. */
    case NPUB = 'npub';

    /** Publication index coordinate. `source_value` = `30040:<pubkey>:<d>`. */
    case PUBLICATION = 'publication';

    /**
     * NIP-51 set coordinate. `source_value` = `<kind>:<pubkey>:<d>` where
     * See SubscriptionSource::COLLECTION_KINDS for supported kinds.
     * Kinds 3 and 10015 use empty d-tag, stored as `3:<pk>:` / `10015:<pk>:`.
     */
    case NIP51_SET = 'nip51_set';

    public function label(): string
    {
        return match ($this) {
            self::NPUB => 'updates.subscription.sourceType.npub',
            self::PUBLICATION => 'updates.subscription.sourceType.publication',
            self::NIP51_SET => 'updates.subscription.sourceType.nip51Set',
        };
    }
}
