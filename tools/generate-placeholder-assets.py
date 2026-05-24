#!/usr/bin/env python3
"""
Generate placeholder wp.org plugin assets — banner, icon, screenshots.

Outputs into assets/ at repo root. Idempotent — re-running produces identical
PNGs (deterministic colours, geometry, text). Intended for v0.1 submission so
the wp.org listing has *something* in the asset slots before designed assets
are commissioned; the script stays in the repo as a one-command regenerator
should the placeholders need to be re-rendered after a brand tweak.

Replace the resulting PNGs with designed assets before public launch.

Requires Pillow (`pip install pillow`). On macOS, Pillow + a system Helvetica
work out of the box; on Linux, install DejaVu Sans (or override FONT_CANDIDATES).
"""

from __future__ import annotations

import os
import sys
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

REPO_ROOT = Path(__file__).resolve().parent.parent
ASSETS_DIR = REPO_ROOT / "assets"

BRAND_NAME = "AI Readiness Kit"
BRAND_TAGLINE = "AI Readiness for WordPress"
BG = (26, 29, 46)
FG_PRIMARY = (255, 255, 255)
FG_SECONDARY = (160, 168, 200)
ACCENT = (90, 145, 255)

FONT_CANDIDATES = [
    "/System/Library/Fonts/HelveticaNeue.ttc",
    "/System/Library/Fonts/Helvetica.ttc",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
    "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf",
]


def load_font(size: int) -> ImageFont.FreeTypeFont | ImageFont.ImageFont:
    for path in FONT_CANDIDATES:
        if os.path.exists(path):
            try:
                return ImageFont.truetype(path, size)
            except OSError:
                continue
    return ImageFont.load_default()


def centered_text(draw: ImageDraw.ImageDraw, text: str, font, fill, box) -> None:
    bbox = draw.textbbox((0, 0), text, font=font)
    w = bbox[2] - bbox[0]
    h = bbox[3] - bbox[1]
    x = box[0] + (box[2] - box[0] - w) / 2 - bbox[0]
    y = box[1] + (box[3] - box[1] - h) / 2 - bbox[1]
    draw.text((x, y), text, font=font, fill=fill)


def make_banner(width: int, height: int, out: Path) -> None:
    img = Image.new("RGB", (width, height), BG)
    draw = ImageDraw.Draw(img)
    scale = height / 250
    title_font = load_font(int(64 * scale))
    tagline_font = load_font(int(22 * scale))
    accent_bar_height = int(4 * scale)
    draw.rectangle(
        (0, height - accent_bar_height, width, height),
        fill=ACCENT,
    )
    title_box = (0, int(height * 0.22), width, int(height * 0.62))
    tagline_box = (0, int(height * 0.62), width, int(height * 0.88))
    centered_text(draw, BRAND_NAME, title_font, FG_PRIMARY, title_box)
    centered_text(draw, BRAND_TAGLINE, tagline_font, FG_SECONDARY, tagline_box)
    img.save(out, "PNG", optimize=True)


def make_icon(size: int, out: Path) -> None:
    img = Image.new("RGB", (size, size), BG)
    draw = ImageDraw.Draw(img)
    margin = int(size * 0.18)
    draw.rounded_rectangle(
        (margin, margin, size - margin, size - margin),
        radius=int(size * 0.12),
        outline=ACCENT,
        width=max(2, int(size * 0.04)),
    )
    monogram_font = load_font(int(size * 0.46))
    centered_text(draw, "AR", monogram_font, FG_PRIMARY, (0, 0, size, size))
    img.save(out, "PNG", optimize=True)


def make_screenshot(
    width: int,
    height: int,
    title: str,
    subtitle: str,
    out: Path,
) -> None:
    img = Image.new("RGB", (width, height), (245, 246, 250))
    draw = ImageDraw.Draw(img)
    chrome_height = int(height * 0.10)
    draw.rectangle((0, 0, width, chrome_height), fill=BG)
    chrome_font = load_font(int(chrome_height * 0.42))
    centered_text(
        draw,
        "Tools  ›  Context  ›  " + title,
        chrome_font,
        FG_PRIMARY,
        (40, 0, width - 40, chrome_height),
    )
    title_font = load_font(int(height * 0.08))
    body_font = load_font(int(height * 0.035))
    placeholder_font = load_font(int(height * 0.028))
    title_box = (60, chrome_height + 40, width - 60, chrome_height + 40 + int(height * 0.12))
    subtitle_box = (
        60,
        title_box[3] + 12,
        width - 60,
        title_box[3] + 12 + int(height * 0.10),
    )
    draw.text((title_box[0], title_box[1]), title, font=title_font, fill=BG)
    draw.text(
        (subtitle_box[0], subtitle_box[1]),
        subtitle,
        font=body_font,
        fill=(80, 85, 100),
    )
    card_top = subtitle_box[3] + 40
    card_box = (40, card_top, width - 40, height - 60)
    draw.rounded_rectangle(card_box, radius=12, fill=(255, 255, 255), outline=(220, 224, 232), width=2)
    placeholder_msg = "[ placeholder screenshot — designed capture lands before public launch ]"
    centered_text(draw, placeholder_msg, placeholder_font, (140, 148, 168), card_box)
    img.save(out, "PNG", optimize=True)


def main() -> int:
    ASSETS_DIR.mkdir(parents=True, exist_ok=True)

    make_banner(772, 250, ASSETS_DIR / "banner-772x250.png")
    make_banner(1544, 500, ASSETS_DIR / "banner-1544x500.png")
    make_icon(128, ASSETS_DIR / "icon-128x128.png")
    make_icon(256, ASSETS_DIR / "icon-256x256.png")

    screenshots = [
        ("Context Profile", "Single source of truth for which CPTs and statuses are exposed to AI agents."),
        ("Markdown View", "Any post served as clean Markdown via .md, ?format=md, or Accept: text/markdown."),
        ("LLMs Index", "/llms.txt admin with editorial entries and LLM-powered descriptions."),
        ("Context Score", "0–100 readiness audit across six weighted sub-scores."),
    ]
    for idx, (title, subtitle) in enumerate(screenshots, start=1):
        make_screenshot(
            1280,
            720,
            title,
            subtitle,
            ASSETS_DIR / f"screenshot-{idx}.png",
        )

    print(f"Wrote 8 placeholder assets to {ASSETS_DIR.relative_to(REPO_ROOT)}/")
    for png in sorted(ASSETS_DIR.glob("*.png")):
        size_kb = png.stat().st_size / 1024
        with Image.open(png) as img:
            w, h = img.size
        print(f"  {png.name:32s} {w}x{h:<5d} {size_kb:6.1f} KB")
    return 0


if __name__ == "__main__":
    sys.exit(main())
