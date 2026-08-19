#!/usr/bin/env python3
"""Rasterises the phporbit brand assets.

The SVG sources are the masters; this script exists because the machine has no
working SVG rasteriser (Inkscape is a broken snap, and ImageMagick's internal
renderer drops rotated strokes and gradient fills). It redraws the *same*
geometry with Pillow so the PNG and ICO deliverables stay in step with the
vectors.

If you change a number here, change it in the matching SVG too. The shared
constants below are the single description of the shapes; both outputs are
derived from them.

Usage:  python3 brand/render.py
"""

from __future__ import annotations

import math
import os
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
BRAND = os.path.join(ROOT, "brand")
PUBLIC = os.path.join(ROOT, "public")
ICONS = os.path.join(PUBLIC, "icons")

# Supersampling factor. Pillow has no analytic antialiasing, so everything is
# drawn large and reduced with a good filter.
SS = 8

BLUE = (88, 166, 255)
VIOLET = (163, 113, 247)
INK_DARK = (16, 19, 26)      # app background
INK_LIGHT = (230, 233, 239)  # app foreground
MUTED_ON_DARK = (152, 162, 184)
MUTED_ON_LIGHT = (104, 112, 130)

# --- mark geometry, in a 64x64 box -----------------------------------------
MARK_BOX = 64
CENTER = 32.0
RING_RX, RING_RY = 27.0, 11.5
RING_TILT = 28.0
RING_STROKE = 3.25
CORE_R = 7.5
SAT_A = (55.84, 19.32, 4.5)   # on the -28 ring, top right
SAT_B = (8.16, 44.68, 3.0)    # same ring, diagonally opposite


def linear_gradient(size: tuple[int, int], start_rgb, end_rgb) -> Image.Image:
    """A diagonal gradient across the box, matching the SVG's gradient vector."""
    width, height = size
    image = Image.new("RGB", size)
    pixels = image.load()

    # The SVG runs the gradient from (6,14) to (58,50) in mark units.
    x0, y0 = 6 / MARK_BOX * width, 14 / MARK_BOX * height
    x1, y1 = 58 / MARK_BOX * width, 50 / MARK_BOX * height
    dx, dy = x1 - x0, y1 - y0
    span = dx * dx + dy * dy

    for y in range(height):
        for x in range(width):
            t = ((x - x0) * dx + (y - y0) * dy) / span
            t = min(1.0, max(0.0, t))
            pixels[x, y] = tuple(
                round(start_rgb[i] + (end_rgb[i] - start_rgb[i]) * t) for i in range(3)
            )

    return image


def ring_mask(size: int, tilt: float, rx: float, ry: float, stroke: float) -> Image.Image:
    """One orbit ring, drawn flat then rotated about the centre."""
    scale = size / MARK_BOX
    layer = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(layer)

    cx = cy = CENTER * scale
    draw.ellipse(
        [cx - rx * scale, cy - ry * scale, cx + rx * scale, cy + ry * scale],
        outline=255,
        width=max(1, round(stroke * scale)),
    )

    # Pillow rotates counter-clockwise; SVG's rotate() is clockwise.
    return layer.rotate(-tilt, resample=Image.BICUBIC, center=(cx, cy))


def draw_mark(size: int, *, simplified: bool = False, mono=None) -> Image.Image:
    """The mark. `simplified` is the favicon build: one ring, heavier strokes."""
    big = size * SS
    scale = big / MARK_BOX

    mask = Image.new("L", (big, big), 0)

    if simplified:
        # Rounder and heavier than the full mark. At 16px a ry of 11.5 collapses
        # into a sliver and the whole thing reads as a smudge; opening the ring
        # up and thickening the stroke is what keeps it legible in a tab strip.
        rings = [(-RING_TILT, 24.0, 17.0, 6.0)]
        core_r, sat = 9.0, (53.19, 20.73, 6.0)
    else:
        rings = [(-RING_TILT, RING_RX, RING_RY, RING_STROKE),
                 (RING_TILT, RING_RX, RING_RY, RING_STROKE)]
        core_r, sat = CORE_R, None

    for tilt, rx, ry, stroke in rings:
        mask = Image.composite(
            Image.new("L", (big, big), 255), mask, ring_mask(big, tilt, rx, ry, stroke)
        )

    draw = ImageDraw.Draw(mask)
    cx = cy = CENTER * scale
    draw.ellipse(
        [cx - core_r * scale, cy - core_r * scale, cx + core_r * scale, cy + core_r * scale],
        fill=255,
    )

    if mono is not None:
        body = Image.new("RGB", (big, big), mono)
    else:
        body = linear_gradient((big, big), BLUE, VIOLET)

    image = Image.new("RGBA", (big, big), (0, 0, 0, 0))
    image.paste(body, (0, 0), mask)

    # Satellites sit on top so they stay solid rather than picking up the
    # gradient mid-ramp, which is what gives the mark its focal point.
    overlay = ImageDraw.Draw(image)
    satellites = [sat] if sat else [SAT_A + (BLUE,), SAT_B + (VIOLET,)]

    for entry in satellites:
        if len(entry) == 3:
            x, y, r = entry
            colour = BLUE
        else:
            x, y, r, colour = entry
        if mono is not None:
            colour = mono
        overlay.ellipse(
            [(x - r) * scale, (y - r) * scale, (x + r) * scale, (y + r) * scale],
            fill=colour + (255,),
        )

    return image.resize((size, size), Image.LANCZOS)


# --- wordmark ---------------------------------------------------------------
# A geometric lowercase alphabet built from circles and stems, so the letters
# echo the orbits in the mark. Drawn rather than set in a typeface: a logo that
# reshapes itself depending on which fonts the viewer has installed is not a
# logo. Units share the mark's 64-box scale.

ASCENDER, XTOP, BASELINE, DESCENDER = 0.0, 10.0, 34.0, 44.0
STEM = 5.0
BOWL_R = 9.5
BOWL_CX = 12.0
BOWL_CY = 22.0
TRACKING = 5.0

LETTERS = {
    "p": 24.0, "h": 24.0, "o": 24.0, "r": 23.0, "b": 24.0, "i": 5.0, "t": 14.0,
}


def draw_letter(draw: ImageDraw.ImageDraw, letter: str, ox: float, s: float, colour) -> None:
    """Draws one glyph at x-offset `ox`, scaled by `s`."""
    w = max(1, round(STEM * s))
    half = STEM / 2

    def line(x0, y0, x1, y1):
        draw.line([(ox + x0) * s, y0 * s, (ox + x1) * s, y1 * s], fill=colour, width=w)

    # Pillow strokes arcs and ellipses *inward* from the bounding box, while
    # lines are centred on their path and SVG centres everything. Expanding the
    # box by half the stroke puts the curve's centreline where the SVG puts it,
    # so bowls meet stems cleanly and both outputs describe the same shape.
    bleed = STEM / 2

    def circle(cx, cy, r):
        rr = r + bleed
        draw.ellipse(
            [(ox + cx - rr) * s, (cy - rr) * s, (ox + cx + rr) * s, (cy + rr) * s],
            outline=colour, width=w,
        )

    def arc(cx, cy, r, start, end):
        rr = r + bleed
        draw.arc(
            [(ox + cx - rr) * s, (cy - rr) * s, (ox + cx + rr) * s, (cy + rr) * s],
            start, end, fill=colour, width=w,
        )

    if letter == "p":
        line(half, XTOP, half, DESCENDER)
        circle(BOWL_CX, BOWL_CY, BOWL_R)
    elif letter == "b":
        line(half, ASCENDER, half, BASELINE)
        circle(BOWL_CX, BOWL_CY, BOWL_R)
    elif letter == "o":
        circle(BOWL_CX, BOWL_CY, BOWL_R)
    elif letter == "h":
        line(half, ASCENDER, half, BASELINE)
        arc(BOWL_CX, BOWL_CY, BOWL_R, 180, 360)
        line(BOWL_CX + BOWL_R, BOWL_CY, BOWL_CX + BOWL_R, BASELINE)
    elif letter == "r":
        line(half, XTOP, half, BASELINE)
        # Over the top and down to the upper right. Stopping near the apex
        # leaves a stub that reads as a damaged "i" rather than an "r".
        arc(BOWL_CX, BOWL_CY, BOWL_R, 180, 335)
    elif letter == "i":
        line(half, XTOP, half, BASELINE)
        dot = 2.5
        draw.ellipse(
            [(ox + half - dot) * s, (3 - dot) * s, (ox + half + dot) * s, (3 + dot) * s],
            fill=colour,
        )
    elif letter == "t":
        line(7, 2, 7, BASELINE)
        line(0, 11, 14, 11)


def wordmark_width() -> float:
    word = "phporbit"
    return sum(LETTERS[c] for c in word) + TRACKING * (len(word) - 1)


def draw_wordmark(height_px: int, strong, muted) -> Image.Image:
    """Renders "phporbit"; "php" muted, "orbit" strong."""
    s = (height_px * SS) / (DESCENDER - ASCENDER)
    width = round(wordmark_width() * s)
    big = Image.new("RGBA", (width, round(DESCENDER * s)), (0, 0, 0, 0))
    draw = ImageDraw.Draw(big)

    x = 0.0
    for index, letter in enumerate("phporbit"):
        draw_letter(draw, letter, x, s, (muted if index < 3 else strong) + (255,))
        x += LETTERS[letter] + TRACKING

    return big.resize(
        (round(width / SS), round(DESCENDER * s / SS)), Image.LANCZOS
    )


def lockup(height_px: int, strong, muted, background=None) -> Image.Image:
    """Mark plus wordmark, optically aligned."""
    mark_size = round(height_px * 1.45)
    mark = draw_mark(mark_size)
    word = draw_wordmark(height_px, strong, muted)

    gap = round(height_px * 0.42)
    pad = round(height_px * 0.25)
    width = mark.width + gap + word.width + pad * 2
    height = mark.height + pad * 2

    canvas = Image.new("RGBA", (width, height), background or (0, 0, 0, 0))
    canvas.alpha_composite(mark, (pad, pad))

    # The wordmark's optical centre sits above its box centre because of the
    # descender on "p"; align on the x-height band instead.
    x_mid = (XTOP + BASELINE) / 2 / DESCENDER * word.height
    canvas.alpha_composite(word, (pad + mark.width + gap, round(height / 2 - x_mid)))

    return canvas


def social_card() -> Image.Image:
    """1200x630 preview card for link unfurls."""
    card = Image.new("RGBA", (1200, 630), INK_DARK + (255,))

    # An oversized mark bleeding off the right edge. It fills what would
    # otherwise be a dead half, and cropping it is what stops the card reading
    # as a slide with a logo parked in the corner.
    ghost = draw_mark(620)
    ghost.putalpha(ghost.getchannel("A").point(lambda v: round(v * 0.22)))
    # Far enough right that its lower satellite clears the subtitle; the text
    # has to stay the most legible thing on the card.
    card.alpha_composite(ghost, (812, 22))

    draw = ImageDraw.Draw(card)

    mark = draw_mark(150)
    card.alpha_composite(mark, (96, 96))

    word = draw_wordmark(74, INK_LIGHT, MUTED_ON_DARK)
    card.alpha_composite(word, (96, 300))

    try:
        font = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 30)
        small = ImageFont.truetype("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 23)
    except OSError:
        font = small = ImageFont.load_default()

    draw.text((96, 400), "A safe PHP framework that runs on itself.", font=font, fill=INK_LIGHT)
    draw.text(
        (96, 452),
        "One application, unchanged, on its own server • FrankenPHP • nginx • Apache",
        font=small,
        fill=MUTED_ON_DARK,
    )
    draw.line([(96, 512), (150, 512)], fill=BLUE, width=4)

    return card


def derive_logo_variants() -> None:
    """Rewrites the fixed-colour lockups from the adaptive master.

    They differ from `phporbit-logo.svg` only in what replaces `currentColor`,
    so deriving them keeps a geometry fix from having to be applied three
    times — which is exactly how the gradient offset bug survived in two files
    after being fixed in one.
    """
    master_path = os.path.join(BRAND, "phporbit-logo.svg")
    master = open(master_path).read()

    variants = {
        "phporbit-logo-on-dark.svg": ("#e6e9ef", "For dark backgrounds. Fixed colours, for contexts that cannot set currentColor."),
        "phporbit-logo-on-light.svg": ("#10131a", "For light backgrounds. Fixed colours, for contexts that cannot set currentColor."),
    }

    for name, (ink, note) in variants.items():
        stem = name[:-4]
        out = master.replace('stroke="currentColor"', f'stroke="{ink}"')
        out = out.replace('fill="currentColor" stroke="none"', f'fill="{ink}" stroke="none"')
        out = out.replace('id="phporbit-logo-title"', f'id="{stem}-title"')
        out = out.replace('aria-labelledby="phporbit-logo-title"', f'aria-labelledby="{stem}-title"')
        out = out.replace('id="phporbit-logo-grad"', f'id="{stem}-grad"')
        out = out.replace("url(#phporbit-logo-grad)", f"url(#{stem}-grad)")
        out = out.replace(
            "    Adaptive lockup. The wordmark inherits currentColor, so it takes the colour\n"
            "    of the surrounding text and works on either theme without swapping files;\n"
            "    the fixed-colour builds beside it are for contexts that cannot set a colour.",
            "    " + note + "\n    Generated from phporbit-logo.svg by render.py — do not edit.",
        )
        open(os.path.join(BRAND, name), "w").write(out)


def publish_vectors() -> None:
    """Copies the SVG masters into the served asset tree.

    The application links to them rather than inlining, so there is one master
    per asset and no third copy of the geometry drifting inside a template.
    """
    import shutil

    served = os.path.join(PUBLIC, "assets", "brand")
    os.makedirs(served, exist_ok=True)

    for name in (
        "phporbit-mark.svg",
        "phporbit-mark-mono.svg",
        "phporbit-logo.svg",
        "phporbit-logo-on-dark.svg",
        "phporbit-logo-on-light.svg",
    ):
        shutil.copyfile(os.path.join(BRAND, name), os.path.join(served, name))

    return served


def main() -> None:
    os.makedirs(ICONS, exist_ok=True)

    # Favicons and app icons.
    for size in (16, 32, 48, 64, 180, 192, 512):
        simplified = size <= 64
        draw_mark(size, simplified=simplified).save(
            os.path.join(ICONS, f"icon-{size}.png")
        )

    # Apple wants an opaque icon; a transparent one renders on black.
    apple = Image.new("RGBA", (180, 180), INK_DARK + (255,))
    apple.alpha_composite(draw_mark(148), (16, 16))
    apple.save(os.path.join(ICONS, "apple-touch-icon.png"))

    # Multi-resolution .ico for legacy browsers and Windows shortcuts.
    Image.open(os.path.join(ICONS, "icon-48.png")).save(
        os.path.join(PUBLIC, "favicon.ico"),
        sizes=[(16, 16), (32, 32), (48, 48)],
    )

    draw_mark(512).save(os.path.join(BRAND, "phporbit-mark-512.png"))
    draw_mark(512, mono=INK_LIGHT).save(os.path.join(BRAND, "phporbit-mark-mono-light.png"))
    draw_mark(512, mono=INK_DARK).save(os.path.join(BRAND, "phporbit-mark-mono-dark.png"))

    lockup(96, INK_LIGHT, MUTED_ON_DARK).save(os.path.join(BRAND, "phporbit-logo-on-dark.png"))
    lockup(96, INK_DARK, MUTED_ON_LIGHT).save(os.path.join(BRAND, "phporbit-logo-on-light.png"))

    card = social_card().convert("RGB")
    card.save(os.path.join(BRAND, "phporbit-social-card.png"))

    derive_logo_variants()
    served = publish_vectors()
    # The link-preview image has to be fetchable by scrapers, so it lives in the
    # served tree too rather than only in brand/.
    card.save(os.path.join(served, "social-card.png"))

    print("wordmark width (units):", wordmark_width())
    print("wrote icons to", ICONS)
    print("wrote served vectors to", served)


if __name__ == "__main__":
    main()
