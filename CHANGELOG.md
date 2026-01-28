# CHANGELOG

## v0.0.8
Toast on toast, and event in event.

- Show embeds of nostr events in articles.
- Broadcast option.
- Show a stack of toasts instead of replacing the previous one.
- Showing a placeholder when an article is not found.
- Better handle comments.
- Added generic 'alt' tags to index events.
- Admin dash update.
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
