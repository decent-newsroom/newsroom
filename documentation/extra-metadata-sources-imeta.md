# Extra Metadata for Articles: Sources and Media Attachments

## Overview

This feature adds first-class support for two additional Nostr event tag types in the article editor:

1. **Sources (`r` tags)** — Reference URLs pointing to source material used in the article.
2. **Media Attachments (`imeta` tags)** — Structured media metadata for files associated with the article, such as audio, video, or images.

Both tag types can be added through the article editor's "Advanced Metadata" section, and they are preserved during round-trip editing (parse → edit → republish).

## Nostr Protocol Tags

### Source Tags (`r`)

The `r` tag is used to reference external URLs. Each source URL gets its own tag:

```json
["r", "https://example.com/source-article"],
["r", "https://another.example.com/reference"]
```

### Media Attachment Tags (`imeta`)

The `imeta` tag stores structured media metadata using space-delimited key-value pairs:

```json
["imeta", "m audio/mpeg", "url https://anchor.fm/s/935aecc/podcast/play/116057189/https%3A%2F%2Fd3ctxlq1ktw2nl.cloudfront.net%2Fstaging%2F2026-1-26%2F418840879-44100-2-a8fd65ad61bb9.mp3"]
```

Each `imeta` tag contains:
- `m <mime-type>` — The MIME type of the media (e.g., `audio/mpeg`, `image/png`, `video/mp4`)
- `url <url>` — The URL where the media file is hosted

## Architecture

### DTO Layer

- **`MediaAttachment`** (`src/Dto/MediaAttachment.php`) — New DTO representing a single media attachment with `url` and `mimeType` properties. Includes `toTag()` and `fromTag()` static methods for Nostr event serialization/deserialization.
- **`AdvancedMetadata`** (`src/Dto/AdvancedMetadata.php`) — Extended with two new properties:
  - `sources: string[]` — Array of source URLs
  - `mediaAttachments: MediaAttachment[]` — Array of media attachments

### Form Layer

- **`MediaAttachmentType`** (`src/Form/MediaAttachmentType.php`) — New Symfony form type for a single media attachment (URL + MIME type fields).
- **`AdvancedMetadataType`** (`src/Form/AdvancedMetadataType.php`) — Extended with:
  - `sources` — `CollectionType` of `UrlType` entries
  - `mediaAttachments` — `CollectionType` of `MediaAttachmentType` entries

### Service Layer

- **`NostrEventBuilder`** (`src/Service/Nostr/NostrEventBuilder.php`) — `buildAdvancedTags()` now emits `['r', url]` for each source and calls `MediaAttachment::toTag()` for each attachment.
- **`NostrEventParser`** (`src/Service/Nostr/NostrEventParser.php`) — `parseAdvancedMetadata()` now handles `case 'r':` and `case 'imeta':` to populate the respective fields on `AdvancedMetadata`, preventing them from landing in `extraTags`.

### Frontend Layer

- **Template** (`templates/pages/_advanced_metadata.html.twig`) — New "Sources" and "Media Attachments" sections with dynamic add/remove rows, placed between Zap Splits and the hidden fields.
- **Stimulus Controller** (`assets/controllers/content/advanced_metadata_controller.js`) — New targets (`sourcesContainer`, `mediaAttachmentsContainer`) and actions (`addSource`, `removeSource`, `addMediaAttachment`, `removeMediaAttachment`).
- **Publish Controller** (`assets/controllers/nostr/nostr_publish_controller.js`) — `buildAdvancedTags()` emits `r` and `imeta` tags. `collectAdvancedMetadata()` reads sources and media attachment form fields from `FormData`.

## Usage

1. Open the article editor.
2. Scroll to the **Advanced Metadata** section.
3. Under **Sources**, click "Add Source" to add reference URLs.
4. Under **Media Attachments**, click "Add Media Attachment" to add media files with their MIME type and URL.
5. Publish the article — the tags are included in the signed Nostr event.

When editing an existing article that already contains `r` or `imeta` tags, the values are pre-populated in the form.

## Testing

Unit tests cover:
- Building `r` tags from sources (`NostrEventBuilderTest::testBuildSourceTags`)
- Building `imeta` tags from media attachments (`NostrEventBuilderTest::testBuildImetaTags`)
- Building multiple `imeta` tags (`NostrEventBuilderTest::testBuildMultipleImetaTags`)
- Empty sources/attachments produce no tags (`NostrEventBuilderTest::testBuildEmptySourcesAndAttachmentsProducesNoTags`)
- Parsing `r` tags (`NostrEventParserTest::testParseSourceTags`)
- Parsing `imeta` tags (`NostrEventParserTest::testParseImetaTags`)
- Parsing multiple `imeta` tags (`NostrEventParserTest::testParseMultipleImetaTags`)
- Source and imeta tags not leaking into `extraTags` (`NostrEventParserTest::testSourceAndImetaTagsNotInExtraTags`)
- Full round-trip parsing (`NostrEventParserTest::testRoundTripSourcesAndImeta`)

