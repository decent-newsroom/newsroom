---
name: migrate-event-surface
description: >-
  Migrate an existing page, embed, inventory, bookmark list, or event preview onto shared workspace preview components with parity-first characterization tests. Use when replacing bespoke event rendering in Reading Nook, My Content, embeds, or lists without UX regressions.
---

# Skill: Migrate a Surface to Shared Event Previews

Use this guide when moving a page, inventory, bookmark, embedded reference, or
event preview onto the shared workspace/event presentation components. Read
[the event workspace iteration plan](../../../documentation/Reader/event-workspace-iteration-plan.md)
and [the event workspace contract](../event-workspace-contract/SKILL.md) first.

This is a parity-first migration. Do not replace a working surface just because
a shared component exists; first characterize what users and other callers rely on.

## Migration procedure

1. **Map the surface.** Identify its route, controller/provider, data source,
   templates/components, Stimulus/Live Component behavior, authorization,
   translations, and callers. Check whether it redirects to another workflow.
2. **Capture current behavior.** Add or identify tests for routes, filtering,
   ordering, revision choice, missing-data states, query parameters, permissions,
   and actions before changing rendering.
3. **Separate source data from markup.** Preserve event ID/coordinate, author,
   kind, original tag order, relay/version hints, source context, and owner
   scope. Do not pass only a title, pre-rendered HTML, or normalized/deduplicated
   tag values if expansion will need the original references.
4. **Adapt to the shared contract.** Reuse source adapters, generic event
   presentation, reference resolution, and mode-aware preview components. Keep
   local application data such as subscriptions as virtual source nodes rather
   than pretending it is a signed event.
5. **Preserve surface-specific actions.** Reading Nook and My Content share
   presentation but not necessarily actions. Keep Open/Manage, Edit, coordinate
   copy, and signed deletion distinct from an expansion control. Do not make a
   disclosure button replace an existing action menu.
6. **Keep the old path available during rollout.** Use an existing feature flag
   or a narrowly scoped rollout gate when the parity risk justifies it. Avoid
   duplicating business rules behind a permanent parallel implementation.
7. **Compare old and new behavior.** Test representative known, unknown,
   incomplete, and restricted events. Check list order, display modes, missing
   references, owner boundaries, and browser behavior.
8. **Remove superseded code only after parity.** Search callers before deleting
   old templates/endpoints. Retain adapters for specialized routes where needed.
9. **Update documentation, translations, changelog, and indices** for shipped
   user-facing changes.

## Known migration hazards

- Reading Nook currently queries up to 400 collection events before
  deduplication/filtering, and its facet normalization is not a child-order API.
- Reading Nook excludes some kind `30040` events classified as magazines and
  does not currently include kind `3` follows or kind `30045` directories.
- My Content currently covers latest `30023`/`30024` Article projections;
  reading lists and magazines have separate routes, while its query parameters,
  editor links, coordinate-copy action, and signed deletion are established UX.
- Article projection, processed HTML, cached profiles, and graph indexes can be
  missing even when an Event exists.
- Graph child queries may omit unresolved targets. For previews, enumerate
  references first and resolve targets separately.
- A generic event page may redirect articles/publication indexes to focused
  readers. Preserve those routes while providing an explicit way to browse the
  event generically.
- Several embedded contexts nest components. Avoid duplicate DOM/frame IDs,
  nested anchors/forms, global visited-state that hides shared nodes, and
  recursive eager body conversion.

## Validation checklist

- [ ] Existing route, query parameters, filtering, and ordering still work.
- [ ] Existing actions, signatures, permission checks, and redirects are intact.
- [ ] Event previews work without generated HTML or a specialized projection.
- [ ] Missing and unresolved references remain visible, ordered, and expandable
  where applicable.
- [ ] Nested/shared/cyclic references have bounded behavior and unique DOM IDs.
- [ ] No network fetch or persistence occurs during ordinary component rendering.
- [ ] Test the narrowest relevant PHPUnit and JavaScript/browser suites.
- [ ] Run required build/compile checks in Docker.
