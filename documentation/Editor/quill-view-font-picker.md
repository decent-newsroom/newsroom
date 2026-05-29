# Quill view font picker

## Goal
Allow writers to switch editor font family from the Quill toolbar for readability, while keeping published content format-neutral.

## What changed
- Added a view-only font picker to `assets/controllers/publishing/quill_controller.js`.
- Added a 5% stepwise font-size toggle that changes the editor view only.
- The picker is appended to the Quill toolbar and applies `font-family` only on the editor root (`.ql-editor`).
- Font options are configurable through Stimulus values (`fonts`, `defaultFont`) passed from Twig.
- Font-size behavior is configurable through Stimulus values (`fontSizeStep`, `defaultFontSize`, `minFontSize`, `maxFontSize`) passed from Twig.
- Added default options in both editor templates:
  - Sans
  - Serif
  - Mono
- Added toolbar styling in `assets/styles/editor-layout.css`.

## Why this does not affect output
- The picker does not use Quill's `font` format.
- No Delta attributes are added for font changes.
- The hidden field still stores `this.quill.root.innerHTML`, which does not include root-level inline styles.

## Persistence
- Selected font is stored in localStorage under `editor.quill.view-font.{field-id}`.
- Selected font size is stored in localStorage under `editor.quill.view-font-size.{field-id}`.
- On reconnect, the controller restores the previously selected font if it still exists in the configured options.

## Configuration
Configure available fonts in the Twig `quill_widget` block via Stimulus params:

```twig
{% set quill_view_fonts = [
    { label: 'Sans', family: 'system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif' },
    { label: 'Serif', family: 'Georgia, "Times New Roman", Times, serif' },
    { label: 'Mono', family: 'ui-monospace, "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace' }
] %}

<div {{ stimulus_controller('publishing--quill', {
    fonts: quill_view_fonts,
    defaultFont: quill_view_fonts[0].family
}) }} class="quill">
```

Each font entry requires:
- `label`: toolbar option label
- `family`: CSS `font-family` value to apply in the editor view

