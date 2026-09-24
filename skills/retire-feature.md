# Skill: Retire a feature

Use this guide when removing a feature, subscription product, worker, or scheduled job. Identify the behavior being retired and the behavior other features still depend on.

## Establish the decision

- Record the operator's reason, observed usage and contribution, ongoing cost, and accepted data loss in one feature document under documentation/.
- Check whether a metric actually measures the claimed outcome. For ingestion, distinguish jobs dispatched, events fetched, existing events seen again, and **new, unique articles persisted**.
- State what is known from production evidence and what is an operator observation.

## Trace ownership and dependencies

Search routes, controllers, services, entities, repositories, roles, commands, cron, Messenger messages, templates, assets, translations, configuration, tests, and documentation. Mark each match dedicated, shared, or historical.

Follow consumers before deleting a shared symbol. A command with an old feature name may now serve another product. Check payment handling, relay selection, and background jobs in particular. Historical migrations and changelog entries remain historical records.

## Plan the removal

- Specify the forward migration and the exact data or roles it removes. Preserve unrelated roles and subscription rows.
- Map each deleted job, route, and setting to the remaining path that satisfies its purpose, if any.
- Rename shared concepts when the retired feature's name obscures their current owner. Keep persisted values stable unless a migration deliberately changes them.
- Order configuration, code, migration, and cron changes so surviving products work through deployment.

## Implement and verify

Run project commands inside Docker. Add focused tests for shared consumers, migration behavior, and any replacement path. Verify routes, container configuration, schema, and assets where affected. Search for stale references, excluding historical records. Update the feature document, documentation index, and topmost changelog version for the removal.

For Newsroom Active Indexing, use [the removal plan](../documentation/Newsroom/active-indexing-removal-plan.md): its receipt command and status enum serve Updates Pro, and its Lightning recipient settings serve other subscriptions.

## Checklist

- [ ] Decision and evidence distinguish unique contribution from activity counts.
- [ ] Every reference is classified as dedicated, shared, or historical.
- [ ] Shared payment, relay, and ingestion paths still work.
- [ ] Forward migration removes only approved data and roles.
- [ ] Scheduled jobs, routes, UI, translations, docs, and tests are updated.
- [ ] Targeted verification passes inside Docker and the changelog has one item.
