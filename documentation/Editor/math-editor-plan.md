# Math in the Quill Editor: Implementation Plan

## Current State

There are **two separate Delta↔Markdown converters** and a Quill instance that already has partial math support:

| Component | File | Math support |
|-----------|------|-------------|
| Quill controller (publishing) | `assets/controllers/publishing/quill_controller.js` | ✅ KaTeX global, `formula` in formats, `convertFormulasToEmbeds()` on load |
| Publishing `deltaToMarkdown` | same file (bottom) | ✅ formula embeds → `\(…\)` inline or `$$…$$` display |
| Editor `deltaToMarkdown` | `assets/controllers/editor/conversion.js` | ❌ No formula handling |
| Editor `markdownToDelta` | same file | ❌ No formula handling |
| Editor layout controller | `assets/controllers/editor/layout_controller.js` | Uses `conversion.js` for round-trips |

The publishing controller already converts formulas to markdown and re-hydrates them from HTML. But the **editor's canonical converter** (`conversion.js`) — used for the rich-text ↔ markdown tab switching — has no math awareness at all. This means:

- Switching from Quill (rich text) to Markdown tab **drops all formulas** (they become empty strings or `[embed]`).
- Switching from Markdown tab back to Quill **loses all `$…$` / `$$…$$` math** (parsed as literal text, not formula embeds).

## What Needs to Change

### 1. `deltaToMarkdown` in `conversion.js` — handle formula embeds

In the `embedToMarkdown` default handler and the main loop, formula embeds are objects like `{ formula: "x^2 + 1" }`. They need to be converted to markdown math.

**Logic:**
- If the formula embed is the **only non-whitespace content** on a line → emit `$$…$$` (display math).
- Otherwise → emit `$…$` (inline math).

Since the converter processes ops sequentially and flushes on newline, the simplest approach is:

```js
// In embedToMarkdown default:
if (embed.formula) return `$${embed.formula}$`;

// Then post-process: detect lines that are ONLY a single $…$ and promote to $$…$$
```

Or better — detect at flush time whether the line is a solo formula and emit `$$\n…\n$$` vs `$…$`. This mirrors what the publishing converter already does with `isDisplayFormulaLine`.

**Underscore escaping:** The publishing converter escapes `_` → `\_` inside math for Nostr relay compatibility. The editor converter should do the same when outputting markdown for publishing, but **not** during tab-switch round-trips (where losslessness matters more). Use an option flag.

### 2. `markdownToDelta` in `conversion.js` — parse math delimiters

The inline parser (`inlineMarkdownToOps`) needs to recognize `$…$` and emit formula embeds. The block-level parser needs to recognize `$$…$$` (single-line and multi-line).

**Block level** (in the `for` loop over lines):

```js
// Single-line display math: $$…$$
const displayMatch = line.match(/^\$\$(.+)\$\$$/);
if (displayMatch) {
  ops.push({ insert: { formula: displayMatch[1] } });
  ops.push({ insert: '\n' });
  continue;
}

// Multi-line display math: $$ on its own line opens, next $$ closes
if (line.trim() === '$$') {
  // Collect lines until closing $$
  let mathContent = '';
  lineIdx++;
  while (lineIdx < lines.length && lines[lineIdx].trim() !== '$$') {
    mathContent += (mathContent ? '\n' : '') + lines[lineIdx];
    lineIdx++;
  }
  ops.push({ insert: { formula: mathContent } });
  ops.push({ insert: '\n' });
  continue;
}
```

**Inline level** (in `inlineMarkdownToOps`):

Add `$` to the specials list in `nextSpecialIndex`. Then handle it:

```js
// inline math: $…$ (not $$)
if (text[i] === '$' && text[i + 1] !== '$') {
  const end = text.indexOf('$', i + 1);
  if (end !== -1) {
    const tex = text.slice(i + 1, end);
    // Skip pure currency
    if (!/^\s*[\d.,]+\s*$/.test(tex)) {
      ops.push({ insert: { formula: tex } });
      i = end + 1;
      continue;
    }
  }
  pushText('$'); i += 1; continue;
}
```

Also handle `\(…\)` and `\[…\]` delimiters the same way, since the server-side converter may have rewritten `$` to `\(`.

### 3. Quill toolbar — add a formula button

The toolbar in `quill_controller.js` already comments out `'formula'`:

```js
['link', 'blockquote', 'code-block', 'image'], // 'formula' can be added if needed
```

Uncomment it:

```js
['link', 'blockquote', 'code-block', 'image', 'formula'],
```

This gives users a toolbar button that opens Quill's built-in formula prompt (uses KaTeX for preview). KaTeX and its CSS are already imported and globally available.

### 4. Underscore round-trip safety

The publishing converter double-escapes `_` → `\_` for Nostr posting. This is **lossy** for the editor round-trip because `\_` will be parsed back as literal `\_`, not `_`.

**Solution:** The `deltaToMarkdown` in `conversion.js` should output raw `_` in math (no escaping). The underscore escaping for Nostr posting should happen only at publish time, in the publishing converter or in `syncContentBeforePublish`.

### 5. Quill `convertFormulasToEmbeds` — also handle markdown math in loaded content

When an article is loaded from markdown (e.g., editing a draft), the content goes through `markdownToDelta` which (after the changes above) produces proper formula embeds. No extra work needed for this path.

When loaded from HTML (the `convertFormulasToEmbeds` path), formulas are already handled via `span.ql-formula[data-value]`. This path is fine.

## Summary of File Changes

| File | Change |
|------|--------|
| `assets/controllers/editor/conversion.js` | Add formula embed → `$…$`/`$$…$$` in `deltaToMarkdown`; add `$…$`/`$$…$$` parsing in `markdownToDelta` and `inlineMarkdownToOps`; add `$` to `nextSpecialIndex` |
| `assets/controllers/publishing/quill_controller.js` | Uncomment `'formula'` in toolbar |
| `tests/` (if JS tests exist) | Add round-trip tests: `md → delta → md` for inline and display math |

## Edge Cases to Test

1. **Round-trip fidelity:** `$E=mc^2$` → delta with formula embed → `$E=mc^2$` (no loss)
2. **Display math:** solo formula on a line → `$$…$$` in markdown
3. **Mixed content:** `Text $x^2$ more text` — formula stays inline
4. **Currency safety:** `$19.99` stays as text, not a formula embed
5. **Underscore in math:** `$x_{i+1}$` survives the round-trip without extra backslashes
6. **Multi-line display math:** `$$\n\begin{aligned}…\end{aligned}\n$$` → single formula embed → back to `$$…$$`
7. **Code blocks containing `$`:** not parsed as math
8. **`\(…\)` delimiters:** parsed to formula embeds, output as `$…$` (canonical form)

