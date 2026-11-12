---
name: add-event-viewer
description: >-
  Add a selectable Nostr event viewer or display mode to the filesystem-style event workspace, such as Magazine or Book for kind 30040, TOC as titles/cards/full content, or follow lists as npubs/profile cards. Use when adding view toggles, viewer registry entries, or layout modes (list, thumbnails, gallery).
---

# Skill: Add an Event Viewer or Display Mode

Use this guide when adding a specialized presentation or alternate layout for
events in the filesystem-style workspace. Read
[the workspace iteration plan](../../../documentation/Reader/event-workspace-iteration-plan.md)
and [the event workspace contract](../event-workspace-contract/SKILL.md) first.

## Keep the choices separate

Do not conflate:

1. **Browser layout** — for example, list, thumbnails, or gallery.
2. **Event viewer** — for example, Generic, Magazine, or Book.
3. **Child/member display** — for example, titles, cards, or full contents for
   an index; npubs or profile cards for a follow list.
4. **Component placement** — row, card, inline, detail, or nested branch.

All modes consume the same event identity, resolved source data, and ordered
relationships. A presentation choice must not introduce another event model or
lookup pipeline.

## Rules

- Generic browsing remains available for every readable event. Specialist
  registration is optional and must never gate generic display.
- Kind and declared type are capability/default hints, not one-to-one
  restrictions. Keep a safe fallback to Generic if a viewer is incompatible,
  fails, or lacks data.
- Missing or unresolved entries stay visible in every mode where they are
  relevant. Full contents can show a missing-status row; it must not silently
  omit that child.
- Changing views must not mutate/publish the signed event, tags, ordering,
  version binding, or underlying target identity.
- Viewers compose existing reusable previews and body renderers. They do not
  duplicate kind parsing, relay fetching, persistence, or access checks.
- Full contents and profile-card modes are explicit, lazy, paginated, and
  bounded by fetch concurrency and depth. Npub-only mode must render without
  kind `0` lookups; profile cards use cached/batched kind `0` metadata and keep
  npub fallbacks for missing profiles.
- Preserve local selection, expansion, scroll/reading position, and focus by
  stable reference/occurrence as much as possible when switching modes.
- Keep mode/viewer preference state separate from Nostr event state.

## Preference precedence

Resolve viewer and display-mode choices in this order:

1. Explicit, validated URL option.
2. Saved user preference for the current context.
3. Event-declared type or kind-specific default.
4. Generic viewer and its default mode.

An event-declared type is a suggestion, not a restriction. Public URL overrides
must be validated against available modes and must not expose private state.
Preferences and overrides never mutate the signed event.

## Implementation steps

1. Define the viewer's compatibility and mode contract. Include stable IDs,
   translated labels, supported layouts, and fallback behavior.
2. Implement presentation over the shared event and relationship models.
   Resolve only visible children and preserve tag order and missing references.
3. Add the control at the correct layer: viewer choice, browser layout, or
   child/member density. Avoid a combined setting that hides these distinctions.
4. Ensure nested viewers have independent, bounded state. Full contents must not
   recursively expand every nested event.
5. Add translations to all supported locales. Put CSS/JS in `assets/`, not
   inline in Twig. Use Twig components for reusable views; do not make every
   static row a Live Component merely because another part is interactive.
6. Add tests for mode compatibility, URL/preference/default precedence, fallback
   after viewer failure, missing data, order preservation, and no source mutation.
   Add browser tests for state preservation and accessible controls where the
   view is interactive.
7. Update the feature documentation, `documentation/INDEX.md`, and changelog
   for shipped user-facing behavior.

## Example acceptance cases

- One kind `30040` index can use Generic, Magazine, or Book viewers without
  changing its identity or ordered references.
- The same index TOC can switch between titles, cards, and full contents.
- A kind `3` list can switch between npubs and profile cards. Missing kind `0`
  metadata never removes a member; npub-only mode triggers no profile lookup.
- An unknown kind remains useful in Generic mode without any specialist.
- An invalid URL mode is rejected or safely replaced by the normal precedence;
  it cannot enable a restricted view.

## Completion checklist

- [ ] Viewer and browser-layout choices are separate from child/member density.
- [ ] Generic fallback remains usable without the specialist.
- [ ] All modes use shared event/reference data and retain unresolved entries.
- [ ] URL, saved preference, declared default, Generic precedence is tested.
- [ ] Switching modes does not mutate source events or lose occurrence identity.
- [ ] Loading, access control, localization, docs, and changelog are covered.
