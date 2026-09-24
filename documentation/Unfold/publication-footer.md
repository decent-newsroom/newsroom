# Unfold Publication Footer

Each hosted Unfold page renders separate publication and Decent Newsroom footer sections. Publication owners can edit up to five external links from either owner administration mount: `/admin/settings` on the hosted subdomain or `/mag/{mag}/admin/settings` on the main domain.

## Architecture

The root magazine coordinate remains the immutable publication identity. `PublicationSettings` owns the local theme and an ordered list of owner footer links; the root index continues to own title and category navigation. The host stores local settings in `unfold_publication_settings` through the bundle's `PublicationSettingsStoreInterface`. Existing rows receive an empty link list.

The settings form saves theme and links together under the existing owner access and coordinate-scoped CSRF checks. Blank rows are omitted. Labels are trimmed and limited to 80 characters. Links must be absolute HTTPS URLs of at most 2,048 bytes, without credentials or control characters. A save invalidates the publication's site configuration cache. Theme changes and hosting updates preserve existing links.

`SiteConfigLoader` applies local settings after its cached root-event read, so a settings change does not depend on a fresh relay fetch. `ContextBuilder` gives every home, category, and article template a `publication_footer` and `dn_footer` context. The default theme displays publication title, home/RSS/sitemap links, owner links, and the existing creator zap action in the publication section. The sitemap link uses the publication's `/sitemap.xml` route. The DN section uses the configured platform base URL for Unfold, About, and Terms.

## Key files

| File | Role |
| --- | --- |
| `packages/unfold-bundle/src/Config/PublicationSettings.php` | Validation and local presentation settings |
| `src/Entity/UnfoldPublicationSettings.php` | Host persistence model |
| `packages/unfold-bundle/src/Controller/Admin/PublicationAdminController.php` | Owner settings action on both mounts |
| `packages/unfold-bundle/src/Theme/ContextBuilder.php` | Separate publication and platform footer data |
| `packages/unfold-bundle/Resources/themes/default/partials/footer.hbs` | Public footer markup |

## Limits

An About link will be added when an About destination is implemented and selected. Audience and publication payment links wait for their event-backed workflows. The current creator zap action uses the creator's existing Lightning metadata; it does not represent publication payment-target settings.
