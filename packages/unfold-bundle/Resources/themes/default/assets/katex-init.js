/**
 * KaTeX auto-render initializer with currency-safe heuristic.
 *
 * Checks whether the page actually contains LaTeX math before calling
 * renderMathInElement, preventing dollar-sign currency values (e.g. $50,000)
 * from being swallowed by the math renderer.
 */
(function () {
    'use strict';

    /**
     * Heuristic: does the text contain real LaTeX math (not just currency)?
     * Mirrors the logic in assets/controllers/utility/katex_controller.js.
     */
    function hasRealMath(text) {
        if (!text) return false;

        var looksMathy = function (s) {
            return /\\[a-zA-Z]+|[\^_]|[{}]|\\frac|\\sum|\\int|\\lim|\\alpha|\\beta|\\gamma|\\rightarrow|\\mathrm|\\mathbb|\\mathbf/.test(s);
        };

        // Check $…$ pairs that aren't plain currency
        var dollarAny = /\$([^$]+)\$/g;
        var m;
        while ((m = dollarAny.exec(text)) !== null) {
            var inner = m[0].slice(1, -1);
            // Skip pure numeric/currency values like 50,000
            if (/^\s*[\d.,]+\s*$/.test(inner)) continue;
            if (looksMathy(inner)) return true;
        }

        // Check $$…$$ blocks
        var doubleDollar = /\$\$([^$\\]|\\.)+\$\$/g;
        if (doubleDollar.test(text)) return true;

        // Check \(…\) inline
        var parenDelim = /\\\((?:[^\\]|\\.)+\\\)/g;
        if (parenDelim.test(text)) return true;

        // Check \[…\] display
        var bracketDelim = /\\\[(?:[^\\]|\\.)+\\]/g;
        if (bracketDelim.test(text)) return true;

        // Inline $…$ with whitespace rule: opening $ not followed by space,
        // closing $ not preceded by space — the TeX convention that separates
        // math from currency.
        var inlineDollarWs = /(?<![\\$\d])\$([^\s$](?:[^$]*[^\s$])?)\$(?![\d$])/g;
        while ((m = inlineDollarWs.exec(text)) !== null) {
            if (!/^\s*[\d.,]+\s*$/.test(m[1] || '')) return true;
        }

        return false;
    }

    /** Tags whose text nodes should not be touched by dollar normalization. */
    var SKIP_TAGS = /^(pre|code|script|style|textarea|annotation|svg|math)$/i;

    /**
     * Walk text nodes inside `root` and convert $…$ to \(…\) when the
     * content looks like math.  Mirrors the server-side pipeline and
     * handles cached HTML from before the placeholder system.
     */
    function normalizeDollarMathInTextNodes(root) {
        var re = /(?<![\\$\d])\$([^\s$](?:[^$]*?[^\s$])?)\$(?![\d$])/g;
        var walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
        var replacements = [];
        var node;

        while ((node = walker.nextNode())) {
            if (node.parentNode && SKIP_TAGS.test(node.parentNode.tagName)) continue;
            var text = node.nodeValue;
            if (!text || text.indexOf('$') === -1) continue;

            var newText = text.replace(re, function (_match, inner) {
                if (/^\s*[\d.,]+\s*$/.test(inner)) return _match;
                return '\\(' + inner + '\\)';
            });

            if (newText !== text) {
                replacements.push({ node: node, newText: newText });
            }
        }

        for (var i = 0; i < replacements.length; i++) {
            replacements[i].node.nodeValue = replacements[i].newText;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        if (typeof renderMathInElement !== 'function') return;
        if (!hasRealMath(document.body.textContent || '')) return;

        // Normalize any surviving $…$ inline math to \(…\) before KaTeX runs
        normalizeDollarMathInTextNodes(document.body);

        renderMathInElement(document.body, {
            delimiters: [
                { left: '$$', right: '$$', display: true },
                { left: '\\[', right: '\\]', display: true },
                { left: '\\(', right: '\\)', display: false }
            ],
            throwOnError: false,
            // Allow math inside inline <code> (Nostr convention: `$...$`).
            // Keep <pre> ignored so fenced code blocks are not processed.
            ignoredTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'annotation']
        });
    });
})();

