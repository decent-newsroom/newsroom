# ArticleFromCoordinate Component

A Twig component that fetches and displays an article by its coordinate.

## Usage

```twig
{# Basic usage - just provide the coordinate #}
<twig:Organisms:ArticleFromCoordinate coordinate="30023:pubkey123:article-slug" />

{# With optional author metadata #}
<twig:Organisms:ArticleFromCoordinate 
    coordinate="30023:pubkey123:article-slug"
    :authors_metadata="authorsMetadata"
/>

{# With magazine and category context #}
<twig:Organisms:ArticleFromCoordinate 
    coordinate="30023:pubkey123:article-slug"
    :authors_metadata="authorsMetadata"
    mag="magazine-slug"
    cat="category-slug"
/>
```

## Props

- `coordinate` (string, required): Article coordinate in format `kind:pubkey:slug`
- `authorsMetadata` (array, optional): Array of author metadata indexed by pubkey
- `mag` (string, optional): Magazine slug for generating proper links
- `cat` (string, optional): Category slug for generating proper links

## Behavior

- If the coordinate is valid and the article is found in the database, it renders the article card
- If the coordinate is invalid or the article is not found, it displays an info bubble with an error message
- Automatically fetches the most recent version of the article if multiple versions exist

## Coordinate Format

The coordinate must be in the format: `kind:pubkey:slug`

Example: `30023:abc123def456:my-article-slug`

Where:
- `kind`: Nostr event kind (e.g., 30023 for articles)
- `pubkey`: Author's public key in hex format
- `slug`: Article slug/identifier
