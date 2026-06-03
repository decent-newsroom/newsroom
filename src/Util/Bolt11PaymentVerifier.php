<?php

declare(strict_types=1);

namespace App\Util;

use BitWasp\Bech32\Exception\Bech32Exception;

/**
 * Cryptographic verification of Lightning payment proofs.
 *
 * A Lightning payment is proved by the payer providing the payment preimage.
 * The BOLT11 invoice contains the payment hash = sha256(preimage).
 * Verifying sha256(preimage) == payment_hash is trustless and requires no
 * external API call.
 *
 * BOLT11 wire format (after bech32 decode, 5-bit groups, checksum already stripped):
 *   [7 groups] timestamp (35-bit big-endian)
 *   [N groups] tagged fields until end - 104 groups (signature + recovery)
 *     Each field: tag (1×5bit) | len_hi (1×5bit) | len_lo (1×5bit) | data (len×5bit)
 *   [103 groups] 520-bit signature
 *   [1  group ] recovery ID
 *
 * Tag 1  = payment_hash (52 groups → 32 bytes)
 * Tag 16 = payment_secret (52 groups → 32 bytes)
 */
final class Bolt11PaymentVerifier
{
    private const TAG_PAYMENT_HASH = 1;

    /**
     * Verify that a payment preimage matches a BOLT11 invoice.
     *
     * @param string $preimageHex  64-char hex string (32 bytes — the payment preimage)
     * @param string $bolt11       Full BOLT11 invoice (lnbc...)
     *
     * @return bool True if sha256(preimage) == payment_hash from invoice
     * @throws \InvalidArgumentException If the invoice or preimage are malformed
     */
    public static function verifyPreimage(string $preimageHex, string $bolt11): bool
    {
        $preimageHex = strtolower(trim($preimageHex));
        if (!preg_match('/^[0-9a-f]{64}$/', $preimageHex)) {
            throw new \InvalidArgumentException('Preimage must be a 64-character hex string (32 bytes).');
        }

        $paymentHash = self::extractPaymentHash($bolt11);
        if ($paymentHash === null) {
            throw new \InvalidArgumentException('Could not extract payment hash from BOLT11 invoice.');
        }

        // sha256(preimage) must equal payment_hash
        $computedHash = hash('sha256', hex2bin($preimageHex));
        return hash_equals($computedHash, $paymentHash);
    }

    /**
     * Extract the payment hash (as a hex string) from a BOLT11 invoice.
     *
     * @param string $bolt11 The raw BOLT11 invoice string
     * @return string|null 64-char hex payment hash, or null on parse failure
     */
    public static function extractPaymentHash(string $bolt11): ?string
    {
        try {
            $bolt11 = strtolower(trim($bolt11));

            // decodeRaw returns [$hrp, $data5] where data5 is already
            // stripped of the 6-group bech32 checksum.
            [$hrp, $data5] = \BitWasp\Bech32\decodeRaw($bolt11);

            // Must be a mainnet, testnet, regtest, or signet invoice.
            if (!preg_match('/^ln(bc|tb|bcrt|tbs)/', $hrp)) {
                return null;
            }

            $totalGroups = count($data5);

            // The last 104 groups are signature (103) + recovery ID (1).
            $fieldsEnd = $totalGroups - 104;
            if ($fieldsEnd < 7) {
                return null;
            }

            // Skip 7 timestamp groups.
            $pos = 7;

            while ($pos + 2 < $fieldsEnd) {
                $tag = $data5[$pos];
                $len = ($data5[$pos + 1] << 5) | $data5[$pos + 2];
                $pos += 3;

                if ($pos + $len > $fieldsEnd) {
                    break;
                }

                if ($tag === self::TAG_PAYMENT_HASH && $len === 52) {
                    // 52 groups of 5 bits = 260 bits → 32 bytes (+ 4 pad bits, dropped by convertBits).
                    $field5 = array_slice($data5, $pos, 52);
                    $bytes  = \BitWasp\Bech32\convertBits($field5, 52, 5, 8, false);
                    return bin2hex(pack('C*', ...$bytes));
                }

                $pos += $len;
            }
        } catch (Bech32Exception) {
            // Invalid bech32 — fall through
        } catch (\Throwable) {
            // Any other parse error — fall through
        }

        return null;
    }

    /**
     * Extract the sats amount from the BOLT11 HRP suffix, e.g. "lnbc5000n1…" → 500.
     *
     * Multipliers: m=milli (×100_000), u=micro (×100), n=nano (×0.1 → floor), p=pico (×0.0001)
     *
     * @return int|null Amount in sats, or null if unparseable / no amount
     */
    public static function extractAmountSats(string $bolt11): ?int
    {
        $bolt11 = strtolower(trim($bolt11));
        // HRP: ln + network + optional_amount + separator '1'
        if (!preg_match('/^ln(?:bc|tb|bcrt|tbs)(\d+)([munp])?1/', $bolt11, $m)) {
            return null;
        }

        $amount = (int) $m[1];
        $unit   = $m[2] ?? '';

        // All amounts in BOLT11 are in BTC; convert to sats (1 BTC = 100_000_000 sats).
        $sats = match ($unit) {
            'm'     => (int) ($amount * 100_000),          // milli-BTC
            'u'     => (int) ($amount * 100),              // micro-BTC
            'n'     => (int) floor($amount / 10),          // nano-BTC
            'p'     => (int) floor($amount / 10_000),      // pico-BTC
            ''      => $amount * 100_000_000,              // whole BTC
            default => null,
        };

        return $sats > 0 ? $sats : null;
    }
}

