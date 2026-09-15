# Getting started

Decent Newsroom lets you read, write, and curate Nostr publications. You can read public content without signing in. Publishing and social actions use your Nostr identity.

## Sign in

Use the login page to connect a NIP-07 browser extension or a NIP-46 remote signer. Approve the login challenge in your signer. These methods let you use an existing Nostr identity without giving the application your private key.

Your `npub` is a public identifier; your `nsec` is a private key. Keep a backup of your signing credentials: the application cannot reset your private key. See [remote signing](../Nostr/nip46-remote-signing.md) for connection details.

## For readers

- Browse articles on the homepage, magazines at `/newsstand`, books at `/bookshelf`, and media at `/multimedia`.
- Explore subjects at `/topics`, or use search to find articles and authors. A Nostr address can also identify a specific event.
- Open an author's profile to follow them. Your follow list helps build your personal feed.
- Use article actions to bookmark an article or add it to a reading list. Manage lists at `/reading-list`.
- Select article text to create a highlight; browse recent annotations at `/highlights`. Comments appear below articles.

Signing and publishing an action sends a Nostr event to relays. Public follows, highlights, and comments are visible to other clients that support those event types.

Details: [bookmarks](../Reader/bookmarks.md), [highlights](../Reader/highlights.md), [comments](../Newsroom/comments.md), [reading lists](../Newsroom/reading-lists.md), and [search](../Newsroom/search.md).

## For writers

1. Sign in and open `/article-editor/create`.
2. Write using the editor's formatting tools and preview the result. Add images through the media panel or use image URLs.
3. Add a title, summary, tags, and optional cover image. The slug identifies this article across revisions.
4. Choose the publishing target and approve the signature in your signer.
5. Review the publishing result. A local save can succeed even when a relay rejects the event or times out.

Published articles use kind `30023`. Draft events use kind `30024`; saving a draft can publish it to relays, so a draft kind does not guarantee confidentiality. Manage articles and drafts at `/my-content`. Edit an existing article and publish again to issue a new version under the same author, kind, and slug.

The delete action on My Content signs a NIP-09 deletion request. It asks relays to remove the article; it cannot guarantee that all remote copies disappear.

Update your profile and relay preferences at `/settings`. A Lightning address on your profile enables readers to send tips. See [the editor](../Editor/editor.md), [translation helper](../Editor/translation-helper.md), [My Content and article deletion](../Newsroom/my-content-page.md).

## For publishers

Start at `/magazine/wizard/new`, or use `/blog/start` for the magazine introduction. Enter publication metadata, organize categories, select articles, and review the events before signing. After publishing, the wizard offers subdomain setup.

Magazines reference articles by Nostr coordinates, including other authors' articles. Updating a magazine changes its collection of references; it does not change the referenced articles' authorship.

Unfold renders publications on configured subdomains. Availability and subscription options depend on the instance. See [the magazine wizard](../Newsroom/magazine-wizard.md) and [Unfold](../Unfold/unfold.md).

## Common questions

**Why is an article missing?** Confirm it was published as an article, check the per-relay publishing result, and try its Nostr address. The instance may not yet have fetched it, or an operator's relay or content policy may exclude it. Operators can inspect worker logs using the [operations guides](../../docs/INDEX.md).

**Why won't login work?** Check that the signer is unlocked and allows this site. For a remote signer, check the connection and its approval prompts.

**Why won't an image load?** Check that its URL points to an accessible image and that the host permits embedding. A published article can outlive the externally hosted image it references.

**Is Nostr a backup?** Relays choose which events to retain. Keep your own copies of important content and media; broadcasting does not guarantee permanent storage.

**Can I self-host?** Yes. See the [setup guide](../../docs/SETUP.md) and [development guide](development.md).

For the underlying concepts, read [Nostr publishing](nostr-cms.md). The [documentation index](../INDEX.md) lists feature guides.
