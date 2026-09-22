# Candidate Nostr Event Kinds

This page retains future feature ideas from the event-kind review. `src/Enum/KindsEnum.php` is the source of truth for declared kinds; an enum entry alone does not establish complete fetching, projection, rendering, or publishing support.

The earlier highest-priority additions are already declared: deletion requests (5), mute lists (10000), pins (10001), interest sets (30015), zap requests (9734), playlists (34139), Blossom server lists (10063), reports (1984), and labels (1985). Do not re-add them from the old review checklist.

## Remaining candidates

| Kind | Proposed use |
|---|---|
| 39092 | Media starter packs for discovery |
| 30000 | Personal categorized follow sets |
| 17 | Reactions to external content such as podcast episodes |
| 1068 / 1018 | Polls and responses |
| 30311 | Live events if live streaming becomes a product feature |
| 30315 | User status |
| 30009 / 8 / 30008 | Badge definitions, awards, and profile badges |
| 9041 | Fundraising zap goals |
| 31989 / 31990 | Cross-client app-handler discovery |

These are proposals, not commitments. Verify the applicable protocol definitions and current implementation before selecting a kind. Follow `skills/add-nostr-event-kind.md` for an end-to-end implementation, and keep fetch groups in `src/Enum/KindBundles.php` rather than copying a stale integer list from this review. See [Fetch Strategy](fetch-optimization-implementation.md).
