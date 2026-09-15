# ArticleFromCoordinate Component

A Twig component that fetches and displays an article by its coordinate.

## Usage

```twig
{# Basic usage - just provide the coordinate #}
<twig:Organisms:ArticleFromCoordinate coordinate="30023:pubkey123:article-slug" />

{# With optional author metadata #}
<twig:Organisms:ArticleFromCoordinate 
    coordinate="30023:pubkey123:article-slug"
    :authorsMetadata="authorsMetadata"
/>

{# With magazine and category context #}
<twig:Organisms:ArticleFromCoordinate 
    coordinate="30023:pubkey123:article-slug"
    :authorsMetadata="authorsMetadata"
    mag="magazine-slug"
    cat="category-slug"
/>
```

## Props

- `coordinate` (string, required): Article coordinate in format `kind:pubkey:slug`
- `authorsMetadata` (array, optional): Array of author metadata indexed by pubkey
- `mag` (string, optional): Magazine slug for generating proper links
- `cat` (string, optional): Category slug for generating proper links
- `autoFetch` (bool, default false): Best-effort synchronous relay fetch for missing long-form coordinates; use only on views with a handful of references

## Behavior

- If the coordinate is valid and the article is found in the database, it renders the article card
- Missing articles with parsed identifiers render `CardPlaceholder` with a fetch action; malformed coordinates without usable identifiers show an error message
- Automatically fetches the most recent version of the article if multiple versions exist

## Coordinate Format

The coordinate must be in the format: `kind:pubkey:slug`

Example: `30023:abc123def456:my-article-slug`

Where:
- `kind`: Nostr event kind (e.g., 30023 for articles)
- `pubkey`: Author's public key in hex format
- `slug`: Article slug/identifier
