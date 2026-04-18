# Writing Articles with Math

## Overview

You can include mathematical formulas in your articles using standard LaTeX notation. Both inline math (within a sentence) and display math (standalone, centered blocks) are supported. Formulas are rendered in the browser using KaTeX.

## Inline Math

Inline math appears within the flow of your text. Wrap your expression in single dollar signs or `\(…\)` delimiters:

```
The famous equation $E=mc^2$ changed physics forever.
```

or equivalently:

```
The famous equation \(E=mc^2\) changed physics forever.
```

**Result:** The famous equation *E=mc²* changed physics forever.

### More inline examples

| You write | Renders as |
|-----------|------------|
| `$x^2 + y^2 = r^2$` | x² + y² = r² |
| `$\alpha + \beta = \gamma$` | α + β = γ |
| `$\frac{a}{b}$` | a/b (fraction) |
| `$\sqrt{2}$` | √2 |

## Display Math

Display math is rendered as a centered block on its own line. Wrap your expression in double dollar signs or `\[…\]` delimiters:

```
The quadratic formula is:

$$x = \frac{-b \pm \sqrt{b^2 - 4ac}}{2a}$$
```

or equivalently:

```
The quadratic formula is:

\[x = \frac{-b \pm \sqrt{b^2 - 4ac}}{2a}\]
```

### More display examples

**Summation:**
```
$$\sum_{i=1}^{n} i = \frac{n(n+1)}{2}$$
```

**Integral:**
```
$$\int_0^{\infty} e^{-x^2} \, dx = \frac{\sqrt{\pi}}{2}$$
```

**Matrix:**
```
$$\begin{pmatrix} a & b \\ c & d \end{pmatrix}$$
```

## Tips and Pitfalls

### Do: Leave blank lines around display math

Put `$$…$$` blocks on their own line, separated from surrounding text by blank lines. This ensures the Markdown parser treats them correctly.

```
Some text before.

$$\int_0^1 x^2 \, dx = \frac{1}{3}$$

Some text after.
```

### Don't: Add spaces after the opening `$`

The opening `$` must be immediately followed by your expression (no space), and the closing `$` must immediately follow it (no space). This rule prevents currency values like `$100` from being misinterpreted as math.

```
✅  $x^2$
❌  $ x^2 $
```

### Currency is safe

Plain dollar amounts like `$19.99` or `$100` are **not** converted to math. The renderer only activates when it detects LaTeX-like syntax (commands, superscripts, subscripts, braces, etc.).

### Code blocks are safe

Math inside fenced code blocks (triple backticks) is never rendered — it will display as raw text, which is what you want when showing LaTeX source.

## Converter Edge Cases

The server-side converter processes your Markdown before it reaches the browser. Here are some behaviors to be aware of:

### Backtick-wrapped math is unwrapped automatically

Many Nostr clients wrap math in backticks to prevent it from being mangled (e.g. `` `$E=mc^2$` `` or `` `$$\frac{1}{2}$$` ``). The converter detects this and unwraps it — but **only if the content looks like math** (contains LaTeX commands, `^`, `_`, `{}`). If your expression is too simple (e.g. `` `$x$` ``), it may stay as inline code instead of rendering as math.

**Workaround:** For very simple expressions, skip the backticks and write `$x$` directly, or add a hint like `$x^{}$` to make it look more like math.

### Multi-line display math must contain LaTeX commands

Single-line `$$…$$` is always extracted as display math. However, if your `$$…$$` block spans multiple lines (contains blank lines), the converter requires it to contain recognizable LaTeX markup (commands like `\frac`, `\begin`, or operators like `^`, `_`, `\\`). Plain text between `$$` across paragraphs will **not** be treated as math — this prevents false matches with stray `$$` in non-math content.

**Workaround:** Keep display math on a single line when possible. If you need multi-line math, use LaTeX environments:

```
$$
\begin{aligned}
  a &= b + c \\
  d &= e + f
\end{aligned}
$$
```

### The `$` whitespace rule

The opening `$` must not be followed by a space, and the closing `$` must not be preceded by a space. This is by design — it's how math is distinguished from currency. But it means expressions with leading/trailing spaces won't render:

```
✅  $x + y$
❌  $ x + y $
```

### Inline `$…$` is converted to `\(…\)` internally

The converter rewrites `$…$` to `\(…\)` before passing content to the Markdown parser (CommonMark escapes backslashes, so this is done via placeholders). This is invisible to you, but it means if you mix `$…$` and `\(…\)` in the same expression, you may get unexpected results. **Pick one delimiter style and stick with it.**

### Nested dollar signs don't work

The `$…$` regex is non-greedy and non-nested. If you need literal dollar signs inside math, use `\(…\)` delimiters instead, or use `\$` inside the expression:

```
✅  \(\text{Price} = \$50\)
❌  $\text{Price} = $50$
```

### `$$` in code (e.g. JavaScript template literals)

If you write JavaScript examples containing `$${variable}`, wrap them in fenced code blocks. The converter protects fenced code blocks from math extraction. Inline code (single backticks) is also protected.

## Quick Reference

| Goal | Syntax |
|------|--------|
| Inline math | `$...$` or `\(...\)` |
| Display math | `$$...$$` or `\[...\]` |
| Fraction | `\frac{numerator}{denominator}` |
| Superscript | `x^2` or `x^{2n}` |
| Subscript | `x_i` or `x_{i+1}` |
| Square root | `\sqrt{expression}` |
| Greek letters | `\alpha`, `\beta`, `\gamma`, `\pi`, `\Sigma`, etc. |
| Summation | `\sum_{i=1}^{n}` |
| Integral | `\int_a^b` |
| Limit | `\lim_{x \to \infty}` |
| Bold math | `\mathbf{v}` |
| Blackboard bold | `\mathbb{R}` |
| Text in math | `\text{some words}` |

## Full KaTeX Reference

For the complete list of supported functions and symbols, see the [KaTeX documentation](https://katex.org/docs/supported).
