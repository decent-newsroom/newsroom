# Unfold About Page

Every hosted Unfold magazine has an `/about` page with an introduction and two
people sections. Readers can learn who is associated with the publication, while
owners can choose an article for a longer introduction from either publication
administration mount.

## Overview

The introduction uses the owner's selected published kind `30023` article when
it resolves. Without a selection, one direct kind `30023` article on the root
magazine index is treated as the conventional About article, provided it is the
only direct root article. Otherwise the page uses the root index description, or
a short translated sentence naming the magazine when that description is empty.
An unavailable selected article falls back to that default text. The people
sections appear regardless of which introduction is shown.

"Editorial team" contains the pubkeys that signed the root magazine index and
each category index, in root and category order. "Featured writers" contains
authors of published articles referenced by the categories. Each section
deduplicates normalized pubkeys independently; someone can appear in both.
Profile names and images are used when available, with a pubkey fallback when
metadata is missing.

## Administration

The About article field at `/admin/settings` on the hosted subdomain or
`/mag/{mag}/admin/settings` on the main domain shows the saved selection,
or the sole direct root-index article when no selection is saved. The form shows
the resolved article title beside its coordinate. The owner may enter any
published kind `30023` coordinate or naddr, including an article outside
the magazine.

Changing or clearing the field prepares a replacement root kind `30040`
index. The owner signs it in their Nostr signer; the application verifies the
signature, event identity, and expected tag change before saving. Selecting an
article adds its direct `a` reference to the root index and removes the
previous About reference. Clearing removes the About reference from the root index. If that leaves exactly one other direct article, it becomes the conventional About article. Unrelated root references,
categories, and other event content are preserved. The normalized selection and
any naddr relay hints remain in coordinate-keyed local publication settings.

An unchanged About field needs no new signature, so theme and footer-link saves
continue normally. A rejected signature or stale root index leaves the previous
selection in place. If the signed event saves locally but relays do not accept
it, the form offers a retry using the same signature. A hosted-subdomain owner
whose NIP-46 signer is available only on the main domain can hand the draft
to the main-domain admin form. The existing owner authorization and coordinate-scoped CSRF
protection apply to both admin mounts.

The magazine wizard preserves direct root article references when rebuilding the index. A review submitted after the root index changes is rejected so the owner can reload and sign the current version.

## Architecture

The root kind `30040` index supplies publication metadata and references.
Its kind `30040` references are categories; direct kind `30023` references are
article candidates, never categories. Category references supply the article
authors for Featured writers. The signed root event carries the selected
article as a direct reference; local publication settings retain the explicit
choice and optional relay hints alongside the theme and footer links.

The bundle renders linked article content with the host Markdown converter.
Rendered content caching varies by event ID or content hash so a revised
replaceable article is displayed after its event changes. The default theme
shows the magazine title below the About heading and includes the footer zap
assets. It links to `/about` in navigation and the publication footer, and
the hosted sitemap includes the page. Theme CSS lives in the bundle's
theme `assets`
directory.

## Limits

"Featured writers" here means authors of category articles. It is independent
of the platform-wide featured-writer role. The two sections are deduplicated
separately. If several articles are linked directly by the root index and the
owner has not selected one, none is inferred as About.
