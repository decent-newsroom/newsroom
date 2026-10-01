<?php

declare(strict_types=1);

namespace DecentNewsroom\UnfoldBundle\Contract;

use DecentNewsroom\UnfoldBundle\Config\PublicationDraft;

/** Host-owned persistence for unpublished, owner-scoped publication setup state. */
interface PublicationDraftStoreInterface
{
    /** $provisionalKey must be normalized with PublicationDraft::parseProvisionalKey(). */
    public function findByProvisionalKey(string $provisionalKey): ?PublicationDraft;

    public function save(PublicationDraft $draft): void;

    /** $provisionalKey must be normalized with PublicationDraft::parseProvisionalKey(). */
    public function discardByProvisionalKey(string $provisionalKey): void;

    /** Atomically moves the draft from its provisional key to its derived canonical root-coordinate key. */
    public function migrateProvisionalToCanonical(PublicationDraft $draft): void;
}
