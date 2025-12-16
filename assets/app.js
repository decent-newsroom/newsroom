import './bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */

// 01 - Base styles (theme, fonts, typography, reset)
import './styles/01-base/fonts.css';
import './styles/01-base/theme.css';
import './styles/01-base/spacing.css';
import './styles/01-base/reset.css';
import './styles/01-base/typography.css';

// 02 - Layout (grid, header, navigation, main content)
import './styles/02-layout/layout.css';
import './styles/02-layout/header.css';

// 03 - Components (reusable UI components)
import './styles/03-components/button.css';
import './styles/03-components/cards-shared.css';
import './styles/03-components/card.css';
import './styles/03-components/dropdown.css';
import './styles/03-components/form.css';
import './styles/03-components/article.css';
import './styles/03-components/modal.css';
import './styles/03-components/notice.css';
import './styles/03-components/spinner.css';
import './styles/03-components/a2hs.css';
import './styles/03-components/og.css';
import './styles/03-components/nostr-previews.css';
import './styles/reading-lists.css';
import './styles/03-components/nip05-badge.css';
import './styles/03-components/picture-event.css';
import './styles/03-components/video-event.css';
import './styles/03-components/search.css';
import './styles/03-components/image-upload.css';
import './styles/03-components/zaps.css';

// Editor layout
import './styles/editor-layout.css';

// 04 - Page-specific styles
import './styles/04-pages/landing.css';
import './styles/04-pages/admin.css';
import './styles/04-pages/analytics.css';
import './styles/04-pages/author-media.css';
import './styles/04-pages/forum.css';
import './styles/04-pages/highlights.css';
import './styles/04-pages/discover.css';

// 05 - Utilities (last for highest specificity)
import './styles/05-utilities/utilities.css';

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');

import 'katex/dist/katex.min.css';
import renderMathInElement from 'katex/dist/contrib/auto-render.mjs';

// Detect math blocks in text content while avoiding common currency patterns
function hasRealMath(text) {
  if (!text) return false;
  const t = text;

  // Skip plain currency-like $123.45 or $ 1,234 patterns
  const currency = /\$\s*[\d.,]+(?:\b|\s)/g;

  // Detect LaTeX-style delimiters with likely math content inside
  const inlineDollar = /\$(?<body>(?:[^$\\]|\\.)+)\$/g; // $ ... $
  const doubleDollar = /\$\$(?<body>(?:[^$\\]|\\.)+)\$\$/g; // $$ ... $$
  const parenMath = /\\\((?<body>(?:[^\\]|\\.)+)\\\)/g; // \( ... \)
  const bracketMath = /\\\[(?<body>(?:[^\\]|\\.)+)\\\]/g; // \[ ... \]

  // Heuristic: content contains typical math tokens (command, ^, _, { }, fractions)
  const looksMathy = (s) => /\\[a-zA-Z]+|[\^_]|[{}]|\\frac|\\sum|\\int|\\lim|\\alpha|\\beta|\\gamma|\\rightarrow|\\matrm|\\mathrm|\\mathbb|\\mathbf/.test(s);

  // If text has only currency-like $... patterns and no delimiters, don't mark as math
  const hasNonCurrencyDollar = (() => {
    let m;
    // Any $...$ that isn't just numbers
    const dollarAny = /\$(?:[^$]+)\$/g;
    while ((m = dollarAny.exec(t)) !== null) {
      const inner = m[0].slice(1, -1);
      if (!/^\s*[\d.,]+\s*$/.test(inner) && looksMathy(inner)) return true;
    }
    return false;
  })();

  // Check each delimiter type
  const checkDelim = (regex) => {
    let m;
    while ((m = regex.exec(t)) !== null) {
      const inner = m.groups?.body ?? '';
      if (looksMathy(inner)) return true;
    }
    return false;
  };

  if (checkDelim(doubleDollar)) return true;
  if (checkDelim(parenMath)) return true;
  if (checkDelim(bracketMath)) return true;
  if (hasNonCurrencyDollar) return true;

  // Also allow $...$ where the inner isn't currency and includes letters with math markers
  const inlineDollarLoose = /\$(?<body>[^$]+)\$/g;
  let m;
  while ((m = inlineDollarLoose.exec(t)) !== null) {
    const inner = m.groups?.body ?? '';
    if (!/^\s*[\d.,]+\s*$/.test(inner) && /[A-Za-z]/.test(inner) && /[\^_{}]|\\[a-zA-Z]+/.test(inner)) return true;
  }

  return false;
}

document.addEventListener('DOMContentLoaded', () => {
  // Identify containers that may include math and add the .math class when detected
  const root = document.querySelector('.article-main'); // main article container
  const summaries = document.querySelectorAll('.lede'); // article summaries

  if (root && hasRealMath(root.textContent || '')) {
    root.classList.add('math');
  }
  if (summaries && summaries.length) {
    summaries.forEach((summary) => {
      if (summary && hasRealMath(summary.textContent || '')) {
        summary.classList.add('math');
      }
    });
  }

  // Render KaTeX inside elements marked with .math
  const mathRoot = document.querySelector('.article-main.math');
  const mathSummaries = document.querySelectorAll('.lede.math');

  if (mathSummaries && mathSummaries.length) {
    mathSummaries.forEach((summary) => {
      renderMathInElement(summary, {
        delimiters: [
          { left: '$$', right: '$$', display: false },
          { left: '$',  right: '$',  display: false },
        ],
        throwOnError: false, // don’t explode on unknown commands
      });
    });
  }

  if (mathRoot) {
    renderMathInElement(mathRoot, {
      // Delimiters: inline $…$, display $$…$$ and the LaTeX \(…\)/\[…\] forms
      delimiters: [
        { left: '$$', right: '$$', display: true },
        { left: '$',  right: '$',  display: false },
        { left: '\\[', right: '\\]', display: true },
      ],
      throwOnError: false, // don’t explode on unknown commands
    });
  }
});
