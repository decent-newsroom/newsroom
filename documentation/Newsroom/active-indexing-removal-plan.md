# Active Indexing removal plan

Status: implemented in the source tree. The forward migration passed a dry run and was applied to the local development database; production deployment must apply it.

## Decision

Retire Active Indexing completely. The hourly fetch adds recurring relay and worker work, while the operator has observed no distinct articles contributed by it. Existing subscriptions were admin granted, and their rows and `ROLE_ACTIVE_INDEXING` assignments may be deleted. Ordinary author fetching and the other subscription products must continue to work.

Jev (`jev-1.13.0`) evaluated an approved, short summary of these facts and assigned the three options: full removal `0.99`, suspension `0.01`, retention `0.00` (Choice confidence `0.98`). This supports the decision but is not independent evidence of production behavior; [Choice confidence describes the answer distribution](https://docs.typesafe.ai/primitives/choice), not correctness.

Approved state sent to Jev:

> Newsroom has an optional hourly author-indexing job. You report no demonstrated article contribution, accept deletion of its admin-granted subscriptions, and prefer lower resource use and simpler code. Other author-fetch paths exist; shared payment handling must stay, and the author-visit path needs a relay-selection correction.

The former `articles_indexed` counter cannot verify the reported lack of contribution. `active-indexing:fetch` calls `recordFetch()` without an article count, so that path does not increment the counter. The local development database has no Active Indexing subscription rows; that does not establish production counts.

## Implementation checklist (completed in source)

1. Remove the Active Indexing product: its public and admin controllers and routes, service, entity, repository, tier, role and user helper, fetch/lifecycle/activation/list commands, dedicated templates, UI entries, translation keys, and Active Indexing-only CSS. Remove its hourly fetch and lifecycle cron entries and dedicated cron scripts. Keep the shared subscription CSS, pricing toggle, and payment-status controller used by other products. Removed product URLs may return 404.
2. Add a **new forward migration** that removes only `ROLE_ACTIVE_INDEXING` from the JSON `app_user.roles` arrays and drops `active_indexing_subscription`. Preserve all other roles and leave historical migrations unchanged. No paid-customer transition is required because the subscriptions were admin granted.
3. Preserve Updates Pro payment processing. The former `active-indexing:check-receipts` command checked Updates Pro receipts; rename it to `updates-pro:check-receipts` and update cron and operational documentation. Rename the shared `ActiveIndexingStatus` enum to `UpdateProStatus`, updating Updates Pro's entity, repository, service, and receipt worker while keeping persisted status strings unchanged.
4. Replace Active Indexing-named Lightning recipient parameters with generic payment recipient parameters and environment variables. Wire all surviving payment consumers to them. Before deployment, carry over the existing effective recipient values so Updates Pro, Vanity Name, and publication-subdomain payments retain the same destination.
5. Correct the author-visit fetch path. `RevalidateProfileCacheHandler` previously passed `getRelaysForFetching()` into `FetchAuthorContentMessage`, overriding the handler's bounded author write-relay selection. Omit that relay override and the now-unused service dependency. `FetchAuthorContentHandler` now uses `getRelaysForAuthorContent()` while the current throttle, time window, content types, and ownership rules remain in place.
6. Remove the obsolete Active Indexing feature document and update the documentation index, cron, settings, pricing, and architecture references. Document the retained author-content fetch path in one feature document. Add one removal item to the topmost changelog version when implementing the change.

## Verification and rollout

- Test that profile revalidation dispatches without relay overrides and that the shared handler selects a bounded list led by the author's write relays. Test that Updates Pro receipts still activate subscriptions under the renamed command. Verify the migration removes the retired role while preserving other roles.
- Inside Docker, run the relevant PHPUnit tests, container and route checks, Doctrine schema validation, and asset compilation. Search for stale Active Indexing references; historical migrations and changelog entries may retain their history.
- Deploy the payment recipient configuration, code, migration, and cron changes together. Confirm the hourly Active Indexing jobs are gone, author-profile visits still fetch and persist articles, and surviving payment flows work.

## Deployment configuration

Surviving payment consumers use PAYMENT_RECIPIENT_PUBKEY and PAYMENT_RECIPIENT_LUD16, falling back to the former ACTIVE_INDEXING_RECIPIENT_PUBKEY and ACTIVE_INDEXING_RECIPIENT_LUD16 variables for this rollout. Before deployment, copy any production overrides to the generic variables and confirm Updates Pro, Vanity Name, and publication-subdomain invoices still use the intended destination. The legacy aliases can be removed after deployment configuration is confirmed. The local container has no override variables for either pair and uses the project contribution recipient.

The migration deletes the retired subscription rows and role assignments irreversibly. Apply it with the application release and confirm the retired hourly jobs are absent from the installed cron schedule.

## Assumptions

- All existing Active Indexing subscriptions were admin granted; deleting their data and role assignments is approved.
- No replacement paid indexing product or historical subscription archive is needed in this change.
- The operator's report of no contributed articles is an observation, not a conclusion drawn from the broken counter.
