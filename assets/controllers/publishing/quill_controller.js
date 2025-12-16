import { Controller } from '@hotwired/stimulus';
import Quill from 'quill';

import 'quill/dist/quill.core.css';
import 'quill/dist/quill.snow.css';
import 'katex/dist/katex.min.css';

// KaTeX must be global for Quill's formula module
import * as katex from 'katex';
window.katex = katex;

export default class extends Controller {
  static targets = ['hidden', 'markdown']   // hidden = HTML, markdown = MD

  connect() {
    // --- 1) Custom IMG blot that supports alt ---
    const BlockEmbed = Quill.import('blots/block/embed');
    class ImageAltBlot extends BlockEmbed {
      static blotName = 'imageAlt'; // (set to 'image' to override default)
      static tagName = 'IMG';

      static create(value) {
        const node = super.create();
        if (typeof value === 'string') {
          node.setAttribute('src', value);
        } else if (value && value.src) {
          node.setAttribute('src', value.src);
          if (value.alt) node.setAttribute('alt', value.alt);
        }
        node.setAttribute('draggable', 'false');
        return node;
      }

      static value(node) {
        return {
          src: node.getAttribute('src') || '',
          alt: node.getAttribute('alt') || '',
        };
      }
    }
    Quill.register(ImageAltBlot);

    // --- 2) Simple image tooltip (URL + alt) ---
    const Tooltip = Quill.import('ui/tooltip');
    class ImageTooltip extends Tooltip {
      constructor(quill, boundsContainer) {
        super(quill, boundsContainer);
        this.root.classList.add('ql-image-tooltip');
        this.root.innerHTML = [
          '<span class="ql-tooltip-arrow"></span>',
          '<div class="ql-tooltip-editor">',
          '<input class="ql-image-src" type="text" placeholder="Image URL" />',
          '<input class="ql-image-alt" type="text" placeholder="Alt text" />',
          '<a class="ql-action">Insert</a>',
          '<a class="ql-cancel">Cancel</a>',
          '</div>',
        ].join('');

        this.srcInput = this.root.querySelector('.ql-image-src');
        this.altInput = this.root.querySelector('.ql-image-alt');
        this.action = this.root.querySelector('.ql-action');
        this.cancel = this.root.querySelector('.ql-cancel');

        this.action.addEventListener('click', () => this.save());
        this.cancel.addEventListener('click', () => this.hide());

        const keyHandler = (e) => {
          if (e.key === 'Enter') { e.preventDefault(); this.save(); }
          if (e.key === 'Escape') { e.preventDefault(); this.hide(); }
        };
        this.srcInput.addEventListener('keydown', keyHandler);
        this.altInput.addEventListener('keydown', keyHandler);
      }

      edit(prefill = null) {
        const range = this.quill.getSelection(true);
        if (!range) return;
        const bounds = this.quill.getBounds(range);
        this.show();
        this.position(bounds);
        this.root.classList.add('ql-editing');
        this.srcInput.value = prefill?.src || '';
        this.altInput.value = prefill?.alt || '';
        this.srcInput.focus();
        this.srcInput.select();
      }

      hide() {
        this.root.classList.remove('ql-editing');
        super.hide();
      }

      save() {
        const src = (this.srcInput.value || '').trim();
        const alt = (this.altInput.value || '').trim();
        if (!src || !/^https?:|^data:image\//i.test(src)) {
          this.srcInput.focus();
          return;
        }
        const range = this.quill.getSelection(true);
        if (!range) return;

        const [blot, blotOffset] = this.quill.getLeaf(range.index);
        const isImg = blot?.domNode?.tagName === 'IMG';

        if (isImg) {
          const idx = range.index - blotOffset;
          this.quill.deleteText(idx, 1, 'user');
          this.quill.insertEmbed(idx, 'imageAlt', { src, alt }, 'user');
          this.quill.setSelection(idx + 1, 0, 'user');
        } else {
          this.quill.insertEmbed(range.index, 'imageAlt', { src, alt }, 'user');
          this.quill.setSelection(range.index + 1, 0, 'user');
        }
        this.hide();
      }
    }

    // --- 3) Quill init ---
    const toolbarOptions = [
      ['bold', 'italic', 'strike'],
      ['link', 'blockquote', 'code-block', 'image'], // 'formula' can be added if needed
      [{ header: 1 }, { header: 2 }, { header: 3 }],
      [{ list: 'ordered' }, { list: 'bullet' }],
    ];

    const options = {
      theme: 'snow',
      modules: {
        toolbar: toolbarOptions,
      },
    };

    // Root editor element & hidden target
    const editorEl =
      this.element.querySelector('#editor') ||
      document.querySelector('#editor');

    // Before initializing Quill, check if there's existing HTML with formulas
    const existingHTML = editorEl.innerHTML.trim();
    const hasFormulas = existingHTML.includes('ql-formula');

    this.quill = new Quill(editorEl, options);

    // Expose globally for preview functionality
    window.appQuill = this.quill;

    // If there were formulas in the loaded HTML, we need to convert them to proper embeds
    if (hasFormulas) {
      this.convertFormulasToEmbeds();
    }

    // Image tooltip wiring
    const imageTooltip = new ImageTooltip(this.quill, this.quill.root.parentNode);
    this.quill.getModule('toolbar').addHandler('image', () => {
      const range = this.quill.getSelection(true);
      let prefill = null;
      if (range) {
        const [blot] = this.quill.getLeaf(range.index);
        if (blot?.domNode?.tagName === 'IMG') {
          prefill = {
            src: blot.domNode.getAttribute('src') || '',
            alt: blot.domNode.getAttribute('alt') || '',
          };
        }
      }
      imageTooltip.edit(prefill);
    });

    // --- 4) Nostr link highlighting ---
    const NOSTR_GLOBAL = /\bnostr:(?:note1|npub1|nprofile1|nevent1|naddr1|nrelay1|nsec1)[a-z0-9]+/gi;
    const highlightAll = () => {
      const text = this.quill.getText(); // includes trailing \n
      this.quill.formatText(0, text.length, { background: false }, 'api');
      for (const m of text.matchAll(NOSTR_GLOBAL)) {
        this.quill.formatText(m.index, m[0].length, { background: 'rgba(168, 85, 247, 0.18)' }, 'api');
      }
    };

    highlightAll();
    this.quill.on('text-change', (delta, old, source) => {
      if (source === 'user') highlightAll();
      this.syncHiddenAsHtml();
    });

    const sync = () => {
      // HTML
      if (this.hasHiddenTarget) this.hiddenTarget.value = this.quill.root.innerHTML;
      // Markdown (from Delta)
      if (this.hasMarkdownTarget) this.markdownTarget.value = deltaToMarkdown(this.quill.getContents());
    };

    // sync on load and on every edit
    sync();
    this.quill.on('text-change', (delta, oldDelta, source) => {
      if (source === 'user') highlightAll();
      sync();
    });

    // safety: also refresh MD/HTML right before a real submit (if any)
    const form = this.element.closest('form');
    if (form) {
      form.addEventListener('submit', () => sync());
    }
  }

  // keep hidden field updated with HTML while typing (optional)
  syncHiddenAsHtml() {
    if (!this.hasHiddenTarget) return;
    this.hiddenTarget.value = this.quill.root.innerHTML;
  }

  // Convert formula spans in loaded HTML to proper Quill formula embeds
  convertFormulasToEmbeds() {
    const root = this.quill.root;
    const formulaSpans = root.querySelectorAll('span.ql-formula');

    if (formulaSpans.length === 0) return;

    const deltaOps = [];

    // Walk through DOM and rebuild the delta, converting formulas to embeds
    const processNode = (node, attrs = {}) => {
      if (node.nodeType === Node.TEXT_NODE) {
        if (node.textContent) {
          deltaOps.push({ insert: node.textContent, attributes: Object.keys(attrs).length ? attrs : undefined });
        }
      } else if (node.nodeType === Node.ELEMENT_NODE) {
        const tag = node.tagName.toLowerCase();

        // Handle formula spans
        if (node.classList && node.classList.contains('ql-formula')) {
          const texValue = node.getAttribute('data-value');
          if (texValue) {
            deltaOps.push({ insert: { formula: texValue } });
          }
          return;
        }

        // Handle images
        if (tag === 'img') {
          const src = node.getAttribute('src');
          const alt = node.getAttribute('alt');
          if (src) {
            deltaOps.push({ insert: { imageAlt: { src, alt: alt || '' } } });
          }
          return;
        }

        // Track inline formatting
        const newAttrs = { ...attrs };
        if (tag === 'strong') newAttrs.bold = true;
        if (tag === 'em') newAttrs.italic = true;
        if (tag === 'code') newAttrs.code = true;
        if (tag === 's') newAttrs.strike = true;
        if (tag === 'a') newAttrs.link = node.getAttribute('href');

        // Handle block elements
        if (tag === 'p' || tag === 'div' || tag === 'br') {
          for (const child of node.childNodes) {
            processNode(child, newAttrs);
          }
          if (tag === 'br' || (deltaOps.length > 0 && deltaOps[deltaOps.length - 1].insert !== '\n')) {
            deltaOps.push({ insert: '\n' });
          }
          return;
        }

        // Handle headings
        if (tag.match(/^h[1-6]$/)) {
          for (const child of node.childNodes) {
            processNode(child, newAttrs);
          }
          const level = parseInt(tag[1]);
          deltaOps.push({ insert: '\n', attributes: { header: level } });
          return;
        }

        // Handle lists
        if (tag === 'li') {
          const parent = node.parentElement;
          const listType = parent?.tagName.toLowerCase() === 'ol' ? 'ordered' : 'bullet';
          for (const child of node.childNodes) {
            processNode(child, newAttrs);
          }
          deltaOps.push({ insert: '\n', attributes: { list: listType } });
          return;
        }

        // Handle blockquotes
        if (tag === 'blockquote') {
          for (const child of node.childNodes) {
            processNode(child, newAttrs);
          }
          if (deltaOps.length > 0 && deltaOps[deltaOps.length - 1].insert !== '\n') {
            deltaOps.push({ insert: '\n', attributes: { blockquote: true } });
          }
          return;
        }

        // Handle code blocks
        if (tag === 'pre') {
          const codeContent = node.textContent || '';
          deltaOps.push({ insert: codeContent });
          deltaOps.push({ insert: '\n', attributes: { 'code-block': true } });
          return;
        }

        // Default: process children
        for (const child of node.childNodes) {
          processNode(child, newAttrs);
        }
      }
    };

    for (const child of root.childNodes) {
      processNode(child);
    }

    // Ensure delta ends with a newline
    if (deltaOps.length === 0 || deltaOps[deltaOps.length - 1].insert !== '\n') {
      deltaOps.push({ insert: '\n' });
    }

    this.quill.setContents(deltaOps, 'silent');
  }

  disconnect() {
    // Clean up global reference
    if (window.appQuill === this.quill) {
      window.appQuill = null;
    }
  }
}

/* ---------- Delta → Markdown with $...$ / $$...$$ ---------- */
function escapeUnderscoresInTeXForPosting(tex) {
  if (!tex) return tex;
  // Double-escape underscore for posting: produce two backslashes before _ at runtime
  return tex.replace(/_/g, "\\_");
}

function deltaToMarkdown(delta) {
  const ops = delta.ops || [];

  // Build logical lines; the attributes on the *newline* op define the block
  const lines = [];
  let inlines = [];
  let pendingBlock = {}; // attrs from the newline that ended the line

  const pushText = (text, attrs) => {
    if (!text) return;
    inlines.push({ type: 'text', text, attrs: attrs || null });
  };
  const pushEmbed = (embed, attrs) => {
    inlines.push({ type: 'embed', embed, attrs: attrs || null });
  };
  const endLine = (newlineAttrs) => {
    lines.push({ inlines, block: newlineAttrs || pendingBlock || {} });
    inlines = [];
    pendingBlock = {};
  };

  for (const op of ops) {
    const attrs = op.attributes || null;

    if (typeof op.insert === 'string') {
      // Split by '\n' but preserve the newline attrs as the block style
      const parts = op.insert.split('\n');
      for (let i = 0; i < parts.length; i++) {
        if (parts[i]) pushText(parts[i], attrs);
        if (i < parts.length - 1) {
          // This newline ends the line: its attrs define header/list/blockquote
          endLine(attrs);
        }
      }
    } else if (op.insert) {
      pushEmbed(op.insert, attrs);
    }
  }
  if (inlines.length) lines.push({ inlines, block: pendingBlock || {} });

  const stripZW = (s) => s.replace(/[\u200B\u200C\u200D\u2060\uFEFF]/g, '');

  const renderInline = (seg) => {
    if (seg.type === 'embed') {
      const e = seg.embed;
      if (e.formula) return `$${escapeUnderscoresInTeXForPosting(e.formula)}$`;
      if (e.imageAlt) return `![${e.imageAlt.alt || 'image'}](${e.imageAlt.src || ''})`;
      if (e.image) return `![image](${e.image})`;
      return '[embed]';
    }
    let t = stripZW(seg.text);
    const a = seg.attrs || {};
    if (a.code)   t = '`' + t + '`';
    if (a.italic) t = '*' + t + '*';
    if (a.bold)   t = '**' + t + '**';
    if (a.link)   t = `[${t}](${a.link})`;
    return t;
  };

  // Tolerant display-math detection: allow surrounding whitespace only
  const isDisplayFormulaLine = (inlines) => {
    let tex = null;
    for (const seg of inlines) {
      if (seg.type === 'embed' && seg.embed?.formula) {
        if (tex !== null) return null; // more than one formula
        tex = seg.embed.formula;
      } else if (seg.type === 'text' && stripZW(seg.text).trim() === '') {
        // whitespace ok
      } else {
        return null; // other content present
      }
    }
    return tex ? tex : null;
  };

  const mdLines = [];

  for (const { inlines: L, block } of lines) {
    // Display math line
    const dispTex = isDisplayFormulaLine(L);
    if (dispTex) {
      mdLines.push('$$\n' + escapeUnderscoresInTeXForPosting(dispTex) + '\n$$');
      continue;
    }

    // Inline content
    const content = L.map(renderInline).join('');

    // Block styling (header/list/blockquote/code)
    if (block['code-block']) { mdLines.push('```\n' + content + '\n```'); continue; }
    if (block.blockquote)    { mdLines.push('> ' + content); continue; }

    const h = block.header;
    if (h >= 1 && h <= 6) {
      mdLines.push(`${'#'.repeat(h)} ${content}`);
      continue;
    }

    if (block.list === 'ordered') { mdLines.push(`1. ${content}`); continue; }
    if (block.list === 'bullet')  { mdLines.push(`- ${content}`);  continue; }

    mdLines.push(content);
  }

  // Normalize spacing: a blank line after headings & code blocks helps some renderers
  let out = mdLines.join('\n');
  // Escape "_" inside display math $$...$$ and inline math $...$
  out = out.replace(/\$\$([\s\S]*?)\$\$/g, (m, g1) => `$$${g1.replace(/_/g, (u, i, s) => (i>0 && s[i-1]==='\\') ? '\\_' : '\\_')}$$`);
  out = out.replace(/\$([^$]*?)\$/g, (m, g1) => `$${g1.replace(/_/g, (u, i, s) => (i>0 && s[i-1]==='\\') ? '\\_' : '\\_')}$`);

  out = out.replace(/(\n?^#{1,6} .*$)/gm, '$1'); // keep as-is
  out = out.replace(/\n{3,}/g, '\n\n');
  return out.trim();
}
