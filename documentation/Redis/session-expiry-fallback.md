# Relay Selection When a Session Expires

The editor can recover publish relay targets from the signed event's author when the Symfony session is absent. This is relay selection fallback; it does not renew the session or remove signing and event-validation requirements.

## Editor publishing

`src/Controller/Editor/EditorController.php` first uses the logged-in user's stored relays when available. Without a session, it reads the signed event's hex pubkey, looks up the User by npub, and tries the stored relay list. It can fall back to `UserRelayListService::getRelaysForPublishing()` and configured fallback relays.

## Article broadcasting

`src/Controller/Api/ArticleBroadcastController.php` behaves differently. If a broadcast request omits relay targets, the controller requires a logged-in user and returns 401 when no user exists. It does not automatically select the article author's relays after session expiry. Requests with explicit relay targets follow the broadcast endpoint's validation and routing rules.

Both paths use [Relay Pool Management](../Strfry/relay-pool.md) for relay resolution. Public project URLs are remapped/deduplicated for server-side requests; internal relay URLs must not be displayed to browsers.

## Verification

For editor changes, exercise publishing with and without a session, with missing stored relays, and with relay lookup failures. For broadcast changes, check that an empty relay list without a session returns the authentication error and that explicit targets receive the expected validation.

## Future ideas

Session-expiry warnings, renewal during active editing, and browser-side relay caching remain potential improvements. They require a separate design and should not be described as existing behavior.
