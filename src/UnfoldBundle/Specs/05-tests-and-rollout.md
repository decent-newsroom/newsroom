# Tests And Rollout

## Migrations

Expected local schema changes:

- Add `owner_pubkey` to `unfold_site`, nullable for migration then required after backfill.
- Add `app_data_coordinate` to `unfold_site`, nullable.
- Add indexes for owner lookup and subdomain-owner filtering.

Backfill:

- Parse `UnfoldSite.coordinate` as `kind:pubkey:dtag`.
- Store the middle field as `owner_pubkey`.
- Leave malformed coordinates untouched and report them in a diagnostic command or migration warning.

## Parser Coverage

Unit-test AppData parsing/building:

- Named `publication`, `about`, repeated `audience`, `payment_targets`, `home_relay`, `theme`, and `alt` tags.
- Legacy unmarked `a` publication fallback.
- Named `publication` taking precedence over legacy `a`.
- Relay hints preserved on addressable references.
- Invalid coordinate shapes rejected for linked events.
- `home_relay` normalized and invalid URLs rejected.

Unit-test audience parsing:

- `38110` title and summary.
- `G` and `a` publication scope.
- Multiple `price` tags.
- `SATS` price mirrored to legacy `subscription`.
- `expires_in` parsing.
- Optional `payment_descriptor`.

Unit-test payment descriptor parsing:

- `30133` `d`, `G`, and publication `a` tags.
- Multiple NIP-A3 `payto` tags.
- Duplicate payment target handling consistent with existing `PaymentTargetService`.

## Functional Coverage

Owner admin:

- Owner can access `/admin` on their Unfold subdomain.
- Non-owner receives access denied.
- Anonymous visitor is redirected to login.
- DN admin behavior is covered for explicitly documented operator routes only.
- Signed AppData with mismatched pubkey is rejected.

Feeds and sitemap:

- `/rss.xml` and `/feed.xml` return RSS XML and publication-local absolute URLs.
- `/{category}/rss.xml` returns only category articles.
- Unknown category feed returns 404.
- `/sitemap.xml` returns XML with home, category, article, and about URLs.
- Cache headers and content types match the spec.

Protocol feature spec:

- Add a Gherkin feature covering owner-signed AppData linking publication, about article, audiences, payment descriptor, and home relay.

## Rollout Sequence

1. Add schema fields and DTO/parser tests.
2. Backfill owner pubkeys from existing coordinates.
3. Add AppData parsing and owner-signed setup.
4. Add owner admin access shell.
5. Add RSS/sitemap/robots routes.
6. Add footer context and template updates.
7. Add audience and publication payment descriptor management.
8. Add analytics and content-management workflows.

## Verification Commands

Preferred commands, run inside the Docker container when the `php` service is running:

```bash
docker compose exec php bin/phpunit --testsuite UnfoldBundle
docker compose exec php bin/phpunit --testsuite Unit --filter 'AppData|Audience|PaymentDescriptor'
docker compose exec php bin/console lint:twig templates
```

For docs-only edits in this spec folder, tests are optional, but markdown should be kept linkable and readable.