# Design tokens

## Status

Introduced 2026-09-26. Additive and non-breaking: the tokens are **aliases** for palette steps the
app already uses, so `bg-canvas` and `bg-slate-950` compile to the same declaration and a screen that
never adopts a token keeps working unchanged. No component was rewritten to introduce them.

Defined in [`resources/css/app.css`](../../resources/css/app.css) inside the existing Tailwind v4
`@theme` block, which is where this project's CSS-first configuration already lives. Nothing new was
added to the build.

## Why

Colour was chosen per component. Across `resources/js` the same handful of palette steps appear
between tens and hundreds of times each, written out in full every time:

| Utility | Uses | Role it plays |
|---|---|---|
| `text-slate-400` | 238 | secondary text |
| `text-slate-100` | 215 | primary text |
| `text-slate-500` | 211 | muted text |
| `border-slate-800` | 166 | subtle divider |
| `border-slate-700` | 154 | ordinary border |
| `bg-slate-950` | 148 | page canvas |
| `bg-slate-900` | 138 | card / panel surface |
| `text-emerald-400` | 132 | accent text, settled figures |
| `bg-slate-800` | 97 | raised / hover surface |
| `text-rose-400` | 58 | failure, shortage |
| `bg-emerald-500` | 58 | primary action fill |
| `text-amber-400` | 37 | caution, pending, withheld |

That is not a problem of taste — the palette is consistent and came from the two Stitch design
systems (`TindaFlow Industrial Retail Back Office`, `Industrial POS Terminal System`). It is a
problem of **leverage**: deciding that "muted text" should be one step lighter is currently a
211-place edit with no way to be sure the set is complete.

## The tokens

Grouped by role, not by hue. Every value was read out of the components; none was invented here.

### Surfaces
| Token | Alias of | Use for |
|---|---|---|
| `canvas` | `slate-950` | the page behind everything |
| `surface` | `slate-900` | cards, panels, the sidebar |
| `surface-raised` | `slate-800` | hover, selected rows, raised chips |

### Lines
| Token | Alias of | Use for |
|---|---|---|
| `line-subtle` | `slate-800` | dividers inside a surface |
| `line` | `slate-700` | the ordinary border of a control |
| `line-strong` | `slate-600` | a border that must be seen |

### Text
| Token | Alias of | Use for |
|---|---|---|
| `ink` | `slate-100` | primary text, values that matter |
| `ink-soft` | `slate-300` | secondary body text |
| `ink-muted` | `slate-400` | labels, captions |
| `ink-faint` | `slate-500` | metadata, timestamps |
| `ink-ghost` | `slate-600` | placeholders, disabled |
| `ink-inverse` | `slate-950` | text **on** an accent fill |

### Accent — emerald
The primary action, and the colour of a figure that balances.

| Token | Alias of |
|---|---|
| `accent` | `emerald-500` |
| `accent-hover` | `emerald-400` |
| `accent-ink` | `emerald-400` |
| `accent-line` | `emerald-700` |
| `accent-tint` | `emerald-950` |

`accent-hover` and `accent-ink` share a value deliberately: one is a fill state, the other is text on
a dark surface. They are separate names so they can stop sharing without a hunt.

### Danger — rose
Failure, destruction, a shortage. See the convergence note below.

| Token | Alias of |
|---|---|
| `danger` | `rose-400` |
| `danger-line` | `rose-800` |
| `danger-tint` | `rose-950` |

### Caution — amber
A state that needs attention but is not a failure: pending approval, blocked setup, a cash variance,
a figure withheld during an open shift.

| Token | Alias of |
|---|---|
| `warn` | `amber-400` |
| `warn-line` | `amber-700` |
| `warn-tint` | `amber-950` |

## Known inconsistency: two reds

The app uses **both** `rose-*` and `red-*` for the same idea. Measured on 2026-09-26:

- `rose-*` — the majority, and what the tokens alias.
- `red-*` — **47 utilities across 12 files**: `text-red-300` (14), `bg-red-500/10` (12),
  `text-red-400` (5), `border-red-800` (5), `bg-red-950` (5), `border-red-500/30` (4),
  `ring-red-500` (1), `border-red-500` (1).

The split runs by area rather than by meaning: the POS screens and the older Store Setup admin
screens use `red-*`; the records, reports and catalog screens use `rose-*`. Both render as "this went
wrong", and the difference is visible when a POS error sits next to an admin one.

**Not converged in this change, on purpose.** It touches 12 files including `Pos.jsx` and the POS
panels, which are under active concurrent work; a mechanical 47-site sweep landing on top of that is
how a clean-looking merge turns into a broken one. It is recorded here with its exact scope so the
sweep can be done deliberately, in one commit, when those files are quiet.

## How to use them

In new or changed code, prefer the token:

```jsx
// instead of
<div className="bg-slate-900 border border-slate-800 text-slate-400">
// write
<div className="bg-surface border border-line-subtle text-ink-muted">
```

Existing code is not required to change. Migrating a screen is safe to do opportunistically — the
rendered output is identical, so a token migration should never produce a visual diff. If it does,
the utility being replaced was not playing the role its name claims, which is worth knowing.

Two rules worth keeping:

1. **Do not add a raw palette step for a role that has a token.** If a new role appears, add a token
   for it here rather than reaching for `slate-750`-style one-offs.
2. **Colour stays functional.** Both Stitch design systems state it explicitly: green means balanced,
   settled, executed; amber means attention or a deficit; rose means failure. Nothing decorative.

## Not included

- **No spacing, radius or type-scale tokens.** Both Stitch design systems define them, but the app
  uses Tailwind's defaults consistently and there is no measured inconsistency to solve. Adding them
  would be configuration for its own sake.
- **No light theme.** The app is dark throughout; the only white surfaces are invoice previews
  (`InvoicePanel`, `StoreSettingsPage`), which are white because a receipt is paper, not because of a
  theme.
