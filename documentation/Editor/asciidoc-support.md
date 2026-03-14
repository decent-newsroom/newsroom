# AsciiDoc Support

## Overview

The application supports **AsciiDoc** in addition to Markdown for article and chapter content. The `Converter` auto-detects the format and routes to the appropriate parser. Kind 30041 (PUBLICATION_CONTENT) events use AsciiDoc by default per NKBIP-01.

## Key Files

| Component | File |
|-----------|------|
| AsciiDoc converter | `src/Util/AsciiDoc/AsciiDocConverter.php` |
| Main converter | `src/Util/CommonMark/Converter.php` |
| Tests | `tests/Util/AsciiDoc/AsciiDocConverterTest.php` |

## Format Detection

`Converter::convertToHTML(string $content, ?string $format = null)`:
- `null` — auto-detect (checks for AsciiDoc markers like `= Title`, `----`, `[source]`)
- `'asciidoc'` — force AsciiDoc
- `'markdown'` — force Markdown

Kind 30041 chapters always use AsciiDoc: `Converter::convertAsciiDocToHTML($content)`.

## Supported AsciiDoc Features

Document titles (`= Title`), section levels (`==` through `======`), bold/italic, links, images, code blocks (`[source,lang]`), admonitions (NOTE/TIP/WARNING/CAUTION/IMPORTANT), tables, lists (ordered/unordered/definition), horizontal rules, and sidebar blocks.
