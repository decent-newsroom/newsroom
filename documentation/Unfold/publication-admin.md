# Unfold Publication Administration

Publication owners can view their publication and change its local theme through
one administration surface, mounted on a registered subdomain at `/admin` and
on the main domain at `/mag/{mag}/admin`. Here `{mag}` is the root index
d-tag belonging to the signed-in owner. Main-domain administration also supports
publications without hosting.

## Overview

The overview displays publication title, immutable root coordinate, owner pubkey,
saved theme, and hosting status. Hosted publications also link to their public
site, RSS feed, and sitemap; the public site opens in a new tab.

Settings at `<admin-prefix>/settings` edit only the theme. Both mounts use the
same coordinate-keyed local settings and theme catalogue as operator/subscription
setup. Saving a theme does not require event signing or relay publication.

## Architecture

### Identity and storage

The identity is `30040:<owner-hex-pubkey>:<dtag>`. The complete coordinate is
immutable and cannot be selected or changed through submitted form fields.
Existing settings persistence is reused, with the default theme for publications
without settings. Hosting mappings and subscription billing remain separate.

The bundle-owned `PublicationContext` carries the coordinate, owner, d-tag,
settings, mount, admin prefix, and optional hosting/public URL. Host and coordinate
resolvers implement one contract. A host identity adapter exposes the normalized
authenticated hex pubkey and login URL, keeping host entities outside the bundle.

Subdomain resolution uses that host's mapping and checks ownership before reading
publication metadata. Non-owners receive 403, including platform administrators.
Malformed hosting coordinates produce an unavailable/repair state without an
editable shell.

Main-domain resolution requests the exact coordinate formed from the signed-in
pubkey and route d-tag, then validates the returned event identity. It never uses
slug-only lookup or its magazine projection. Missing or mismatched events return
404; infrastructure failures produce an unavailable response. Another owner's
colliding d-tag cannot affect resolution. When multiple hosting mappings exist,
the coordinate mount selects the oldest deterministically for public links.

### Request and save flow

1. Explicitly marked publication-admin routes run before public catch-all routing.
   Main-domain routes require the configured main domain; subdomain routes require
   registered hosting. Public category routing reserves `/admin` and descendants.
2. Anonymous requests redirect to main-domain login with a validated continuation.
   Only recognized admin paths on the main domain or registered publication hosts
   are accepted. Login behavior without a continuation stays unchanged.
3. Central resolution authorizes the request and attaches its context for shared
   controllers and templates. Context stays on the request, preventing state
   reuse across consecutive FrankenPHP requests.
4. Theme saves check coordinate-scoped CSRF and the shared theme catalogue,
   persist against the authorized coordinate, and invalidate site configuration.
   Successful saves redirect to settings. Invalid CSRF returns 403; invalid themes
   or storage failures retain the submitted input and display an error.
5. Admin responses use `Cache-Control: private, no-store`. Hosted settings remain
   accessible during temporary publication metadata failures.

Only explicitly marked owner-admin routes bypass the blanket platform admin-role
requirement; ownership remains mandatory. Platform moderation, repair, and billing
routes retain their protections.

### Key files

| Location | Role |
| --- | --- |
| `packages/unfold-bundle/src/Admin/` | Context, mount, resolvers, and central request handling |
| `packages/unfold-bundle/src/Contract/PublicationAdminIdentityInterface.php` | Host identity and login integration boundary |
| `packages/unfold-bundle/src/Config/PublicationSettingsManager.php` | Shared local theme validation, persistence, and cache invalidation |
| `packages/unfold-bundle/Resources/` | Bundle route collections and templates |
| `packages/unfold-bundle/assets/admin.css` | AssetMapper administration styles |

## Configuration

This slice adds no database migration or session configuration. Shared session
cookies enable a return from main-domain login to subdomain administration.
When cookie sharing is not configured, or on localhost, login instead opens the
same owner's publication at the main-domain coordinate mount, preserving the
requested overview/settings page and development port. A repeated cross-host
return within 60 seconds uses the same fallback, preventing redirect loops when
a browser does not send the shared cookie. The publication owner is checked
before either return; another owner's colliding d-tag cannot change the destination.

| Setting | Existing value | Purpose |
| --- | --- | --- |
| `SESSION_COOKIE_DOMAIN` | Parent domain, for example `.decentnewsroom.com` | Sends the authenticated session cookie to both main domain and publication subdomains |
| `framework.session.cookie_secure` | `auto` | Uses secure cookies on HTTPS |
| `framework.session.cookie_samesite` | `lax` | Preserves the existing session behavior |

Use the actual parent domain for each deployment and retain existing Redis-backed
session handling. A host-only cookie cannot carry main-domain authentication back
to a publication subdomain; main-domain administration remains available through
the automatic fallback.

## Limitations and deferred work

Wizard and draft migration, analytics, footer configuration, content editing,
payments, and reader interactions remain pending. This slice does not reassign
publication roots or hosting, or change billing.

Publication-definition events will be respecified only after gated access is
complete. No definition-event implementation or compatibility workflow belongs
to the active slice.

See [Local setup and settings](site-creation-signing.md) for shared setup and
[the bundle administration spec](../../packages/unfold-bundle/Specs/08-unified-publication-admin.md)
for the delivered foundation and future consolidation work.
