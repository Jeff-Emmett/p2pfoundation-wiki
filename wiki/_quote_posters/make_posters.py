#!/usr/bin/env python3
"""Compose 1080x1080 IG quote posters — P2PF Wiki edition.

Hierarchy (most → least prominent):
  1. Quote text (large serif, full-justified)
  2. Speaker name (medium sans, letter-spaced caps)
  3. P2PF Wiki watermark (small sans, bottom-right corner)

Constraints honored:
- Two fonts only (Liberation Serif + Liberation Sans).
- Full justification (last line left-aligned per typesetting convention).
- All padding/sizes expressed as fractions of canvas SIZE.
- Compositional rule: text block sits across the middle band, leaving the
  upper band for imagery and the lower band for breathing room + watermark.
- Watermark says "P2PF WIKI" — disambiguates source (the wiki) from the
  speakers (who are external thinkers, not P2P Foundation staff).
"""
from PIL import Image, ImageDraw, ImageFilter, ImageFont
from pathlib import Path

BG_DIR = Path("/home/jeffe/Pictures/fal-generated")
OUT_DIR = Path("/home/jeffe/Github/p2pfoundation-wiki/wiki/_quote_posters")

SIZE = 1080  # canvas edge

# Relative padding (fractions of SIZE)
PAD_SIDE = 0.07
PAD_BOTTOM = 0.055
COLUMN_FRAC = 0.88          # quote measure column
GAP_RULE = 0.045            # quote → rule
GAP_AUTHOR = 0.030          # rule → author
QUOTE_TOP_FRAC = 0.30       # quote block top y position
RULE_W_FRAC = 0.055

# Type
SERIF = "/usr/share/fonts/truetype/liberation/LiberationSerif-Regular.ttf"
SANS_BOLD = "/usr/share/fonts/truetype/liberation/LiberationSans-Bold.ttf"
SANS_REG = "/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf"

QUOTE_SIZE = int(SIZE * 0.062)       # ≈ 67px  — primary
AUTHOR_SIZE = int(SIZE * 0.026)      # ≈ 28px  — secondary
WATERMARK_SIZE = int(SIZE * 0.014)   # ≈ 15px  — tertiary
LINE_HEIGHT = 1.28

# Colors
TEXT = (245, 245, 240, 255)
TEXT_SOFT = (220, 220, 215, 240)
RULE_COLOR = (245, 245, 240, 200)
WATERMARK_COLOR = (235, 235, 230, 180)

POSTERS = [
    {
        "bg": "p2pf_ig_bg_ruddick_trust.jpg",
        "out": "01_ruddick_trust.png",
        "quote": (
            "When trust in institutions frays,\n"
            "we don’t abandon trust —\n"
            "we relocalize it."
        ),
        "author": "Will Ruddick",
        "gradient_strength": 220,
        "top_dim": 140,
    },
    {
        "bg": "p2pf_ig_bg_thompson_ecology.jpg",
        "out": "02_thompson_ecology.png",
        "quote": (
            "A market is not a good model for a\n"
            "planetary ecology."
        ),
        "author": "William Irwin Thompson",
        "gradient_strength": 175,
        "top_dim": 70,
    },
    {
        "bg": "p2pf_ig_bg_chorpharn_tempo.jpg",
        "out": "03_chorpharn_tempo.png",
        "quote": (
            "Who, or what, will keep the clocks\n"
            "in time?"
        ),
        "author": "Chor Pharn",
        "gradient_strength": 200,
        "top_dim": 110,
    },
]


def load_font(path, size):
    return ImageFont.truetype(path, size)


def text_w(draw, text, font):
    bbox = draw.textbbox((0, 0), text, font=font)
    return bbox[2] - bbox[0]


def wrap_to_width(draw, text, font, max_w):
    """Word-wrap respecting explicit '\\n' breaks first, then greedy fill."""
    out = []
    for chunk in text.split("\n"):
        words = chunk.split()
        if not words:
            continue
        cur = []
        for w in words:
            trial = (" ".join(cur + [w])) if cur else w
            if text_w(draw, trial, font) <= max_w or not cur:
                cur.append(w)
            else:
                out.append(cur)
                cur = [w]
        if cur:
            out.append(cur)
    return out


MAX_JUSTIFY_STRETCH = 3.0


def draw_justified_line(draw, words, x, y, max_w, font, color, justify):
    if not words:
        return
    space_w = text_w(draw, " ", font)
    word_widths = [text_w(draw, w, font) for w in words]
    total_words = sum(word_widths)
    natural_w = total_words + space_w * (len(words) - 1)
    do_justify = (
        justify
        and len(words) > 1
        and natural_w >= max_w * 0.55
    )
    if do_justify:
        gap = (max_w - total_words) / (len(words) - 1)
        if gap > space_w * MAX_JUSTIFY_STRETCH:
            do_justify = False
    if do_justify:
        cx = x
    else:
        cx = x + (max_w - natural_w) / 2
        gap = space_w
    for i, w in enumerate(words):
        draw.text((cx, y), w, font=font, fill=color)
        cx += word_widths[i] + gap


def vertical_gradient(canvas, strength, top_dim):
    """Soft vertical gradient — gentle dim at top, deeper toward the bottom."""
    w, h = canvas.size
    grad = Image.new("L", (1, h), 0)
    for y in range(h):
        t = y / h
        if t < 0.40:
            val = int(top_dim * (t / 0.40))
        else:
            tt = (t - 0.40) / 0.60
            val = int(top_dim + (strength - top_dim) * (tt**1.3))
        grad.putpixel((0, y), val)
    grad = grad.resize((w, h))
    dark = Image.new("RGBA", (w, h), (0, 0, 0, 0))
    dark.putalpha(grad)
    return Image.alpha_composite(canvas, dark)


def render(spec):
    bg = Image.open(BG_DIR / spec["bg"]).convert("RGB")
    bw, bh = bg.size
    scale = SIZE / min(bw, bh)
    bg = bg.resize((int(bw * scale), int(bh * scale)), Image.LANCZOS)
    bw, bh = bg.size
    left, top = (bw - SIZE) // 2, (bh - SIZE) // 2
    bg = bg.crop((left, top, left + SIZE, top + SIZE)).convert("RGBA")
    bg = vertical_gradient(bg, spec["gradient_strength"], spec["top_dim"])

    draw = ImageDraw.Draw(bg)
    qfont = load_font(SERIF, QUOTE_SIZE)
    afont = load_font(SANS_BOLD, AUTHOR_SIZE)
    wfont = load_font(SANS_BOLD, WATERMARK_SIZE)
    wfont_reg = load_font(SANS_REG, WATERMARK_SIZE)

    # Layout
    block_w = int(SIZE * COLUMN_FRAC)
    block_x = (SIZE - block_w) // 2
    side = int(SIZE * PAD_SIDE)
    bottom = int(SIZE * PAD_BOTTOM)

    # Quote
    lines = wrap_to_width(draw, spec["quote"], qfont, block_w)
    line_h = int(QUOTE_SIZE * LINE_HEIGHT)
    quote_top = int(SIZE * QUOTE_TOP_FRAC)
    y = quote_top
    for i, words in enumerate(lines):
        is_last = (i == len(lines) - 1)
        draw_justified_line(
            draw, words, block_x, y, block_w, qfont, TEXT, justify=not is_last
        )
        y += line_h
    quote_bottom = y

    # Rule
    rule_y = quote_bottom + int(SIZE * GAP_RULE)
    rule_w = int(SIZE * RULE_W_FRAC)
    rx = (SIZE - rule_w) // 2
    draw.rectangle([rx, rule_y, rx + rule_w, rule_y + 2], fill=RULE_COLOR)

    # Author — secondary prominence, letter-spaced caps
    author_y = rule_y + int(SIZE * GAP_AUTHOR)
    cap = spec["author"].upper()
    cap_spaced = "   ".join(cap)
    cw = text_w(draw, cap_spaced, afont)
    draw.text(((SIZE - cw) // 2, author_y), cap_spaced, font=afont, fill=TEXT)

    # Watermark — bottom-right corner, small + restrained
    wm_main = "P2PF  WIKI"
    wm_sub = "wiki.p2pfoundation.net"
    wm_main_w = text_w(draw, wm_main, wfont)
    wm_sub_w = text_w(draw, wm_sub, wfont_reg)
    wm_block_w = max(wm_main_w, wm_sub_w)
    wm_right_x = SIZE - side
    wm_y_sub = SIZE - bottom - WATERMARK_SIZE
    wm_y_main = wm_y_sub - int(WATERMARK_SIZE * 1.55)
    draw.text(
        (wm_right_x - wm_main_w, wm_y_main),
        wm_main, font=wfont, fill=WATERMARK_COLOR,
    )
    draw.text(
        (wm_right_x - wm_sub_w, wm_y_sub),
        wm_sub, font=wfont_reg, fill=WATERMARK_COLOR,
    )

    out = OUT_DIR / spec["out"]
    bg.convert("RGB").save(out, "PNG", optimize=True)
    widths = [text_w(draw, " ".join(w), qfont) for w in lines]
    print(
        f"Wrote {out}  | lines={len(lines)}  quote_top={quote_top}  "
        f"quote_bottom={quote_bottom}  author_y={author_y}  col={block_w}  "
        f"widths={widths}"
    )


def main():
    OUT_DIR.mkdir(parents=True, exist_ok=True)
    for s in POSTERS:
        render(s)


if __name__ == "__main__":
    main()
