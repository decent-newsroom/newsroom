/**
 * Nostr utilities for handling pubkeys, relays, and tag building.
 * Includes NIP-19 bech32 encoding/decoding with TLV support.
 */

// Bech32 character set
const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

// Bech32 polymod helpers
function bech32Polymod(values: number[]): number {
    const GEN = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    let chk = 1;
    for (const v of values) {
        const b = chk >> 25;
        chk = ((chk & 0x1ffffff) << 5) ^ v;
        for (let i = 0; i < 5; i++) {
            if ((b >> i) & 1) chk ^= GEN[i];
        }
    }
    return chk;
}

function bech32HrpExpand(hrp: string): number[] {
    const ret: number[] = [];
    for (let i = 0; i < hrp.length; i++) ret.push(hrp.charCodeAt(i) >> 5);
    ret.push(0);
    for (let i = 0; i < hrp.length; i++) ret.push(hrp.charCodeAt(i) & 31);
    return ret;
}

function bech32CreateChecksum(hrp: string, data: number[]): number[] {
    const values = bech32HrpExpand(hrp).concat(data).concat([0, 0, 0, 0, 0, 0]);
    const polymod = bech32Polymod(values) ^ 1;
    const ret: number[] = [];
    for (let i = 0; i < 6; i++) ret.push((polymod >> (5 * (5 - i))) & 31);
    return ret;
}

/**
 * Convert 8-bit bytes to 5-bit groups for bech32 encoding
 */
function convertBits(data: Uint8Array, fromBits: number, toBits: number, pad: boolean): number[] {
    let acc = 0;
    let bits = 0;
    const ret: number[] = [];
    const maxv = (1 << toBits) - 1;

    for (const value of data) {
        acc = (acc << fromBits) | value;
        bits += fromBits;
        while (bits >= toBits) {
            bits -= toBits;
            ret.push((acc >> bits) & maxv);
        }
    }

    if (pad) {
        if (bits > 0) {
            ret.push((acc << (toBits - bits)) & maxv);
        }
    }

    return ret;
}

/**
 * Encode bytes to bech32 string
 */
function bech32Encode(prefix: string, data: Uint8Array): string {
    const data5 = convertBits(data, 8, 5, true);
    const checksum = bech32CreateChecksum(prefix, data5);
    const combined = data5.concat(checksum);
    let result = prefix + '1';
    for (const c of combined) {
        result += BECH32_CHARSET[c];
    }
    return result;
}

/**
 * Decode bech32 string to prefix and raw bytes
 */
function bech32Decode(str: string): { prefix: string; data: Uint8Array } | null {
    const lowered = str.toLowerCase();

    // Find the separator
    let sepIndex = lowered.lastIndexOf('1');
    if (sepIndex < 1) return null;

    const prefix = lowered.substring(0, sepIndex);
    const dataStr = lowered.substring(sepIndex + 1);

    if (dataStr.length < 6) return null;

    // Decode the data
    const values: number[] = [];
    for (let i = 0; i < dataStr.length; i++) {
        const c = dataStr[i];
        const v = BECH32_CHARSET.indexOf(c);
        if (v === -1) return null;
        values.push(v);
    }

    // Remove checksum (last 6 chars)
    const data = values.slice(0, -6);

    // Convert from 5-bit to 8-bit
    const bytes: number[] = [];
    let acc = 0;
    let bits = 0;

    for (const value of data) {
        acc = (acc << 5) | value;
        bits += 5;

        if (bits >= 8) {
            bits -= 8;
            bytes.push((acc >> bits) & 0xff);
        }
    }

    return {
        prefix,
        data: new Uint8Array(bytes)
    };
}

/**
 * Convert Uint8Array to hex string
 */
function bytesToHex(bytes: Uint8Array): string {
    return Array.from(bytes)
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Convert hex string to Uint8Array
 */
function hexToBytes(hex: string): Uint8Array {
    const bytes = new Uint8Array(hex.length / 2);
    for (let i = 0; i < hex.length; i += 2) {
        bytes[i / 2] = parseInt(hex.substring(i, i + 2), 16);
    }
    return bytes;
}

/**
 * NIP-19 TLV types
 */
const TLV_SPECIAL = 0;
const TLV_RELAY = 1;
const TLV_AUTHOR = 2;
const TLV_KIND = 3;

/**
 * Parse TLV data from bytes
 */
function parseTLV(data: Uint8Array): { [key: number]: Uint8Array[] } {
    const result: { [key: number]: Uint8Array[] } = {};
    let pos = 0;
    while (pos < data.length) {
        const type = data[pos];
        const length = data[pos + 1];
        const value = data.slice(pos + 2, pos + 2 + length);
        if (!result[type]) {
            result[type] = [];
        }
        result[type].push(value);
        pos += 2 + length;
    }
    return result;
}

/**
 * Build TLV byte array from entries
 */
function buildTLV(entries: Array<{ type: number; value: Uint8Array }>): Uint8Array {
    let totalLen = 0;
    for (const entry of entries) {
        totalLen += 2 + entry.value.length;
    }
    const result = new Uint8Array(totalLen);
    let pos = 0;
    for (const entry of entries) {
        result[pos] = entry.type;
        result[pos + 1] = entry.value.length;
        result.set(entry.value, pos + 2);
        pos += 2 + entry.value.length;
    }
    return result;
}

/**
 * Decoded NIP-19 entity
 */
export interface DecodedNip19 {
    type: 'npub' | 'note' | 'nprofile' | 'nevent' | 'naddr';
    data: {
        hex?: string;        // pubkey hex for npub/nprofile, event id hex for note/nevent
        pubkey?: string;     // author hex for naddr and optionally nevent
        relays?: string[];   // relay hints
        kind?: number;       // kind for naddr, optionally nevent
        identifier?: string; // d-tag for naddr
    };
}

/**
 * Decode any NIP-19 bech32 entity
 */
export function decodeNip19(bech32str: string): DecodedNip19 | null {
    try {
        const decoded = bech32Decode(bech32str);
        if (!decoded) return null;

        const { prefix, data } = decoded;

        switch (prefix) {
            case 'npub':
                return { type: 'npub', data: { hex: bytesToHex(data) } };

            case 'note':
                return { type: 'note', data: { hex: bytesToHex(data) } };

            case 'nprofile': {
                const tlv = parseTLV(data);
                const special = (tlv[TLV_SPECIAL] || [])[0];
                if (!special) return null;
                const relays = (tlv[TLV_RELAY] || []).map((r: Uint8Array) => new TextDecoder().decode(r));
                return { type: 'nprofile', data: { hex: bytesToHex(special), relays } };
            }

            case 'nevent': {
                const tlv = parseTLV(data);
                const special = (tlv[TLV_SPECIAL] || [])[0];
                if (!special) return null;
                const relays = (tlv[TLV_RELAY] || []).map((r: Uint8Array) => new TextDecoder().decode(r));
                const authorBytes = (tlv[TLV_AUTHOR] || [])[0];
                const kindBytes = (tlv[TLV_KIND] || [])[0];
                const result: DecodedNip19 = {
                    type: 'nevent',
                    data: {
                        hex: bytesToHex(special),
                        relays,
                    }
                };
                if (authorBytes) result.data.pubkey = bytesToHex(authorBytes);
                if (kindBytes) {
                    result.data.kind = (kindBytes[0] << 24) | (kindBytes[1] << 16) | (kindBytes[2] << 8) | kindBytes[3];
                }
                return result;
            }

            case 'naddr': {
                const tlv = parseTLV(data);
                const special = (tlv[TLV_SPECIAL] || [])[0];
                if (!special) return null;
                const identifier = new TextDecoder().decode(special);
                const relays = (tlv[TLV_RELAY] || []).map((r: Uint8Array) => new TextDecoder().decode(r));
                const authorBytes = (tlv[TLV_AUTHOR] || [])[0];
                const kindBytes = (tlv[TLV_KIND] || [])[0];
                return {
                    type: 'naddr',
                    data: {
                        identifier,
                        pubkey: authorBytes ? bytesToHex(authorBytes) : undefined,
                        relays,
                        kind: kindBytes ? ((kindBytes[0] << 24) | (kindBytes[1] << 16) | (kindBytes[2] << 8) | kindBytes[3]) : undefined,
                    }
                };
            }

            default:
                return null;
        }
    } catch (e) {
        console.error('Error decoding NIP-19:', e);
        return null;
    }
}

/**
 * Encode an nprofile bech32 string from hex pubkey + optional relays
 */
export function encodeNprofile(pubkeyHex: string, relays: string[] = []): string {
    const entries: Array<{ type: number; value: Uint8Array }> = [];
    entries.push({ type: TLV_SPECIAL, value: hexToBytes(pubkeyHex) });
    for (const relay of relays) {
        entries.push({ type: TLV_RELAY, value: new TextEncoder().encode(relay) });
    }
    return bech32Encode('nprofile', buildTLV(entries));
}

/**
 * Encode an naddr bech32 string from kind + pubkey hex + d-tag + optional relays
 */
export function encodeNaddr(kind: number, pubkeyHex: string, identifier: string, relays: string[] = []): string {
    const entries: Array<{ type: number; value: Uint8Array }> = [];
    entries.push({ type: TLV_SPECIAL, value: new TextEncoder().encode(identifier) });
    for (const relay of relays) {
        entries.push({ type: TLV_RELAY, value: new TextEncoder().encode(relay) });
    }
    entries.push({ type: TLV_AUTHOR, value: hexToBytes(pubkeyHex) });
    const kindBytes = new Uint8Array(4);
    kindBytes[0] = (kind >> 24) & 0xff;
    kindBytes[1] = (kind >> 16) & 0xff;
    kindBytes[2] = (kind >> 8) & 0xff;
    kindBytes[3] = kind & 0xff;
    entries.push({ type: TLV_KIND, value: kindBytes });
    return bech32Encode('naddr', buildTLV(entries));
}

/**
 * Extract nostr: references from text content and return auto-generated p, e, a tags.
 * Per NIP-27, mentioned npub/nprofile add 'p' tags, note/nevent add 'e' tags,
 * and naddr adds 'a' tags.
 *
 * @param content   The markdown content to scan
 * @param mentionNames  Optional map of hex pubkey → display name.
 *                      When provided, 'p' tags include the name so clients
 *                      don't need to resolve the profile at render time.
 */
export function extractNostrTags(content: string, mentionNames?: { [hex: string]: string }): string[][] {
    const NOSTR_RE = /nostr:(?:npub1|nprofile1|note1|nevent1|naddr1)[a-z0-9]+/gi;
    const tags: string[][] = [];
    const seenP: { [key: string]: boolean } = {};
    const seenE: { [key: string]: boolean } = {};
    const seenA: { [key: string]: boolean } = {};
    const names = mentionNames || {};

    let match: RegExpExecArray | null;
    while ((match = NOSTR_RE.exec(content)) !== null) {
        const bech = match[0].substring(6); // strip 'nostr:'
        const decoded = decodeNip19(bech);
        if (!decoded) continue;

        switch (decoded.type) {
            case 'npub': {
                const hex = decoded.data.hex!;
                if (!seenP[hex]) {
                    seenP[hex] = true;
                    const tag: string[] = ['p', hex];
                    if (names[hex]) tag.push('', names[hex]);
                    tags.push(tag);
                }
                break;
            }
            case 'nprofile': {
                const hex = decoded.data.hex!;
                if (!seenP[hex]) {
                    seenP[hex] = true;
                    const relay = decoded.data.relays?.[0] || '';
                    const tag: string[] = ['p', hex, relay];
                    if (names[hex]) tag.push(names[hex]);
                    tags.push(tag);
                }
                break;
            }
            case 'note': {
                const hex = decoded.data.hex!;
                if (!seenE[hex]) {
                    seenE[hex] = true;
                    tags.push(['e', hex, '', 'mention']);
                }
                break;
            }
            case 'nevent': {
                const hex = decoded.data.hex!;
                if (!seenE[hex]) {
                    seenE[hex] = true;
                    const relay = decoded.data.relays?.[0] || '';
                    const tag: string[] = ['e', hex, relay, 'mention'];
                    tags.push(tag);
                }
                // Also add p tag for the event author if present
                if (decoded.data.pubkey && !seenP[decoded.data.pubkey]) {
                    seenP[decoded.data.pubkey] = true;
                    tags.push(['p', decoded.data.pubkey]);
                }
                break;
            }
            case 'naddr': {
                const d = decoded.data;
                if (d.kind !== undefined && d.pubkey && d.identifier !== undefined) {
                    const coord = `${d.kind}:${d.pubkey}:${d.identifier}`;
                    if (!seenA[coord]) {
                        seenA[coord] = true;
                        const relay = d.relays?.[0] || '';
                        tags.push(['a', coord, relay]);
                    }
                    // Also add p tag for the naddr author
                    if (!seenP[d.pubkey]) {
                        seenP[d.pubkey] = true;
                        tags.push(['p', d.pubkey]);
                    }
                }
                break;
            }
        }
    }

    return tags;
}

/**
 * Convert npub to hex pubkey
 */
export function npubToHex(npub: string): string | null {
    if (!npub.startsWith('npub1')) {
        // Check if it's already hex
        if (/^[0-9a-f]{64}$/i.test(npub)) {
            return npub.toLowerCase();
        }
        return null;
    }

    try {
        const decoded = bech32Decode(npub);
        if (!decoded || decoded.prefix !== 'npub') {
            return null;
        }

        return bytesToHex(decoded.data);
    } catch (e) {
        console.error('Error decoding npub:', e);
        return null;
    }
}

/**
 * Validate if a string is a valid npub or hex pubkey
 */
export function isValidPubkey(pubkey: string): boolean {
    if (!pubkey) return false;

    // Check if hex (64 chars)
    if (/^[0-9a-f]{64}$/i.test(pubkey)) {
        return true;
    }

    // Check if npub
    if (pubkey.startsWith('npub1')) {
        return npubToHex(pubkey) !== null;
    }

    return false;
}

/**
 * Validate relay URL
 */
export function isValidRelay(relay: string): boolean {
    if (!relay) return true; // Empty is valid (optional)

    try {
        const url = new URL(relay);
        return url.protocol === 'wss:';
    } catch {
        return false;
    }
}

/**
 * ZapSplit interface
 */
export interface ZapSplit {
    recipient: string;
    relay?: string;
    weight?: number;
    sharePercent?: number;
}

/**
 * Calculate share percentages for zap splits
 */
export function calculateShares(splits: ZapSplit[]): number[] {
    if (splits.length === 0) {
        return [];
    }

    // Check if any weights are specified
    const hasWeights = splits.some(s => s.weight !== undefined && s.weight !== null && s.weight > 0);

    if (!hasWeights) {
        // Equal distribution
        const equalShare = 100 / splits.length;
        return splits.map(() => equalShare);
    }

    // Calculate total weight
    const totalWeight = splits.reduce((sum, s) => sum + (s.weight || 0), 0);

    if (totalWeight === 0) {
        return splits.map(() => 0);
    }

    // Calculate weighted shares
    return splits.map(s => {
        const weight = s.weight || 0;
        return (weight / totalWeight) * 100;
    });
}

/**
 * Build a zap tag for Nostr event
 */
export function buildZapTag(split: ZapSplit): (string | number)[] {
    const hexPubkey = npubToHex(split.recipient);
    if (!hexPubkey) {
        throw new Error(`Invalid recipient pubkey: ${split.recipient}`);
    }

    const tag: (string | number)[] = ['zap', hexPubkey];

    // Add relay (even if empty, to maintain position)
    tag.push(split.relay || '');

    // Add weight if specified
    if (split.weight !== undefined && split.weight !== null) {
        tag.push(split.weight);
    }

    return tag;
}

/**
 * Advanced metadata interface
 */
export interface AdvancedMetadata {
    doNotRepublish: boolean;
    license: string;
    customLicense?: string;
    zapSplits: ZapSplit[];
    contentWarning?: string;
    expirationTimestamp?: number;
    isProtected: boolean;
}

/**
 * Build advanced metadata tags for Nostr event
 */
export function buildAdvancedTags(metadata: AdvancedMetadata): any[][] {
    const tags: any[][] = [];

    // Policy: Do not republish
    if (metadata.doNotRepublish) {
        tags.push(['L', 'rights.decent.newsroom']);
        tags.push(['l', 'no-republish', 'rights.decent.newsroom']);
    }

    // License
    const license = metadata.license === 'custom' ? metadata.customLicense : metadata.license;
    if (license && license !== 'All rights reserved') {
        tags.push(['L', 'spdx.org/licenses']);
        tags.push(['l', license, 'spdx.org/licenses']);
    } else if (license === 'All rights reserved') {
        tags.push(['L', 'rights.decent.newsroom']);
        tags.push(['l', 'all-rights-reserved', 'rights.decent.newsroom']);
    }

    // Zap splits
    for (const split of metadata.zapSplits) {
        try {
            tags.push(buildZapTag(split));
        } catch (e) {
            console.error('Error building zap tag:', e);
        }
    }

    // Content warning
    if (metadata.contentWarning) {
        tags.push(['content-warning', metadata.contentWarning]);
    }

    // Expiration
    if (metadata.expirationTimestamp) {
        tags.push(['expiration', metadata.expirationTimestamp.toString()]);
    }

    // Protected event
    if (metadata.isProtected) {
        tags.push(['-']);
    }

    return tags;
}

/**
 * Validate advanced metadata before publishing
 */
export function validateAdvancedMetadata(metadata: AdvancedMetadata): { valid: boolean; errors: string[] } {
    const errors: string[] = [];

    // Validate zap splits
    for (let i = 0; i < metadata.zapSplits.length; i++) {
        const split = metadata.zapSplits[i];

        if (!isValidPubkey(split.recipient)) {
            errors.push(`Zap split ${i + 1}: Invalid recipient pubkey`);
        }

        if (split.relay && !isValidRelay(split.relay)) {
            errors.push(`Zap split ${i + 1}: Invalid relay URL (must start with wss://)`);
        }

        if (split.weight !== undefined && split.weight !== null && split.weight < 0) {
            errors.push(`Zap split ${i + 1}: Weight must be 0 or greater`);
        }
    }

    // Validate expiration is in the future
    if (metadata.expirationTimestamp) {
        const now = Math.floor(Date.now() / 1000);
        if (metadata.expirationTimestamp <= now) {
            errors.push('Expiration date must be in the future');
        }
    }

    return {
        valid: errors.length === 0,
        errors
    };
}

