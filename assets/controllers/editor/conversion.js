import Delta from '../../vendor/quill-delta/quill-delta.index.js';

/**
 * Optional: enforce canonical delta contract during development.
 * Enable by passing { strict: true } to deltaToMarkdown().
 */
function assertCanonicalDelta(delta) {
  if (!delta || !Array.isArray(delta.ops)) throw new Error('Invalid delta: missing ops array');

  for (const op of delta.ops) {
    // Embeds are allowed
    if (op.insert && typeof op.insert === 'object') continue;

    if (typeof op.insert === 'string') {
      const isNewline = op.insert === '\n';

      // Canonical rule: text ops must not contain embedded newlines
      if (!isNewline && op.insert.includes('\n')) {
        throw new Error('Non-canonical delta: text op contains embedded \\n');
      }

      // Canonical rule: block attrs must appear only on newline ops
      if (!isNewline && op.attributes) {
        const blockKeys = ['header', 'blockquote', 'list', 'indent', 'code-block'];
        for (const k of blockKeys) {
          if (k in op.attributes) {
            throw new Error(`Non-canonical delta: block attr "${k}" found on text op`);
          }
        }
      }
    }
  }
}

// --- Delta to Markdown (canonical) ---
export function deltaToMarkdown(delta, opts = {}) {
  const options = {
    strict: false,         // set true during dev to catch non-canonical deltas
    fence: '```',
    orderedListStyle: 'one', // 'one' or 'increment'
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

  const escapeText = (s) =>
    String(s)
      .replace(/\\/g, '\\\\')
      .replace(/([*_`[\]~])/g, '\\$1');

  const escapeLinkText = (s) =>
    String(s).replace(/\\/g, '\\\\').replace(/([\[\]])/g, '\\$1');

  const escapeLinkUrl = (s) => String(s).replace(/\s/g, '%20');

  function renderInlineText(text, attrs = {}) {
    if (!text) return '';

    if (attrs.code) {
      const t = String(text).replace(/`/g, '\\`');
      return `\`${t}\``;
    }

    let out = escapeText(text);

    if (attrs.link) {
      out = `[${escapeLinkText(out)}](${escapeLinkUrl(attrs.link)})`;
    }

    // wrapper order is a choice; keep it stable
    if (attrs.strike) out = `~~${out}~~`;
    if (attrs.bold) out = `**${out}**`;
    if (attrs.italic) out = `*${out}*`;

    return out;
  }

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

  function flushLine(attrs = {}) {
    // code block line
    if (attrs['code-block']) {
      openFence();
      md += `${line}\n`;  // raw
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
      const level = Math.min(6, Math.max(1, Number(attrs.header) || 1));
      md += `${'#'.repeat(level)} ${line}\n`;
      line = '';
      return;
    }

    // normal / blank
    if (!line.length) {
      md += '\n';
      return;
    }

    md += `${line}\n`;
    line = '';
  }

  for (const op of delta.ops) {
    // embeds
    if (op.insert && typeof op.insert === 'object') {
      const embedMd = options.embedToMarkdown(op.insert);
      if (embedMd) line += embedMd;
      continue;
    }

    // newline
    if (op.insert === '\n') {
      flushLine(op.attributes || {});
      continue;
    }

    // text
    if (typeof op.insert === 'string') {
      // If you truly enforce canonical, this is safe.
      // If you want mild robustness without supporting "weird attrs",
      // you can split embedded newlines but apply NO block attrs here:
      if (op.insert.includes('\n')) {
        // Non-canonical: split without block formatting support
        const parts = op.insert.split('\n');
        for (let p = 0; p < parts.length; p++) {
          if (parts[p]) line += renderInlineText(parts[p], op.attributes || {});
          if (p < parts.length - 1) flushLine({});
        }
      } else {
        if (inCodeBlock) line += op.insert; // raw inside fence
        else line += renderInlineText(op.insert, op.attributes || {});
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

// --- Markdown to Delta (canonical) ---
export function markdownToDelta(md, opts = {}) {
  const options = {
    fence: '```',
    indentSize: 2, // spaces per indent level for lists
    ...opts,
  };

  if (!md) return new Delta([{ insert: '\n' }]);

  const lines = md.replace(/\r\n/g, '\n').split('\n');
  const ops = [];
  let inCodeBlock = false;

  for (const rawLine of lines) {
    const line = rawLine;

    // code fence toggle
    if (line.trim().startsWith(options.fence)) {
      inCodeBlock = !inCodeBlock;
      continue;
    }

    // code block content: emit per-line canonical Quill code-block
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

    // blockquote (canonical: attrs on newline)
    const quoteMatch = line.match(/^>\s?(.*)$/);
    if (quoteMatch) {
      const content = quoteMatch[1] ?? '';
      ops.push(...inlineMarkdownToOps(content));
      ops.push({ insert: '\n', attributes: { blockquote: true } });
      continue;
    }

    // lists with indent
    const leadingSpaces = (line.match(/^(\s*)/)?.[1] ?? '').replace(/\t/g, '    ').length;
    const indent = Math.floor(leadingSpaces / options.indentSize);
    const trimmed = line.trimStart();

    const olMatch = trimmed.match(/^\d+\.\s+(.*)$/);
    if (olMatch) {
      const content = olMatch[1] ?? '';
      ops.push(...inlineMarkdownToOps(content));
      ops.push({ insert: '\n', attributes: { list: 'ordered', ...(indent ? { indent } : {}) } });
      continue;
    }

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
  if (ops.length === 0 || ops[ops.length - 1].insert !== '\n') ops.push({ insert: '\n' });

  return new Delta(ops);
}

// Deterministic inline parser for your subset.
// (This replaces regex-overlap issues in parseInlineOps.)
function inlineMarkdownToOps(text) {
  const ops = [];
  let i = 0;

  const pushText = (t) => { if (t) ops.push({ insert: t }); };

  while (i < text.length) {
    // inline code
    if (text[i] === '`') {
      const end = text.indexOf('`', i + 1);
      if (end !== -1) {
        const content = text.slice(i + 1, end);
        if (content) ops.push({ insert: content, attributes: { code: true } });
        i = end + 1;
        continue;
      }
      pushText('`'); i++; continue;
    }

    // link
    if (text[i] === '[') {
      const closeBracket = text.indexOf(']', i + 1);
      if (closeBracket !== -1 && text[closeBracket + 1] === '(') {
        const closeParen = text.indexOf(')', closeBracket + 2);
        if (closeParen !== -1) {
          const label = text.slice(i + 1, closeBracket);
          const url = text.slice(closeBracket + 2, closeParen);
          if (label) ops.push({ insert: label, attributes: { link: url } });
          i = closeParen + 1;
          continue;
        }
      }
      pushText('['); i++; continue;
    }

    // bold
    if (text.startsWith('**', i)) {
      const end = text.indexOf('**', i + 2);
      if (end !== -1) {
        const content = text.slice(i + 2, end);
        if (content) ops.push({ insert: content, attributes: { bold: true } });
        i = end + 2;
        continue;
      }
      pushText('*'); i++; continue;
    }

    // strike
    if (text.startsWith('~~', i)) {
      const end = text.indexOf('~~', i + 2);
      if (end !== -1) {
        const content = text.slice(i + 2, end);
        if (content) ops.push({ insert: content, attributes: { strike: true } });
        i = end + 2;
        continue;
      }
      pushText('~'); i++; continue;
    }

    // italic
    if (text[i] === '*') {
      const end = text.indexOf('*', i + 1);
      if (end !== -1) {
        const content = text.slice(i + 1, end);
        if (content) ops.push({ insert: content, attributes: { italic: true } });
        i = end + 1;
        continue;
      }
      pushText('*'); i++; continue;
    }

    // plain run
    const next = nextSpecialIndex(text, i);
    pushText(text.slice(i, next));
    i = next;
  }

  return ops;
}

function nextSpecialIndex(text, start) {
  const specials = ['`', '[', '*', '~'];
  let min = text.length;
  for (const ch of specials) {
    const idx = text.indexOf(ch, start);
    if (idx !== -1 && idx < min) min = idx;
  }
  return min;
}
