# phporbit brand assets

## The idea

**Two crossed orbits, one core.**

The rings are the framework's two process models — per-request (Apache, nginx+FPM) and long-lived worker (the built-in server, FrankenPHP). The core is the single application that runs unchanged in both. The rings pass *behind* the core because the application never has to know which one it is in.

That is the framework's defining constraint, so the mark says something rather than decorating. The wordmark's circular bowls echo the same geometry.

## Files

| File | Use |
| --- | --- |
| `phporbit-mark.svg` | The mark alone. Gradient. Primary icon. |
| `phporbit-mark-mono.svg` | Single colour via `currentColor`. Print, embroidery, engraving, busy backgrounds. |
| `phporbit-logo.svg` | Mark + wordmark. Wordmark inherits `currentColor`, so one file serves both themes. |
| `phporbit-logo-on-dark.svg` | Fixed light wordmark, for contexts that cannot set a colour. |
| `phporbit-logo-on-light.svg` | Fixed dark wordmark, same reason. |
| `phporbit-social-card.png` | 1200×630 link preview (Open Graph / Twitter card). |
| `phporbit-mark-512.png`, `phporbit-mark-mono-*.png` | Raster mark, where SVG is not accepted. |
| `phporbit-logo-on-*.png` | Raster lockups. |
| `../public/favicon.svg`, `../public/favicon.ico`, `../public/icons/` | Shipped with the app. |

**The SVGs are the masters.** Every PNG and the `.ico` are generated from `render.py`; do not hand-edit them.

## Regenerating

```bash
python3 brand/render.py
```

This writes every PNG, the `.ico`, the two fixed-colour lockups, and copies the served vectors into `public/assets/brand/`.

`render.py` redraws the geometry with Pillow rather than rasterising the SVGs. Inkscape here is a broken snap, and ImageMagick's internal renderer silently drops rotated strokes and fills gradients with black — both were tried. Chrome (below) can rasterise, so switching the pipeline to convert the SVGs directly is a viable improvement; the Pillow path is what exists today.

**If you change a shape, change it in `render.py` and in `phporbit-logo.svg` / `phporbit-mark.svg`.** Nothing enforces that they agree. The fixed-colour lockups *are* derived — they are rewritten from the adaptive master on every run, so don't edit them.

## Verifying

The SVGs and the PNGs are drawn by two different pieces of code, so they can silently disagree. Render both and look:

```bash
google-chrome --headless --disable-gpu --screenshot=/tmp/check.png \
  --window-size=760,220 --default-background-color=10131aff \
  "file://$PWD/brand/phporbit-logo.svg"
```

This is worth doing after any geometry change. It is how the gradient-offset bug was found: `gradientUnits="userSpaceOnUse"` resolves against the user space where the gradient is *referenced*, so coordinates inside a translated group must be in that group's local space. The lockup's gradient was written in lockup coordinates and rendered 11 units off, leaving the mark almost entirely blue — invisible in the PNG, which is drawn by different code.

## Colours

| Token | Hex | Use |
| --- | --- | --- |
| Blue | `#58a6ff` | Gradient start, primary satellite, accents |
| Violet | `#a371f7` | Gradient end, secondary satellite |
| Ink (dark) | `#10131a` | Background on dark; wordmark on light |
| Ink (light) | `#e6e9ef` | Wordmark on dark |
| Muted | `#98a2b8` / `#687082` | The "php" half of the wordmark, on dark / on light |

The gradient runs diagonally, from `(6,14)` to `(58,50)` in the mark's 64-unit box. These are the same tokens the demo application's stylesheet uses, so the site and the brand are one system rather than two that happen to look similar.

## Using it well

- **Clear space:** keep a margin of at least the core's diameter (a quarter of the mark's height) on all sides. The lockup SVGs already include it.
- **Minimum sizes:** mark 24px, lockup 120px wide. Below 24px use `favicon.svg`, which is a deliberately different drawing — one ring instead of two and a much heavier stroke, because at tab size the second ring turns the centre into a smudge.
- **On photographs or busy backgrounds:** use the monochrome mark, not the gradient.
- **Don't** re-colour the gradient, add effects, stretch it non-uniformly, rebuild the wordmark in a font, or set the mark on a background that leaves less than 4.5:1 contrast.

## Why the wordmark is drawn rather than set in a typeface

A logo whose shapes depend on which fonts the viewer happens to have installed is not a logo. Every glyph here is built from circles and stems on one grid: 5-unit strokes, bowls of radius 9.5 centred on the x-height, 44-unit body from ascender to descender. Extending it means staying on that grid.

"php" is muted and "orbit" is full strength — the name reads as one word while showing what it is made of.
