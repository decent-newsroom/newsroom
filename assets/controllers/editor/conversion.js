// assets/controllers/editor/conversion.js
//
// Canonical Delta <-> Markdown conversion for QuillJS.
//
// Canonical delta contract (enforced by markdownToDelta; assumed by deltaToMarkdown):
// - Newlines are standalone ops: { insert: '\n', attributes?: { ...blockAttrs } }
// - Block attrs live only on newline ops: header, blockquote, list, indent, code-block
// - Inline attrs live on text ops: bold, italic, strike, code, link
// - Text ops do not contain embedded '\n' (deltaToMarkdown tolerates splitting, but no block attrs from text ops)
//
// Markdown subset supported:
// - #..###### headings
// - > blockquote (single-line)
// - ordered lists ("1. item") and bullet lists ("- item" or "* item") with indentation by leading spaces
// - fenced code blocks ```
// - inline: `code`, **bold**, *italic*, ~~strike~~, [label](url)
//
// Underline intentionally unsupported.

import Delta from '../../vendor/quill-delta/quill-delta.index.js';

// ---------------------------
// Delta -> Markdown
// ---------------------------

export function deltaToMarkdown(delta, opts = {}) {
  const options = {
    strict: false, // if true, throw on non-canonical deltas
    fence: '```',
    orderedListStyle: 'increment', // 'one' | 'increment'
    embedToMarkdown: (embed) => {
      if (!embed || typeof embed !== 'object') return '';
      if (embed.image) return `![](${String(embed.image)})`;
      if (embed.video) return String(embed.video);
      if (embed.nostr) return String(embed.nostr);
      return '';
    },
    ...opts,
  };

  if (!delta || !Array.isArray(delta.ops)) return '';
  if (options.strict) assertCanonicalDelta(delta);

  let md = '';
  let line = '';

  // Block state
  let inCodeBlock = false;
  let inList = null; // 'ordered' | 'bullet' | null
  let listCounter = 1;

  const closeList = () => {
    if (inList) {
      md += '\n';
      inList = null;
      listCounter = 1;
    }
  };

  const openFence = () => {
    if (!inCodeBlock) {
      closeList();
      md += `${options.fence}\n`;
      inCodeBlock = true;
    }
  };

  const closeFence = () => {
    if (inCodeBlock) {
      md += `${options.fence}\n\n`;
      inCodeBlock = false;
    }
  };

  const escapeText = (s) => {
    // Escape special markdown characters, but don't double-escape already escaped ones
    // We process character by character to handle existing escapes properly
    let result = '';
    const str = String(s);
    for (let i = 0; i < str.length; i++) {
      const char = str[i];
      if (char === '\\') {
        // Check if next char is something that could be escaped
        const next = str[i + 1];
        if (next && '\\*_`[]~'.includes(next)) {
          // Already escaped, keep both backslash and next char
          result += '\\' + next;
          i++; // skip next char
        } else {
          // Lone backslash, escape it
          result += '\\\\';
        }
      } else if ('*_`[]~'.includes(char)) {
        // Special char that needs escaping
        result += '\\' + char;
      } else {
        result += char;
      }
    }
    return result;
  };

  const escapeLinkText = (s) => {
    let result = '';
    const str = String(s);
    for (let i = 0; i < str.length; i++) {
      const char = str[i];
      if (char === '\\') {
        const next = str[i + 1];
        if (next && '\\[]'.includes(next)) {
          result += '\\' + next;
          i++;
        } else {
          result += '\\\\';
        }
      } else if ('[]'.includes(char)) {
        result += '\\' + char;
      } else {
        result += char;
      }
    }
    return result;
  };

  const escapeLinkUrl = (s) => String(s).replace(/\s/g, '%20');

  function renderInline(text, attrs = {}) {
    if (!text) return '';

    if (attrs.code) {
      let codeText = '';
      const str = String(text);
      for (let i = 0; i < str.length; i++) {
        const char = str[i];
        if (char === '\\' && i + 1 < str.length && str[i + 1] === '`') {
          codeText += '\\`';
          i++;
        } else if (char === '`') {
          codeText += '\\`';
        } else if (char === '\\') {
          codeText += '\\\\';
        } else {
          codeText += char;
        }
      }
      return `\`${codeText}\``;
    }

    let out = escapeText(text);

    if (attrs.link) {
      out = `[${escapeLinkText(out)}](${escapeLinkUrl(attrs.link)})`;
    }

    // Stable wrapper order
    if (attrs.strike) out = `~~${out}~~`;
    if (attrs.bold) out = `**${out}**`;
    if (attrs.italic) out = `*${out}*`;

    return out;
  }

  function flushLine(blockAttrs = {}) {
    const attrs = blockAttrs || {};

    // code-block line
    if (attrs['code-block']) {
      openFence();
      md += `${line}\n`; // raw content
      line = '';
      return;
    }

    // leaving code block?
    closeFence();

    const indent = Number.isFinite(attrs.indent) ? attrs.indent : 0;
    const indentPrefix = indent > 0 ? '  '.repeat(indent) : '';

    // list line
    if (attrs.list === 'ordered' || attrs.list === 'bullet') {
      const newType = attrs.list;

      if (inList && inList !== newType) md += '\n';
      if (!inList) listCounter = 1;

      inList = newType;

      const marker =
        newType === 'ordered'
          ? (options.orderedListStyle === 'increment' ? `${listCounter++}. ` : '1. ')
          : '- ';

      md += `${indentPrefix}${marker}${line}\n`;
      line = '';
      return;
    }

    // not list anymore
    closeList();

    // blockquote
    if (attrs.blockquote) {
      md += line.length ? `> ${line}\n` : '>\n';
      line = '';
      return;
    }

    // header
    if (attrs.header) {
      const level = clampInt(attrs.header, 1, 6);
      md += `${'#'.repeat(level)} ${line}\n`;
      line = '';
      return;
    }

    // normal / blank line
    if (!line.length) {
      md += '\n';
      return;
    }

    md += `${line}\n`;
    line = '';
  }

  for (const op of delta.ops) {
    // embed
    if (op.insert && typeof op.insert === 'object') {
      const embedMd = options.embedToMarkdown(op.insert);
      if (embedMd) line += embedMd;
      continue;
    }

    // newline (block attrs live here)
    if (op.insert === '\n') {
      flushLine(op.attributes || {});
      continue;
    }

    // text
    if (typeof op.insert === 'string') {
      // Tolerate embedded newlines (no block attrs here).
      if (op.insert.includes('\n')) {
        const parts = op.insert.split('\n');
        for (let p = 0; p < parts.length; p++) {
          if (parts[p]) line += inCodeBlock ? parts[p] : renderInline(parts[p], op.attributes || {});
          if (p < parts.length - 1) flushLine({});
        }
      } else {
        line += inCodeBlock ? op.insert : renderInline(op.insert, op.attributes || {});
      }
    }
  }

  // finalize
  if (line.length) {
    closeFence();
    closeList();
    md += `${line}\n`;
    line = '';
  }

  closeFence();
  closeList();

  md = md.replace(/\n{4,}/g, '\n\n\n');
  return md.replace(/[ \t]+\n/g, '\n').replace(/\s+$/, '');
}

function assertCanonicalDelta(delta) {
  const blockKeys = ['header', 'blockquote', 'list', 'indent', 'code-block'];

  for (const op of delta.ops) {
    if (op.insert && typeof op.insert === 'object') continue;

    if (typeof op.insert === 'string') {
      const isNewline = op.insert === '\n';

      if (!isNewline && op.insert.includes('\n')) {
        throw new Error('Non-canonical delta: text op contains embedded \\n');
      }

      if (!isNewline && op.attributes) {
        for (const k of blockKeys) {
          if (k in op.attributes) {
            throw new Error(`Non-canonical delta: block attr "${k}" found on text op`);
          }
        }
      }
    }
  }
}

// ---------------------------
// Markdown -> Delta (canonical)
// ---------------------------

export function markdownToDelta(md, opts = {}) {
  const options = {
    fence: '```',
    indentSize: 2, // leading spaces per list indent level
    ...opts,
  };

  if (!md) return new Delta([{ insert: '\n' }]);

  const lines = md.replace(/\r\n/g, '\n').split('\n');
  const ops = [];
  let inCodeBlock = false;

  for (const rawLine of lines) {
    const line = rawLine;

    // fence toggle
    if (line.trim().startsWith(options.fence)) {
      inCodeBlock = !inCodeBlock;
      continue;
    }

    // code-block content: canonical Quill style (attrs on newline per line)
    if (inCodeBlock) {
      if (line.length) ops.push({ insert: line });
      ops.push({ insert: '\n', attributes: { 'code-block': true } });
      continue;
    }

    // blank line
    if (line.trim() === '') {
      ops.push({ insert: '\n' });
      continue;
    }

    // header
    const headerMatch = line.match(/^(#{1,6})\s+(.*)$/);
    if (headerMatch) {
      const level = headerMatch[1].length;
      const content = headerMatch[2] ?? '';
      ops.push(...inlineMarkdownToOps(content));
      ops.push({ insert: '\n', attributes: { header: level } });
      continue;
    }

    // blockquote
    const quoteMatch = line.match(/^>\s?(.*)$/);
    if (quoteMatch) {
      const content = quoteMatch[1] ?? '';
      ops.push(...inlineMarkdownToOps(content));
      ops.push({ insert: '\n', attributes: { blockquote: true } });
      continue;
    }

    // list indent by leading spaces (tabs treated as 4 spaces)
    const leadingSpaces = (line.match(/^(\s*)/)?.[1] ?? '').replace(/\t/g, '    ').length;
    const indent = Math.floor(leadingSpaces / options.indentSize);
    const trimmed = line.trimStart();

    // ordered list
    const olMatch = trimmed.match(/^\d+\.\s+(.*)$/);
    if (olMatch) {
      const content = olMatch[1] ?? '';
      ops.push(...inlineMarkdownToOps(content));
      ops.push({ insert: '\n', attributes: { list: 'ordered', ...(indent ? { indent } : {}) } });
      continue;
    }

    // bullet list
    const ulMatch = trimmed.match(/^[-*]\s+(.*)$/);
    if (ulMatch) {
      const content = ulMatch[1] ?? '';
      ops.push(...inlineMarkdownToOps(content));
      ops.push({ insert: '\n', attributes: { list: 'bullet', ...(indent ? { indent } : {}) } });
      continue;
    }

    // paragraph
    ops.push(...inlineMarkdownToOps(line));
    ops.push({ insert: '\n' });
  }

  // ensure trailing newline
  if (ops.length === 0 || ops[ops.length - 1].insert !== '\n') {
    ops.push({ insert: '\n' });
  }

  return new Delta(ops);
}

// ---------------------------
// Inline markdown parsing (subset)
// ---------------------------

function inlineMarkdownToOps(text) {
  const ops = [];
  let i = 0;

  const pushText = (t) => { if (t) ops.push({ insert: t }); };

  const unescapeText = (t) => t.replace(/\\([\\*_`[\]~])/g, '$1');

  while (i < text.length) {
    // escaped character: \X
    if (text[i] === '\\' && i + 1 < text.length) {
      const next = text[i + 1];
      if ('\\*_`[]~'.includes(next)) {
        pushText(next);
        i += 2;
        continue;
      }
      // not a recognized escape, treat backslash literally
      pushText('\\');
      i += 1;
      continue;
    }

    // inline code: `...`
    if (text[i] === '`') {
      const end = text.indexOf('`', i + 1);
      if (end !== -1) {
        const content = text.slice(i + 1, end);
        if (content) ops.push({ insert: content, attributes: { code: true } });
        i = end + 1;
        continue;
      }
      pushText('`'); i += 1; continue;
    }

    // link: [label](url)
    if (text[i] === '[') {
      const closeBracket = text.indexOf(']', i + 1);
      if (closeBracket !== -1 && text[closeBracket + 1] === '(') {
        const closeParen = text.indexOf(')', closeBracket + 2);
        if (closeParen !== -1) {
          const label = unescapeText(text.slice(i + 1, closeBracket));
          const url = text.slice(closeBracket + 2, closeParen).replace(/%20/g, ' ');
          if (label) ops.push({ insert: label, attributes: { link: url } });
          i = closeParen + 1;
          continue;
        }
      }
      pushText('['); i += 1; continue;
    }

    // bold: **...**
    if (text.startsWith('**', i)) {
      const end = text.indexOf('**', i + 2);
      if (end !== -1) {
        const content = unescapeText(text.slice(i + 2, end));
        if (content) ops.push({ insert: content, attributes: { bold: true } });
        i = end + 2;
        continue;
      }
      pushText('*'); i += 1; continue;
    }

    // strike: ~~...~~
    if (text.startsWith('~~', i)) {
      const end = text.indexOf('~~', i + 2);
      if (end !== -1) {
        const content = unescapeText(text.slice(i + 2, end));
        if (content) ops.push({ insert: content, attributes: { strike: true } });
        i = end + 2;
        continue;
      }
      pushText('~'); i += 1; continue;
    }

    // italic: *...*
    if (text[i] === '*') {
      const end = text.indexOf('*', i + 1);
      if (end !== -1) {
        const content = unescapeText(text.slice(i + 1, end));
        if (content) ops.push({ insert: content, attributes: { italic: true } });
        i = end + 1;
        continue;
      }
      pushText('*'); i += 1; continue;
    }

    // plain run
    const next = nextSpecialIndex(text, i);
    pushText(text.slice(i, next));
    i = next;
  }

  return ops;
}

function nextSpecialIndex(text, start) {
  const specials = ['\\', '`', '[', '*', '~'];
  let min = text.length;
  for (const ch of specials) {
    const idx = text.indexOf(ch, start);
    if (idx !== -1 && idx < min) min = idx;
  }
  return min;
}

function clampInt(value, min, max) {
  const n = Number(value);
  if (!Number.isFinite(n)) return min;
  return Math.min(max, Math.max(min, Math.trunc(n)));
}
