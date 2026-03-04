<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Categorizes relay URLs by their intended purpose.
 *
 * Relay specialization is intentional — profile relays (purplepag.es)
 * index user metadata and relay lists; content relays serve articles and
 * events; the project relay is the Decent Newsroom relay; the local relay
 * is the strfry instance.
 *
 * LOCAL and PROJECT point to the **same physical relay** (strfry) but via
 * different network paths:
 *   LOCAL   = internal Docker hostname (e.g. ws://strfry:7777) — used by
 *             the server for subscriptions, writes, and worker processes.
 *   PROJECT = public hostname (e.g. wss://relay.decentnewsroom.com) — used
 *             in the UI, in relay hints, and anywhere users/clients connect.
 *
 * Use RelayRegistry::getPublicUrl() when you need the user-facing URL for
 * the local relay.
 */
enum RelayPurpose: string
{
    /** Profile metadata + relay lists (kind 0, kind 10002) — e.g. purplepag.es */
    case PROFILE = 'profile';

    /** Articles, media, generic content — e.g. theforest, damus, primal */
    case CONTENT = 'content';

    /**
     * Public hostname of the project relay (wss://relay.decentnewsroom.com).
     * Same physical relay as LOCAL — use this for UI display and relay hints.
     */
    case PROJECT = 'project';

    /**
     * Internal Docker hostname of the local strfry instance (e.g. ws://strfry:7777).
     * Same physical relay as PROJECT — use this for server-side subscriptions and writes.
     */
    case LOCAL = 'local';

    /** Dynamic per-user relays from NIP-65 relay list (kind 10002) */
    case USER = 'user';

    /** Relays used for NIP-46 bunker / nostr-connect signing */
    case SIGNER = 'signer';
}

