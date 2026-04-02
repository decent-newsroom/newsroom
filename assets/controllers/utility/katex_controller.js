import { Controller } from "@hotwired/stimulus";
import renderMathInElement from 'katex/dist/contrib/auto-render.mjs';

/**
 * Stimulus controller that renders KaTeX math expressions.
 *
 * Unlike a DOMContentLoaded listener this fires every time the element
 * enters the DOM — including after Turbo navigations and inside a PWA.
 *
 * Usage:
 *   <div data-controller="utility--katex"
 *        data-utility--katex-display-value="true">
 *     Content with $x^2$ math…
 *   </div>
 *
 * The optional `display` value controls whether $$…$$ blocks render in
 * display mode (default true for articles, false for summaries/cards).
 */

// Heuristic: does the text contain real LaTeX math (not just currency)?
function hasRealMath(text) {
    if (!text) return false;
    const t = text;

    const looksMathy = (s) =>
        /\\[a-zA-Z]+|[\^_]|[{}]|\\frac|\\sum|\\int|\\lim|\\alpha|\\beta|\\gamma|\\rightarrow|\\mathrm|\\mathbb|\\mathbf/.test(s);

    // Check for $…$ that isn't plain currency
    const hasNonCurrencyDollar = (() => {
        let m;
        const dollarAny = /\$([^$]+)\$/g;
        while ((m = dollarAny.exec(t)) !== null) {
            const inner = m[0].slice(1, -1);
            if (!/^\s*[\d.,]+\s*$/.test(inner) && looksMathy(inner)) return true;
        }
        return false;
    })();

    // Unambiguous delimiters — their presence alone is a strong math signal.
    // No looksMathy gate: nobody writes \(…\), \[…\], or $$…$$ for currency.
    if (/\$\$(?:[^$\\]|\\.)+\$\$/g.test(t)) return true;
    if (/\\\((?:[^\\]|\\.)+\\\)/g.test(t)) return true;
    if (/\\\[(?:[^\\]|\\.)+\\]/g.test(t)) return true;

    // Ambiguous single-$ — only trust it if the content looks like real LaTeX.
    if (hasNonCurrencyDollar) return true;

    // Inline $…$ with whitespace rule: opening $ not followed by space,
    // closing $ not preceded by space — the TeX convention that separates
    // math from currency.
    const inlineDollarWs = /(?<![\\$\d])\$([^\s$](?:[^$]*[^\s$])?)\$(?![\d$])/g;
    let m;
    while ((m = inlineDollarWs.exec(t)) !== null) {
        if (!/^\s*[\d.,]+\s*$/.test(m[1])) return true;
    }

    return false;
}

export default class extends Controller {
    static values = {
        display: { type: Boolean, default: true },   // $$…$$ → display mode?
    };

    connect() {
        if (!hasRealMath(this.element.textContent || '')) return;

        this.element.classList.add('math');

        const delimiters = this.displayValue
            ? [
                { left: '$$', right: '$$', display: true },
                { left: '\\(', right: '\\)', display: false },
                { left: '\\[', right: '\\]', display: true },
            ]
            : [
                { left: '$$', right: '$$', display: false },
                { left: '\\(', right: '\\)', display: false },
            ];

        renderMathInElement(this.element, {
            delimiters,
            throwOnError: false,
        });
    }
}
