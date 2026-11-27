# Graph product, interface, and positioning plan

Status: **Design proposal, 15 November 2026. No application behavior is changed by this document.**

Commercial supersession: [local subscriptions](subscriptions.md) are now free for
all supported sources. Updates Pro is retired, with historical payments and
receipt settlement preserved. All proposals below to expose, price, promote, or
gate subscriptions through Updates Pro are superseded, including the
five-source free limit and paid-only collection access. The remaining paid
services are separate products.

Implementation update, 16 November 2026: the [unified discovery and author
streams](home-feed-logged-in.md) apply the shared navigation/preview styling and
editorial direction. The requested single home and mixed profile streams
supersede the separate public-landing and retained-profile-tab suggestions below.
Other proposed phases, including split-pane selection and commercial
reconciliation, remain proposals rather than claimed delivered capabilities.

Scheduling constraint: **Restyling begins only after the refactor is complete.** This document records a future design direction. Reassess the finished architecture and supported journeys before implementing navigation, layout, component styling, or promotional copy. Current gaps and file references are a snapshot of work in progress, not constraints to preserve in the finished design.

This is the product and visual-design companion to the [event workspace iteration plan](event-workspace-iteration-plan.md), not a replacement implementation roadmap. That plan remains authoritative about delivered capabilities and access boundaries. The [editorial design system](../Newsroom/editorial-design-system.md) remains the visual foundation.

## Recommendation

Make Decent Newsroom a place to **read, collect, and publish connected work on Nostr**. Readers should be able to follow an article into its sources, a collection into its contents, and a reference into a profile without losing their place.

The graph should shape navigation, context, and reusable previews. A node-link diagram can eventually be an optional view, but it should not be the default interface. The main experience should remain comfortable for someone who came to read an essay or assemble a publication.

Keep the editorial character: warm paper and ink, the existing green accent, serif reading typography, square edges, no gradients, no shadows. The largest opportunity is a coherent interaction model and stronger information hierarchy, rather than another palette or a wholesale frontend rewrite.

Primary audience hypothesis: curious readers and curators of substantial Nostr content. Writers and publication editors are the second journey, using the same connected material. Validate this emphasis with users before investing in a broad marketing relaunch; the existing publishing tools remain an important differentiator.

## Review basis and limits

Reviewed the current repository's graph documentation and workspace plan; presentation/reference contracts; Reading Nook and My Content templates; shared shell, navigation, design tokens, and previews; generic event/profile/search pages; English product translations; README; and publishing/hosting documentation. The paid-feature review additionally covers pricing and subscription templates, settings, Updates Pro access/tier services, vanity payment types, publication hosting terms, and Essayist membership/expiry behavior and copy.

Also visually inspected the public homepage and Discover page at [decentnewsroom.com](https://decentnewsroom.com/) on 15 November 2026. The public footer reported v0.0.54 Preprint; the workspace changelog begins at v0.1.0. Live-site observations illustrate the public product story and existing styling, not deployment of this branch's refactor. Discovery content did finish loading; an initial spinner is not evidence of a persistent failure.

Authenticated pages were assessed from source, not through a signed-in browser. Docker access was unavailable in this session, so no application runtime, worker, asset compilation, or automated test result is claimed. This is a planning review, not a completed accessibility or performance audit.

### What is already useful

- `EventPresentationFactory` builds metadata and ordered reference snapshots independently of body conversion. It already includes an `alt` fallback; this is a consistency task across surfaces, not a wholly missing capability.
- Reading Nook has bounded collection disclosures; My Content has owner-only article/draft previews. Shared lazy disclosure and account-scoped tab-local UI state already exist.
- Graph indexes store supported `a`, exact lowercase `e`, and lowercase `p` occurrences. Current graph traversal primarily follows structural `a` edges; storing a profile reference does not yet provide a general profile browser.
- The shared shell, semantic design tokens, flat story rows, and typography give the redesign an existing implementation path.

### Where the experience diverges

| Evidence | User consequence | Proposed change |
|---|---|---|
| `NavigationBuilderTrait` replaces the global sidebar with different Reading Nook and Newsroom navigation. | Switching tasks also changes the apparent map of the product. | Keep Discover, Reading Nook, and Newsroom visible as stable destinations; place existing workspace-specific links underneath. Preserve their routes and actions. |
| Reading Nook repeats section navigation/counts in tabs and the right aside. | Space that could explain an item instead repeats the inventory. | Use one primary section navigator and a compact result count; reserve contextual space for the selected item. |
| Inventory action menus use a plus icon; expansion uses separate disclosures. | Plus can suggest adding or expanding rather than opening actions. | Chevron with an explicit contents label for disclosure; ellipsis labeled “More actions” for the action menu. |
| `EventPreview.html.twig` leads with “Untitled event”, kind, full event ID, and pubkey. No `.event-preview` rules were found in the reviewed style files. | The reusable core looks like a diagnostic fragment rather than a finished reading component. | Give the shared preview a visual specification and dedicated asset stylesheet; put identity details in a disclosure. |
| The ordinary layout promotes magazine creation in its context rail; authenticated Home places an Essayist promo above the feed. | Reading context competes with generic promotion. | Make context about the selected work. Put relevant, dismissible service promotion at intentional entry points. |
| The event page still parses and dispatches kinds/tags in Twig; `_event_meta` deduplicates reference output and labels generic `p` tags as mentions. | Similar objects have different previews and relationship meanings across pages. | Migrate this surface using the shared contract and parity checks; generic labels must remain conservative when the source kind does not establish a role. |
| Homepage, About, README, and metadata describe different products. | New visitors cannot tell whether this is one magazine, a publishing tool, or a broader Nostr reader. | Use one product promise, followed by clear reading, curation, and publishing journeys. |

## Product structure and vocabulary

Use three stable destinations. Retain current URLs and avoid a separate third authenticated dashboard that duplicates the two workspaces.

| Destination | Purpose | Contents |
|---|---|---|
| **Discover** | Find a starting point. | Current article discovery, highlights, writers, Newsstand, Bookshelf, and topics. Start with supported search/content sources. |
| **Reading Nook** | Return to and explore what you collect. | Existing bookmarks, topic lists, reading lists, follow packs, update subscriptions, and the existing saved-books directory disclosure. |
| **Newsroom** | Make and manage your work. | My Content, drafts, publications, media, and existing creation flows. |

“Reading Nook” is worth retaining for character. Add the descriptive subtitle “Your saved collections and subscriptions.” Use “My Content” for the authored inventory inside Newsroom. Rename “Follow packs” to “People collections” only if user research warrants it; initially explain the existing term as “Follow packs · collections of people” rather than creating two labels for the same feature.

Distinguish publication format from data structure: “Publication” is the broad product term; magazine and book are reading experiences, while a collection is a grouping. Keep “article”, “chapter”, “profile”, and “highlight” wherever those are the actual objects. Technical “event”, kind numbers, coordinates, and tag names belong in Details or developer-facing views.

Update subscriptions are local application records and must have an appropriate source label. Do not fabricate signed events for virtual sections, subscriptions, or navigation roots. Do not imply that a listed item is owned by the viewer merely because it is referenced by their collection.

## The core reading and exploration interaction

### Desktop

Use three functional regions at roomy desktop widths:

1. A stable navigation column, approximately 200–220px, with workspace-specific roots below the global destinations.
2. The current collection or inventory outline, approximately 300–380px when paired with a preview. Rows show title, meaningful type, compact byline/status, and a clear expander where supported.
3. A flexible selected-item pane. It contains the preview or reader and its Read, Connections, and Details sections. It replaces generic contextual promotions rather than becoming a fourth permanent column.

Use a wider single inventory when no item is selected; let people open a dedicated focused reader when they want long reading. A split-pane excerpt need not be the full article. Keep existing article/book/publication reading routes and links.

Rows have three distinct actions: the chevron expands supported children, “Preview” selects the item, and the title/Open action follows the existing destination. Keep existing title/editor link meanings during migration. Edit, Manage, signed deletion, and copy actions stay separate from selection and expansion.

The selected pane uses a compact type label, title, author, date, optional summary, readable body/preview, and contextual actions. Profile metadata can enrich an authorized key; a missing profile name leaves a shortened npub with a full copyable value. A display name alone is never proof of identity.

### Connections

Show relationships as labeled, navigable groups: Contents, References, People, Parent/Root, and Sources where supported by the kind and current contracts. These groups are projections of source occurrences, not a replacement ordered reference model. Preserve source order within groups, duplicate occurrences, and an inspectable complete source order in Details.

- Say “Member of this follow pack” for a kind-39089 `p` entry. Do not say the viewer follows that person.
- Say “People referenced” for an undifferentiated `p` relation. Use “mentions”, “recipients”, or other roles only where kind-aware interpretation establishes them.
- Keep an exact `e` link distinct from an address that resolves to the current locally available revision. Label pinned revisions explicitly; never silently substitute latest.
- Let a containing publication expose “Also appears in” when an authorized, bounded reverse query is available. Describe it as known local results, not a complete view of the Nostr network.
- References are not endorsements, ownership, verification of claims, or permission to access the target.

Author is part of the event envelope, topics come from tags, and profile metadata is independently updated data. The UI should explain these relationships consistently without pretending every one is an identical graph edge.

An optional local neighborhood map can be evaluated later: small scope, labeled edges, keyboard-equivalent list, explicit incomplete-data status. It must earn its place by helping users find something that the outline and Connections view cannot explain well.

### Mobile and narrow screens

Use a single pane: inventory → collection → preview/reader. Keep the current path as breadcrumbs or a compact Back label. Navigation moves into the existing drawer; the selected item's Connections and Details stay available in the page, not in a desktop rail that simply disappears at a breakpoint.

Back restores the branch, filters, selected occurrence, and position where possible. Browser Back/Forward must work as well as the in-page controls. A public share link identifies the event/reference; a personal traversal path is local UI context, not part of a public URL. Draft/private selection must not leak into public share links.

Do not squeeze multiple columns or deeply indented rows into a phone. After a few visible nesting levels, offer “Open this collection” as a new local browsing root; this changes the presentation path, not the signed structure.

### Loading, errors, and incomplete data

| Situation | Suggested visible copy | Action/boundary |
|---|---|---|
| Source reference exists; target has not been checked | “Linked item · preview not checked” | Preview checks only the authorized supported local scope. |
| Target is missing locally | “This item isn't available here yet.” | Show “Look on relays” only where explicit discovery is implemented and allowed. |
| Explicit fetch is in progress | “Checking relays…” | Keep the row in place; replace only the affected occurrence. |
| Fetch attempt ends without a readable result | “No readable result yet. Try again later.” | Apply actual server limits; do not imply the item does not exist anywhere. |
| Exact requested revision is missing | “This saved revision isn't available here.” | No automatic upgrade to latest and no unsupported fetch action. |
| Target is restricted or unavailable under policy | “Preview unavailable.” | No hidden title, author, body, counts, or revealing reason. |
| Reference is malformed | “This link couldn't be read.” | Raw source details only if the source is authorized. |
| Current path would cycle | “Already open in this path.” | Offer a jump to that occurrence without opening another branch. |
| Preview body fails conversion | “This preview couldn't be formatted.” | Keep authorized identity, metadata, and connections useful; safe text fallback when applicable. |
| Sensitive content | “Sensitive content” | Follow the existing reveal policy; hide metadata where required. |

Use generic loading copy before a request and state-specific copy only after an authoritative result. Restricted/deleted/suppressed states must not become existence or identity leaks through counts, placeholders, titles, or notifications.

## Visual direction

Evolve the current editorial workbench. The public homepage already has a strong typographic identity; the workspace needs the same care at smaller scales.

- Keep the canonical light/dark/space token system, square geometry, and no elevation. Use rules and space for structure. A selected row gets an accent rule and a clear text cue, not an extra decorative card.
- Keep Newsreader for the wordmark and EB Garamond for reading and major editorial headings. Use the system sans-serif for dense controls, row metadata, tabs, statuses, and inspector labels. Avoid blanket uppercase on long controls.
- Target a focused reading measure around 60–75 characters, with roughly 18–20px body text and comfortable line height. Verify against real article typography, math, tables, and chapter content rather than scaling the entire app.
- Define row, compact preview, and detail variants of the shared presentation vocabulary. Keep title/type/byline/actions in predictable places. Specialized media and publication renderers compose that vocabulary.
- Use thumbnails selectively, never as a prerequisite for a useful row. A text-only item should look intentional. Implement low-data behavior separately before offering the setting: hide list covers and make external media explicitly loadable.
- Avoid a colored badge for every kind or relationship. Use compact human labels and a consistent icon vocabulary; reserve semantic colors for interaction/status with a text equivalent.
- Reduce permanent dashboard-like totals and repeated navigation. Show the result count near the list; show item-specific context near the item. Until the inventory cap is fixed, totals must be qualified as the loaded/local subset.
- Give loading rows approximately their eventual geometry to reduce movement. Async replacement must preserve focus and expanded siblings.
- Verify contrast in every theme. Some existing muted text is deliberately subtle; measure it on its actual background before reusing it for essential controls.

Use native disclosure semantics where they fit. Enter/Space should operate disclosure controls and expanded state must be exposed, as described by the [WAI disclosure pattern](https://www.w3.org/WAI/ARIA/apg/patterns/disclosure/). Do not add `role="tree"` unless implementing the full keyboard interaction expected of a tree. A nested list with native disclosures is a better initial implementation.

## Page-specific changes

| Surface | Proposed change | When |
|---|---|---|
| Public homepage | Lead with the reader benefit; show an example collection → item → references journey. Keep publishing and Unfold as clear secondary paths. Replace the current Unfold device image as the primary product demonstration once the workspace is ready. | After refactor completion and review of the released capabilities. |
| Discover / authenticated Home | Share the same object vocabulary and preview behavior. Keep current content discovery readable; show a clear page title, meaningful empty/loading states, and contextual reasons such as a supported source collection. | After the first workspace slice. |
| Reading Nook | Consolidate duplicated section controls; improve actions; selected-item pane; retain existing filters and supported expansions. | First implementation surface. |
| My Content | Reuse presentation/navigation, with owner actions and editing status. Clarify that the current inventory covers articles/drafts; widen the claim only with Event-only inventory support. | Second surface. |
| Event and article pages | Keep focused reading and existing specialist behavior; add an explicit “Explore connections” entry. Move protocol detail out of the primary reading hierarchy. Preserve math/media/comment behavior. | One caller at a time, using migration parity. |
| Profiles | Retain Articles/Media/Highlights and owner-only Drafts. Later add supported public collections as another entry into the same explorer. | Requires explicit source/policy support. |
| Search | Clearly separate text search from opening a pasted Nostr reference. Keep the prompt article-specific until more source types can actually be searched. | Small clarity pass first; broader search later. |
| Publication / Bookshelf | Keep established reading routes. Later expose compatible Generic/Book/Magazine viewers over the same identity and ordered contents. | Existing roadmap's viewer stage. |
| Footer / services | Group publishing/hosting, support/project, and developer links. Explain Essayist and Unfold in context rather than requiring new visitors to understand every brand. | Copy/navigation pass. |
| Pricing | Present the free core, optional paid services, and Essayist's distinct contribution model with clear beneficiaries, terms, and feature boundaries. Include Updates Pro. | After the post-refactor offering/price reconciliation. |
| Settings / services | Provide one account overview of purchased services and memberships, with contextual links back to the profile, publication, or tracked sources. | As part of product-wide convergence. |

Keep inventory layout, selected-event viewer, and contents display as separate choices. A future List/Cards switch arranges the inventory; Generic/Book/Magazine changes the reader; Titles/Cards/Full contents changes children. Do not ship a toolbar offering all combinations before each one works.

## Paid features and their place in the product

The paid features belong in the product strategy, navigation, graph access model, and copy from the outset of the **post-refactor design review**. They should have clear value and intentional entry points. Reducing generic promotion in the reader must not make the services undiscoverable.

Recommended commercial structure: a useful free reading/curation/publishing core, optional services for ongoing tracking, identity, and hosted presentation, plus a distinct contribution-based Essayist membership. These are different purchases with different beneficiaries and access effects. Do not imply that buying one currently activates the others, or invent a single premium entitlement through visual packaging.

This is a positioning recommendation, not a decision to change prices, bundle products, retire an offering, or introduce new charges. Reconfirm the surviving products and actual terms after the refactor. There is no revenue or conversion evidence in this review from which to rank their present commercial performance.

### Offerings, value, and placement

| Offering | Place in the emerging product | Recommended interface placement | Scope and boundaries |
|---|---|---|---|
| **Free Newsroom** | The entry point for public reading, saving, curation, and existing article/publication creation. A credible core experience demonstrates the product's value. | Main homepage, Discover, Reading Nook, and Newsroom. Pricing explicitly states what is included. | Say “public articles and publications”, not “all articles”. Member-only material has separate entitlement. Ordinary collection browsing and a paid ongoing update subscription are different actions. |
| **Unfold hosting** | The clearest publisher-facing service proposition: turn a publication's event structure into a managed web presence. This connects the graph direction directly to something a publisher can buy. | Newsroom → publication → Website/Hosting; magazine setup at the point of choosing a web address; public pricing and a dedicated Unfold explanation. | Describe the supported hosted Newsroom subdomain and managed service. A hosting purchase does not create copyright/ownership of referenced work or sell access to the whole graph. Do not advertise arbitrary custom domains or audience paywalls without implemented support. |
| **Vanity name / NIP-05 address** | An optional identity convenience for readers and creators: a recognizable address and readable profile link backed by the same public key. | Profile settings and sharing setup; pricing; account service management. | It is a name-to-key mapping, not verification of a person's expertise, legal identity, or trustworthiness. The canonical pubkey remains the graph identity. Explain what happens to hosted aliases if a service ends. |
| **Updates Pro** | The recurring reader/curator service: keep track of more sources and supported collections without repeatedly checking them manually. Its natural home is Reading Nook, not the publishing tier. | Reading Nook → tracked sources / update settings; a contextual upgrade when a supported paid source type or free source limit is reached; pricing and account service management. | The current access service allows five free author/publication sources and reserves NIP-51-set subscriptions for Pro. Paying for monitoring does not buy access to restricted target content or permit unbounded recursive graph crawling. |
| **Essayist** | A distinct membership reading/publishing space built around contributions to existing members. It can enrich Newsroom's reading experience while retaining a clear membership identity. | An explained public membership entry; a persistent Essayist destination/scope for members; membership status in account settings; relevant member-content entry points. Reuse shared reading/previews where policy permits. | Contributions to members are not automatically Newsroom operating revenue. Relay authentication, local article entitlement, and preview access remain distinct. Membership is not a certification of editorial quality or confidentiality of every referenced event. |
| **Tips / zaps** | Voluntary support for an author or the project, depending on the stated recipient. | Author/reading actions and an intentional “Support Newsroom” entry. | Label the recipient. A tip grants no product entitlement unless an explicit, verified mechanism such as Essayist membership says it does. Separate an ordinary zap from a membership-qualifying contribution. |

**Active Indexing is retired in the source tree.** Do not resurrect it as a graph-discovery premium or retain its former pricing promises. See the [retirement decision](../Newsroom/active-indexing-removal-plan.md). Generic graph exploration should not become a renamed version of that product by accident.

### What payments fund

Explain the exchange concretely. Hosting, vanity addresses, and Updates Pro are operator-provided services. Essayist contributions flow to qualifying existing members under the membership mechanism; project donations are voluntary support. Keep these separate on pricing, payment confirmation, and account history.

Do not present Essayist contribution volume as revenue available to run Newsroom. Any operator initialization fee must be separately verified, named, and accounted for before it is promoted. Likewise, do not claim that present low service prices cover costs: establish hosting/relay/worker/support costs and renewal behavior before making sustainability claims or revising prices.

Evaluate each offering against the task it solves and its cost to serve: hosted publications/domains, tracked sources and collection fan-out, identity administration, and membership/relay operations. Paid “unlimited subscriptions” in existing copy is not a technical license for unlimited descendants or network work. Keep bounded processing and document the actual product limits. If commercial limits change, make that a separate product decision with an explicit treatment of existing customers.

### Pricing and discoverability

Keep `/pricing` as the comprehensive public entry point, organized around **Read and collect**, **Publish and host**, **Your identity**, and **Essayist membership**. The free core should clearly include writing/curation where supported; the existing “Reader” label undersells that. Use “Free Newsroom” or an equally broad name.

Show each surviving paid offer's outcome, price, payment period, recipient/model, and CTA. Use service rows or flat panels with clear hierarchy rather than a fabricated Free/Pro/Enterprise ladder. Show Updates Pro alongside the currently visible vanity/hosting offers. Explain Essayist separately enough that people understand that it supports members rather than purchasing a generic Newsroom upgrade.

Use one homepage section such as **“Make more of your Newsroom”** to introduce the paid paths, after demonstrating the core product. Suggested summaries:

- **Unfold:** “Give your publication a home on the web.” CTA: “Explore hosting”.
- **Vanity name:** “A readable Nostr address and profile link.” CTA: “Choose a name”.
- **Updates Pro:** “Keep up with more of what matters.” Supporting copy must identify supported sources/collections and the applicable limits. CTA: “Compare update options”.
- **Essayist:** “Support a member. Join the reading community.” Supporting copy explains qualification, amount, and access dates. CTA: “How membership works”.

Contextual prompts should explain the immediate benefit and offer a route back to the task. Existing customers see Manage/Open rather than another acquisition CTA. A member should find Essayist from normal navigation without searching for its former promotional banner. Non-members should be able to discover its purpose without a recurring full-width interruption above every feed.

### Account management and payment journeys

Create a **Services & memberships** area in Settings that covers all four paid offerings and links to their existing management flows. This is a shared overview, not a prerequisite for merging their ledgers or access rules. Keep ordinary update-source subscriptions under **Tracked sources** or **Update subscriptions** so they are not confused with payment plans.

For each service, show the actual owner/publication/profile, status, price/term, expiry or one-time basis, and available action. Distinguish pending invoice, confirmed payment, activation, active access, grace where supported, expiry, and a failed/unconfirmed result. An opened wallet or a browser-side success message is not activation. Show a clear recovery/check-payment route when confirmation is delayed.

Before payment, summarize exactly what is being bought, the recipient or membership beneficiary, sats amount, access period, and supported renewal mechanism. Prefer exact “active through” dates over ambiguous monthly language. Do not imply automatic wallet debits when the implemented flow requires a new Lightning invoice. “Cancel pending invoice” must not be mislabeled as ending/refunding an active service. Specify service-specific consequences of expiry; do not invent a common grace period or automatic deletion behavior.

Keep the ordinary task resumable after payment: publication → hosting setup; profile → selected name; tracked-source limit → source management; Essayist → member reading space. Do not silently subscribe, republish, change event tags, or retry a charge when restoring that context. Those actions need their own supported user flow.

### Paid access in a connected graph

Payment state is viewer/service policy, not proof supplied by a tag or a link. Reusing the graph and generic preview must preserve that boundary:

- A public collection referencing member-only work does not grant target access. Apply entitlement policy to fragments, titles, bodies, thumbnails, relationships, counts, search, and caches before presentation.
- A safe source reference can remain visible according to the source's policy. Do not turn withheld target metadata into an unsolicited premium teaser. Teasers require a separately authorized product policy.
- Pro update matching may discover that a permitted source changed; it must not expose a restricted target or defeat a mute/suppression rule.
- A member's authenticated view must not remain visible through shared caches or restored UI state after account change, sign-out, or membership expiry.
- Hosting renders a permitted publication graph; the subscription does not transfer ownership of its children. Vanity aliases remain presentation/resolution conveniences over canonical keys.
- Essayist's local exclusive-serving flag and membership relay authentication are distinct controls. Describe their actual limits; neither implies encryption or deletion of copies available elsewhere.

### Current commercial inconsistencies to resolve after the refactor

These are review findings, not new price decisions or checkout verification. The [public pricing page](https://decentnewsroom.com/pricing), reviewed on 15 November 2026, lists vanity names at 1,000 sats lifetime and Unfold hosting at 5,000 sats/year. Those amounts agree with the reviewed one-time enum price and hosting entity constant. The conflicting business documentation, magazine setup references, and translated Unfold promotion have since been aligned to these displayed prices. No purchase was made, and the public Essayist page could not be retrieved by the web tool in this review.

| Finding | Reconciliation required before redesign/relaunch |
|---|---|
| Resolved: `Business/footer-and-pricing.md`, `vanity-names.md`, publication-subdomain docs, magazine setup references, and translated Unfold promotion contained stale prices. | Current customer-facing references now match the pricing page: 1,000 sats lifetime for a vanity name and 5,000 sats/year for Unfold hosting. |
| `VanityNamePaymentType` retains `SUBSCRIPTION`, but `getPriceInSats()` has no matching subscription arm; the controller offers one-time purchase but still has a subscription fallback when a type is omitted. | Decide and verify legacy/new-purchase behavior before presenting subscription choices. This is functional reconciliation, not a styling fix. |
| Updates Pro has a surviving controller, access service, and tiers but is absent from the main pricing template and the reviewed Settings subscription overview. The enum lists 100 sats/30 days and 300 sats/365 days. | Confirm it is offered in the completed product, expose it consistently, and verify grace/renewal/limits. Do not confuse its surviving payment processing with retired Active Indexing. |
| The Updates Pro page promises a “Priority matching pipeline”. | Retain this claim only if a distinct priority behavior is demonstrated; otherwise describe the verified source-count and collection-subscription benefits. |
| Essayist landing copy says 2,000 sats and “one month”; the configured minimum and membership service show 1,000 sats and access through the end of the month following payment. It also contains initialization-fee and old June launch/early-bird language. | Generate amount and coverage dates from the same authoritative policy as the actual membership flow. Verify whether an initialization fee is collected and how legacy early-bird grants work; do not solve those policy questions by merely removing an old banner. |
| Pricing says “Read all articles and magazines” and advertises NIP-05 “verified identity” / “verified badge”. | Say “Read public articles and publications”; describe a domain-backed address/name mapping without promising personal verification or uniform display in every client. |
| Old `pricing.publisher*` / `pricing.curator*` strings advertise indexing and featured placement; they are present in translations but not the reviewed current pricing template. | Audit usages and remove or rewrite obsolete promises during the copy pass. Do not expose those legacy tiers as if they were current products. |

The intended implementation outcome is a single authoritative offer definition for amounts, durations, limits, and labels where practical, consumed by pricing, invoices, settings, and translated text. Record deployment-specific membership settings explicitly. Keep differing offer/payment models intact rather than forcing one generic subscription shape.

### Paid-feature acceptance and decisions

Before restyling, agree which offers survive, their audience and benefit, current/legacy prices and terms, what the payment funds, and the experience after expiry. Decide whether future bundling would help only after mapping these independent entitlements; no bundle is assumed by this plan. Publicly explain which features support Newsroom operations and which support individual creators.

During implementation, verify the four purchase/membership journeys and management states with controlled local fixtures. Cover an unpaid user, active service holder, pending confirmation, failed confirmation, expired service, supported grace state, renewal, and one-time/legacy grants as applicable. Include a non-member following a public reference into restricted content, and sign-out/expiry during an open preview. These checks supplement the core graph journey; they are not fulfilled by a pricing-page screenshot.

Measure whether readers and publishers can explain what they get, who receives the money, and how long access lasts; whether existing customers can find Manage; and whether a relevant offer is discoverable at the appropriate task. Use aggregate service-cost and purchase/renewal evidence when assessing viability. Do not require invasive per-user graph tracking to evaluate monetization.

## Copy audit and replacements

These are proposed English strings, not edits to runtime translations. Apply approved language consistently across all six locales, metadata, README, onboarding, and service pages during implementation.

| Current wording / source | Misalignment | Proposed wording or treatment |
|---|---|---|
| `brand.tagline`: “Collaborative publishing on Nostr” | Omits reading and collecting; collaboration sounds like the entire product. | “Read, collect, and publish on Nostr.” |
| `brand.description`, homepage title/OG/JSON-LD | Generic platform description; hardcoded variants can diverge from translation keys. | “A home for reading, collections, and publishing on Nostr.” Use one translated description consistently. |
| `home.hero.lede`: “Explore, publish articles, and create magazines.” | A feature list without a reason to care. | “Discover writing worth your time. Collect what matters, and publish your own.” |
| `home.explore.description`: specialized long-form search | Accurate for current search, narrower than the proposed whole product. | Now: “Find long-form writing on Nostr.” After broader discovery ships: “Explore writing, collections, and the people behind them.” |
| `about.answer`: “a curated, decentralized magazine” | Describes one editorial publication rather than the tool and connected ecosystem. | “Decent Newsroom brings reading, curation, and publishing together on Nostr. Discover long-form writing, organize collections, and create publications from work across the network.” |
| `about.intro` / `whyText`: misinformation crisis and rebuilding trust | Overstates what a client or signed graph can establish about quality/truth. | Lead with the practical problem: “Good writing is easy to lose in a stream. Decent Newsroom gives readers and publishers a way to organize it and keep its context close.” Keep editorial values as values, not verified product guarantees. |
| README: newspaper lookalike and separate marketplace | Anchors the architecture to an older product, and presents planned work alongside implemented capabilities. | Describe the current reader/curation/publishing product first. Put marketplace plans in an explicitly future section or roadmap. |
| `home.newsroom.description`: manage submissions/collaborate | Can imply a complete editorial assignment and permissions workflow. | “Build a publication from your writing and work by other Nostr authors. Organize it into sections and share it with readers.” Document actual team workflows separately. |
| `home.unfold.description`: “Any magazine… deployed to a custom domain” | Broader than the documented hosted-subdomain purchase flow. | “Give your publication its own website with Unfold. Hosted Newsroom subdomains are available as a paid service.” Describe custom-domain deployment only where supported and documented. |
| `reading_nook.subtitle` | “Your own collections” does not distinguish authoring from saving. | “Your saved collections and subscriptions.” |
| `my_content.description`: “organize your work” | Sounds wider than the article/draft inventory actually supplied. | Now: “Write, revise, and manage your articles and drafts.” Broaden when other authored event types are included. |
| `event_workspace.preview.untitled` / target fallback | “Untitled event” becomes the dominant content. | Use an accurate type label plus a safe excerpt/summary/alt fallback; “Nostr item” when no meaningful type is known. Keep kind/ID in Details. |
| `reading_nook.members.disclosure`: “(npubs)” | Implementation terminology in the main interaction. | “People in %title%”. Preserve full npubs in Details/copy actions and explain unavailable profile metadata. |
| `my_content.preview.label`: “Event preview and references” | Describes internals more than the task. | “Preview and connections”. |
| `my_content.action.copy_coordinate` | Appropriate for advanced users, opaque as a primary action. | Offer “Copy link” as the ordinary supported share action; retain “Copy Nostr address” under Details when accurate. Keep exact-event and current-address links distinct. |
| “Discover public reference”, “Available local index”, “Unresolved reference” | Mechanism-centered status labels. | Use the state table above; offer relay lookup only where the shipped discovery endpoint supports it. |
| Header `nav.article`; global `nav.newReadingList` route | “Article” does not clearly name a creation action; “New Reading List” points at an index in global navigation. | “Write” or “New article”; label the index “Reading lists”, or point a creation label at its actual creation flow. |
| `my_content.delete` confirmation | Already explains signed deletion and relay retention accurately. | Preserve its substance and signer/publication states; make menu wording consistently “Request deletion” if changing the visible action. |

Also audit `templates/base.html.twig`, the web manifest, public page metadata, social previews, search descriptions, and screenshots/alt text when applying a new product description. A new headline alone will not fix the mixed story.

### Recommended promo

**Brand:** Decent Newsroom

**Headline:** Follow good ideas further.

**Launch-safe subheading:** Discover long-form writing on Nostr. Save what matters, organize collections, and publish your own work.

**Primary action:** Explore writing

**Secondary action:** Start a publication

**Returning-user action:** Open Reading Nook

**After the connected-workspace release passes acceptance:** Follow articles into their references, collections into their contents, and ideas back to the people behind them. Keep what matters, and publish something of your own.

**Short description:** Decent Newsroom is a reading, curation, and publishing app for Nostr.

**Longer introduction:** Good writing has a context: the people behind it, the work it draws on, and the collections that bring it together. Decent Newsroom helps you discover long-form writing, collect what matters, and publish your own articles and publications on Nostr. As the connected workspace develops, you can explore more of those relationships without losing your place.

**Commercial supporting line, after confirming the released offers:** Start with free reading, collecting, and publishing. Add a readable address, more ways to keep up, or a hosted publication when you need them. Discover Essayist to support members and join its reading community. Place this below the core promise, with the distinct services and membership terms explained in their own section.

Avoid “browse all of Nostr”, “every connection”, automatic trust/quality claims, guaranteed availability, complete collaboration workflows, and a universal “nothing is locked in” promise. Portability of signed publication structure is valuable; hosting, local state, entitlements, and missing relay data have their own limits.

## Phased delivery and acceptance

All phases below follow refactor completion. They do not ask for a parallel restyling effort during the refactor. Any unfinished data, resolution, authorization, or viewer capabilities remain separate functional work rather than being silently included in a styling task.

### Phase 0 — Review the finished refactor and establish the baseline

First review the completed architecture, shared components, supported relationships, and real user journeys. Update this proposal's capability assumptions and implementation map against that finished state. Reconcile surviving paid offers, authoritative terms, legacy grants, and the commercial inconsistencies above. Then settle the shared vocabulary and release-appropriate copy, and assemble reusable real/seeded design fixtures. Record behavior rather than reopening completed roadmap milestones. Inspect authenticated desktop/mobile pages locally and measure request/query/queued-fetch behavior before setting latency targets.

Fixtures: a nested publication index, mixed bookmarks, a follow pack with missing profile metadata, a raw article without processed HTML, an unknown empty-body event with `alt`, a missing exact pin, repeated/shared children, a cycle, warning-gated content, a restricted item, and owner-only drafts. Use synthetic/local fixtures for research and performance work; avoid retaining private event contents in telemetry.

Acceptance: one agreed navigation model and relationship vocabulary; current-versus-proposed capability matrix; browser evidence for the existing authenticated journey; an agreed free/paid feature map with prices, beneficiaries, periods, management/expiry states, and entitlement boundaries verified against the finished implementation. No implementation estimate should assume that profile resolution, arbitrary drive mounting, or all-kind authorization is solved.

### Phase 1 — Prove the connected Reading Nook

Choose one representative end-to-end journey from the completed refactor. The owned kind-30040 TOC and available chapter previews are a candidate based on today's implementation, not a final restriction. Deliver stable global/workspace navigation, a compact collection outline, shared preview styling, selected-item context, accessible separate disclosure/actions, and state-aware loading. Add Read/Connections/Details only where each section has real authorized content. Keep references as references where resolution is unsupported.

Reuse `LazyDisclosure`, current reference/identity DTOs, the existing TOC discovery and preview endpoints, and account-scoped UI state. Extend the shared shell by route/surface configuration rather than changing every caller at once. Do not perform relay work on ordinary render or auto-open an entire graph.

Acceptance:

- Collection → nested index → available chapter preview → Back works without losing siblings, focus, filters, or position.
- Existing Open/Manage/editor links and signed deletion semantics remain intact.
- The available/missing/restricted/pinned/cyclic cases remain intelligible and do not disclose withheld metadata.
- The mobile single-pane flow is usable with touch and keyboard; no essential context vanishes with the desktop rail.
- Existing page limits and concurrency bounds remain enforced. No new bulk discovery or eager media requests occur.
- The rollback flag still disables expansion controls and related endpoints as documented.

This is the recommended first restyling task after the finished-refactor review. It proves the visual and interaction language before a full homepage redesign.

### Phase 2 — Converge the rest of the product

Apply the proven row/preview language to My Content, supported bookmarks/embeds, focused event pages, and Discover, one bounded caller per change. Preserve each caller's source and policy adapters. Recheck inventory limits and pagination against the finished refactor before presenting totals as complete; any remaining query or inventory defects are separate functional fixes.

Bring pricing, Services & memberships, and the four surviving paid-product journeys into the same design system. Add task-relevant service entry points and member navigation, then check purchase/renewal/expiry experiences and restricted graph references. Keep backend payment or entitlement corrections as explicitly scoped functional work.

Expose the completed refactor's supported connections on focused pages while retaining the focused reader. Style profile enrichment and reverse “appears in” results where those capabilities exist. Any additional queries need separate functional scope and authorization rules. Do not imply those queries are complete global knowledge.

Acceptance: the same permitted event has consistent identity, warning state, and preview hierarchy across migrated surfaces; converter failure remains separate from missing-event status; owner-only data stays private across fragments/cache/navigation/account changes.

### Phase 3 — Broaden expression, then relaunch the full promise

Style and expose the compatible viewers and independent contents display modes delivered by the refactor. Where supported, Book/Magazine/Generic should operate over the same source without tag mutation or republishing. Reassess broader event inventories, follows, directory/drive mounting, and media modes against the finished implementation. Capabilities still missing then belong in separate product work; the restyling does not depend on implementing every longer-term proposal. Drive mounting is deferred in the current architectural snapshot.

Redesign the public hero around the accepted connected-workspace journey, with a responsive real demonstration or clearly labeled illustration. Apply the expanded promo only to capabilities that pass acceptance. Evaluate a small graph map only if it improves a concrete exploration task.

Publish the reconciled free/paid value proposition and service-specific promo together with the pricing and account-management changes. Existing customers must receive accurate management/renewal information, not a new acquisition message that obscures their current access.

Acceptance: viewer/display changes retain identity, pin bindings, ordered missing/duplicate occurrences, and selected child context; full contents remains explicit/lazy/bounded. Public marketing accurately describes the released scope.

### Validation and measures

For implementation, run appropriate existing/focused suites in Docker, Twig/YAML lint, and AssetMapper compilation after JS/CSS changes. Add meaningful parity and semantic tests for changed behavior; use browser checks for focus, history, lazy fragment replacement, narrow-screen layout, and account transitions. Exercise actual worker/Redis completion and failure for the bounded discovery flow. A mockup is not runtime verification.

Test layouts around 360px, 768px, 1024px, and 1440px, at zoom, in all themes, with long translated labels and long identifiers. Verify keyboard-only operation, readable focus, target sizes, contrast, and non-color state cues. Privacy checks include response metadata/counts and share URLs, not just visible bodies.

Evaluate task success with a small set of readers, curators, and writers: find a collected item, explain why two items are connected, distinguish a pack member from a follow, return to the original collection, and recognize a missing revision. Track task completion and observed confusion. Measure query/request/fetch counts on fixed local fixtures; do not claim a speed improvement from shared components alone or introduce external user-level graph telemetry for the redesign.

## Implementation map

| Concern | Existing starting point |
|---|---|
| Shared shell / contextual region | `templates/app-shell.html.twig`, `assets/styles/02-layout/shell.css` |
| Navigation continuity | `src/Helper/NavigationBuilderTrait.php`, `templates/components/SidebarNav.html.twig`, workspace layouts |
| Presentation vocabulary | `src/Service/EventWorkspace/EventPresentationFactory.php`, `src/Dto/EventWorkspace/`, `templates/components/Molecules/EventPreview.html.twig` |
| Reference semantics / local graph | `src/Service/Graph/ReferenceParserService.php`, `GraphLookupService.php`, raw-tag fallback and existing identity/version services |
| Reading slice | `templates/reader/reading_nook/`, current TOC/discovery/chapter services and controller endpoints |
| Owner inventory | `templates/my_content/`, `MyContentController`, `MyContentPreviewController` |
| Progressive loading / state | `LazyDisclosure`, current workspace/discovery Stimulus controllers, account-scoped session storage |
| Focused reader / legacy parity | `templates/event/`, article/chapter/publication routes, `NostrEmbed`, converter/deferred preview callers |
| Appearance | Existing canonical tokens; new shared component CSS in `assets/styles/03-components/`, scoped page adjustments in `04-pages/` |
| Copy | All `translations/messages.{locale}.yaml`, homepage/static metadata, README, onboarding, manifest |
| Paid offerings and account overview | `templates/static/pricing.html.twig`, `templates/settings/tabs/_subscriptions.html.twig`, `templates/subscription/`, subscription controllers and service/enum terms |
| Paid update boundaries | `src/Service/Update/UpdateAccessService.php`, `UpdateProService.php`, `src/Enum/UpdateProTier.php` |
| Membership and member previews | `src/Service/Essayist/EssayistMembershipService.php`, current Essayist controllers/templates, Article entitlement policy and membership relay gateway |

Apply the relevant repository skills when implementing components, Stimulus behavior, translations, event-surface migrations, or viewers. Keep one changelog item per delivered feature/fix. This proposal itself does not warrant a feature release claim.
