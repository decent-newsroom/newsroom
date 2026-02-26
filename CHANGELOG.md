# CHANGELOG

## v0.0.12
More metadata is better.  

- Removed the floating ReadingListQuickAdd widget (component, template, and CSS) — replaced by other functionality.
- Added support for extra metadata tags on articles: source references (`r` tags) and media attachments (`imeta` tags) in the article editor, event builder, and event parser.
- Display category/reading-list summaries on magazine front category headers, collections list cards, reading list pages, and Unfold category pages.
- Filter bot/RSS-type authors out of the Latest Articles feed (denylist + profile bot flag).
- Added prev/next article navigation cards at the bottom of article pages when the article belongs to a reading list or curation set.
- Added a floating "Back to top" button that appears when scrolling down on any page.


## v0.0.11
Mostly quality of life improvements.

- Removed reading lists from the editor sidebar to reduce clutter; sidebar now shows only drafts and articles.
- Added "My Content" page (`/my-content`) — a unified file-manager view for managing articles, drafts, and reading lists.
- Added "My Content" link to the left navigation under the Newsroom section.
- Split sidebar navigation into segmented sections (Discover, Newsroom, Create) with divider labels.
- Upgraded magazine wizard to a 4-step flow: Setup → Categories → Articles → Review & Sign.
- Added live cover preview panel to the magazine setup step.
- Added image upload support to the magazine setup and category steps.
- Added sortable (drag-to-reorder) categories in the wizard.
- Replaced raw naddr coordinate input with a dropdown of user's existing reading lists.
- Added login prompt and desktop device recommendation to the wizard.
- Added basic zap invoices to UnfoldBundle.
- Implemented AsciiDoc parser for kind 30041.
- Updated footer.
- Added nostrconnect uri to the signer flow, so you can log in on the same device.
- [Bug] Fix a bug in magazine wizard, so now you get form errors instead of a broken page.
- [Bug] Fixed Reading List edit loading bug, so now you can actually edit your reading lists.


## v0.0.10
Publications as first-class citizens.

- Introduced publications on subdomains MVP.
- [Bug] Fixed image upload in the editor.
- [Bug] Fixed routing for vanity names.


## v0.0.9
Starting to look like a real product, isn't it?

- Introduced Vanity Names (NIP-05).
- Introduced Active Indexing.
- Updated static pages to reflect changes.
- Updated relay communications.
- Added JSON-LD metadata to article and magazine pages.
- Added a "Support" card.
- [Bug] Fixed a host of bugs in the article and magazine publishing process.

## v0.0.8
Toast on toast, and event in event.

- Remember me.
- Favicon is now there.
- UnfoldBundle now loads a magazine on a configured subdomain.
- Show embeds of nostr events in articles.
- Broadcast option.
- Show a stack of toasts instead of replacing the previous one.
- Showing a placeholder when an article is not found.
- Better handle comments.
- Added generic 'alt' tags to index events.
- Admin dash update.
- Overhauled Caddy config. 
- [Bug] Comments never loaded... because configuration was a mess.
- [Bug] Button didn't open a dialog on login page.


## v0.0.7
Lists that actually list things. Revolutionary.

- Reading lists now load existing lists.
- Now possible to add articles to magazines and reading lists by naddr.
- [Bug] Refactoring metadata introduced a bug in profiles, showing name instead of display name.
- [Bug] Add-to-list button defaulted to extension, now honors login method.


## v0.0.6
Testing revealed some issues. What a shocker. 

- Non-blocking user profile data sync, typed metadata.
- Show/hide long highlights context.
- [Bug] Fixed scrolling in editor.
- [Bug] Fix signer flow in magazine setup.
- [Bug] Fixed squished tabs on mobile.
- [Bug] Fixed reading list wizard buttons and general publishing flow.


## v0.0.5
Navigating to nostr ids made easier.

- Extended search to nostr idents, so you can paste a nostr npub, note, nevent or naddr to navigate to that profile, event or article.
- Updated profiles, implemented background fetch.
- Made publishing magazines available.
- Added zap buttons to articles.
- Brought back zaps as comments.
- Made multimedia more resilient.


## v0.0.4
Deployment used to be a remake of Minesweeper. Now it's more like Darts.

- Updated deployment and build, added documentation.
- [Bug] Fixed Elasticsearch feature flag.
- [Bug] Fixed article title sync in editor.


## v0.0.3
We know you have better things to do than waiting around for the page to load.

- Refactored article editor.
- Removed deprecated Nzine implementation.
- Added a user profile index to Elasticsearch.
- You can now include a cover image in reading lists.
- Added a feature flag for Elasticsearch integration.
- Implemented a new caching object to speed up page loads.
- Extended article entity with parsed HTML content.
- Added a worker for ingesting articles from the local relay.
- [Bug] Fixed share links
- [Bug] Fixed bunker signer

## v0.0.2 
Let's pretend we finally know what we are doing here.

- Initial changelog created.
- Local relay.

## v0.0.1
We won't go into detail here. Most of it was just learning the ropes.

- Initial development setup with lots of wrong turns.
