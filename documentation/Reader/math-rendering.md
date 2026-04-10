# Math / LaTeX Rendering

## Overview

Articles on Nostr can contain mathematical formulas using TeX/LaTeX notation. The rendering pipeline converts these formulas from raw Markdown source into rendered math in the browser using KaTeX.

## Supported Delimiters

| Delimiter | Type | Example |
|-----------|------|---------|
| `$...$` | Inline math | `$E=mc^2$` |
| `$$...$$` | Display math | `$$\frac{1}{2}$$` |
| `\(...\)` | Inline math (LaTeX) | `\(x^2+1\)` |
| `\[...\]` | Display math (LaTeX) | `\[\sum_{i=1}^n x_i\]` |

## Nostr Math Markup Conventions

Many Nostr clients wrap math in backticks to protect it from Markdown parsers that don't understand math delimiters. The converter handles these conventions:

- **Backtick-wrapped inline math**: `` `$E=mc^2$` `` → unwrapped to `$E=mc^2$` then processed as inline math.
- **Backtick-wrapped display math**: `` `$$\frac{1}{2}$$` `` → unwrapped to `$$\frac{1}{2}$$` then processed as display math.
- **Fenced code blocks** with `latex`, `math`, or `tex` language tags (including unclosed blocks) are converted to `$$...$$` display math instead of being rendered as code.

## Pipeline Architecture

### Phase 1: Nostr Markup Normalization (`normalizeNostrMathMarkup`)

Runs first on raw content. Handles Nostr-specific conventions:
1. Converts ` ```latex ` / ` ```math ` / ` ```tex ` fenced code blocks to `$$...$$`
2. Unwraps `` `$$...$$` `` → `$$...$$`
3. Unwraps `` `$...$` `` → `$...$` (only when content looks like math, not shell variables or currency)

### Phase 2: Math Extraction (`extractMathPlaceholders`)

Replaces all math expressions with inert alphanumeric placeholder tokens (`MATHPH0XEND`, `MATHPH1XEND`, etc.) that CommonMark will pass through unchanged. This protects math from CommonMark's backslash escape processing (which would strip `\` before punctuation characters like `(`, `)`, `{`, `}`, `\`).

The original math expressions with their correct delimiters are stored in a map for later restoration.

Order of extraction (most specific first):
1. Display math `$$...$$` → stored as `$$...$$`
2. Display math `\[...\]` → stored as `\[...\]`
3. Inline math `\(...\)` → stored as `\(...\)`
4. Inline math `$...$` → stored as `\(...\)` (normalized to unambiguous delimiter)

Code blocks (fenced and inline) are protected so math detection doesn't match inside them.

### Phase 3: Markdown Conversion

CommonMark processes the content. Placeholder tokens survive unchanged because they contain only alphanumeric characters.

### Phase 4: Math Restoration (`restoreMathPlaceholders`)

After CommonMark produces HTML, placeholder tokens are replaced with the original math expressions. The delimiters (`\(`, `\)`, `$$`, `\\`, etc.) are now in HTML context where they won't be further processed.

### Phase 5: Client-Side Rendering (KaTeX)

The `utility--katex` Stimulus controller (or `katex-init.js` for UnfoldBundle pages) calls KaTeX's `renderMathInElement` to find and render math delimiters in the DOM.

**Delimiters** registered with KaTeX:
- `$$...$$` (display mode)
- `\(...\)` (inline mode)
- `\[...\]` (display mode)

**`ignoredTags`** override: The default KaTeX ignored tags list includes `<code>`, but we remove it to allow math rendering inside inline `<code>` elements (for content that arrives with backtick-wrapped math from sources that bypass the server converter). `<pre>` remains ignored so fenced code blocks are not processed.

## Currency Safety

The pipeline distinguishes math from currency:
- `$10.50`, `$19.99`, `$100` — pure numeric content between `$` signs is left as-is
- The TeX whitespace rule is applied: opening `$` must not be followed by whitespace, closing `$` must not be preceded by whitespace
- The `hasRealMath` heuristic on the client side checks for LaTeX-like syntax (`\frac`, `^`, `_`, `{}`, etc.) before enabling rendering

## Files

| File | Role |
|------|------|
| `src/Util/CommonMark/Converter.php` | Server-side math extraction and restoration |
| `assets/controllers/utility/katex_controller.js` | Stimulus controller for KaTeX rendering |
| `src/UnfoldBundle/Resources/themes/default/assets/katex-init.js` | UnfoldBundle KaTeX initializer |

