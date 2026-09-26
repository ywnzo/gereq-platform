#!/usr/bin/env python3
"""Generate VS Code extension icons for GEREQ tools."""

from __future__ import annotations

import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1] / "tools"

TOOLS = {
    "gereq-env": {
        "title": "GEREQ Env",
        "bg": "#18241c",
        "accent": "#d7ff45",
        "glyph": """
            <rect x="150" y="150" width="212" height="260" rx="28" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M150 220h212" stroke="#fff" stroke-width="26"/>
            <rect x="188" y="255" width="90" height="18" rx="6"/>
            <rect x="188" y="295" width="136" height="14" rx="6" opacity=".75"/>
            <rect x="188" y="330" width="110" height="14" rx="6" opacity=".55"/>
            <circle cx="340" cy="340" r="42" fill="none" stroke="#fff" stroke-width="22"/>
            <path d="M368 368l28 28" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
        """,
    },
    "gereq-i18n": {
        "title": "GEREQ i18n",
        "bg": "#1c2830",
        "accent": "#7eb8e8",
        "glyph": """
            <circle cx="256" cy="256" r="118" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M256 138v236M138 256h236" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
            <path d="M175 180c40 30 122 30 162 0M175 332c40-30 122-30 162 0" fill="none" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
            <rect x="210" y="230" width="40" height="52" rx="8"/>
            <rect x="262" y="230" width="40" height="52" rx="8" opacity=".85"/>
        """,
    },
    "gereq-permissions": {
        "title": "GEREQ Permissions",
        "bg": "#241828",
        "accent": "#c080e8",
        "glyph": """
            <path d="M256 120l120 55v90c0 78-52 140-120 162-68-22-120-84-120-162v-90z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <path d="M210 256l30 30 62-70" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
        """,
    },
    "gereq-twig-goto": {
        "title": "GEREQ Twig IDE",
        "bg": "#281c18",
        "accent": "#f0a070",
        "glyph": """
            <path d="M150 160h212v50H230v170h-48V210H150z" />
            <path d="M300 230h70c40 0 70 28 70 68s-30 68-70 68h-70V230zm48 40v56h22c16 0 28-12 28-28s-12-28-28-28h-22z" opacity=".95"/>
        """,
    },
    "gereq-monitor": {
        "title": "GEREQ Pulse",
        "bg": "#201828",
        "accent": "#e080b0",
        "glyph": """
            <path d="M120 256h70l28-90 48 180 36-120 24 60h66" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="256" cy="256" r="150" fill="none" stroke="#fff" stroke-width="18" opacity=".35"/>
        """,
    },
    "gereq-modules": {
        "title": "GEREQ Modules",
        "bg": "#1c3028",
        "accent": "#5ed8a8",
        "glyph": """
            <rect x="140" y="150" width="100" height="100" rx="22"/>
            <rect x="272" y="150" width="100" height="100" rx="22" opacity=".9"/>
            <rect x="140" y="282" width="100" height="100" rx="22" opacity=".85"/>
            <rect x="272" y="282" width="100" height="100" rx="22" opacity=".7"/>
        """,
    },
}


def svg_for(meta: dict) -> str:
    return f"""<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="{meta["title"]} logo">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{meta["bg"]}"/>
      <stop offset="100%" stop-color="{meta["accent"]}" stop-opacity="0.35"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" rx="112" fill="url(#bg)"/>
  <circle cx="256" cy="256" r="168" fill="{meta["accent"]}" opacity="0.16"/>
  <g fill="#fff" stroke="none">{meta["glyph"]}</g>
</svg>
"""


def main() -> None:
    for name, meta in TOOLS.items():
        d = ROOT / name
        d.mkdir(exist_ok=True)
        svg = d / "logo.svg"
        png = d / "logo.png"
        icon = d / "icon.png"
        svg.write_text(svg_for(meta), encoding="utf-8")
        for out, size in ((png, "512x512"), (icon, "128x128")):
            subprocess.run(
                ["magick", "-background", "none", str(svg), "-resize", size, str(out)],
                check=True,
            )
        print(f"OK {name}")
    print(f"DONE {len(TOOLS)}")


if __name__ == "__main__":
    main()
