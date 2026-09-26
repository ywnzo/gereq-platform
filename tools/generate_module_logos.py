#!/usr/bin/env python3
"""Generate consistent SVG (+ PNG) logos for every GEREQ module."""

from __future__ import annotations

import hashlib
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MODULES = ROOT / "modules"

def glyph(module_id: str) -> str:
    """Return inner SVG markup (white pictogram) for a module id."""
    g: dict[str, str] = {
        "achievements": """
            <path d="M256 96l28 86h90l-72 54 28 86-74-54-74 54 28-86-72-54h90z"/>
        """,
        "ai": """
            <circle cx="256" cy="256" r="52" fill="none" stroke="#fff" stroke-width="28"/>
            <circle cx="256" cy="140" r="22"/><circle cx="256" cy="372" r="22"/>
            <circle cx="140" cy="256" r="22"/><circle cx="372" cy="256" r="22"/>
            <circle cx="172" cy="172" r="18"/><circle cx="340" cy="172" r="18"/>
            <circle cx="172" cy="340" r="18"/><circle cx="340" cy="340" r="18"/>
        """,
        "bikeservice": """
            <circle cx="168" cy="300" r="70" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="344" cy="300" r="70" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M168 300h88l48-110h52" fill="none" stroke="#fff" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="304" cy="190" r="18"/>
        """,
        "bundles": """
            <rect x="140" y="160" width="150" height="110" rx="18"/>
            <rect x="222" y="230" width="150" height="110" rx="18" opacity=".85"/>
            <rect x="180" y="200" width="150" height="110" rx="18" opacity=".7"/>
        """,
        "calendar": """
            <rect x="130" y="150" width="252" height="230" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M130 220h252" stroke="#fff" stroke-width="28"/>
            <rect x="175" y="270" width="40" height="40" rx="8"/>
            <rect x="236" y="270" width="40" height="40" rx="8"/>
            <rect x="297" y="270" width="40" height="40" rx="8"/>
            <path d="M190 120v50M322 120v50" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
        """,
        "carservice": """
            <path d="M150 300h212M170 300v40M352 300v40" stroke="#fff" stroke-width="26" stroke-linecap="round"/>
            <path d="M160 300c20-70 50-100 96-100h40c46 0 70 30 90 100" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="200" cy="300" r="28" fill="none" stroke="#fff" stroke-width="20"/>
            <circle cx="330" cy="300" r="28" fill="none" stroke="#fff" stroke-width="20"/>
            <path d="M360 210l40 20" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
        """,
        "cms": """
            <rect x="120" y="130" width="272" height="252" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M120 200h272" stroke="#fff" stroke-width="28"/>
            <rect x="155" y="235" width="120" height="18" rx="6"/>
            <rect x="155" y="275" width="200" height="14" rx="6" opacity=".8"/>
            <rect x="155" y="310" width="160" height="14" rx="6" opacity=".6"/>
        """,
        "community": """
            <circle cx="200" cy="200" r="48"/>
            <circle cx="312" cy="200" r="48"/>
            <circle cx="256" cy="300" r="48"/>
            <path d="M200 248c-50 20-70 55-70 90M312 248c50 20 70 55 70 90M256 348c-40 18-90 30-120 30M256 348c40 18 90 30 120 30"
                  fill="none" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
        """,
        "coupons": """
            <path d="M140 200h232a28 28 0 0 1 28 28v56a28 28 0 0 1-28 28H140a28 28 0 0 1-28-28v-56a28 28 0 0 1 28-28z" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="190" cy="256" r="22" fill="none" stroke="#fff" stroke-width="18"/>
            <path d="M240 230v52M280 230v52M320 230v52" stroke="#fff" stroke-width="18" stroke-linecap="round" stroke-dasharray="8 14"/>
        """,
        "crm": """
            <rect x="150" y="150" width="212" height="260" rx="24" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="256" cy="230" r="40"/>
            <path d="M190 340c20-40 50-55 66-55s46 15 66 55" fill="none" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
            <path d="M320 170l40-30M320 190l50 0" stroke="#fff" stroke-width="18" stroke-linecap="round"/>
        """,
        "currency": """
            <circle cx="256" cy="256" r="110" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M256 170v172M220 210h70c24 0 40 16 40 36s-16 36-40 36h-50c-24 0-40 16-40 36s16 36 40 36h80"
                  fill="none" stroke="#fff" stroke-width="24" stroke-linecap="round" stroke-linejoin="round"/>
        """,
        "customers": """
            <circle cx="256" cy="190" r="58"/>
            <path d="M150 370c20-70 60-100 106-100s86 30 106 100" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
        """,
        "customer_uploads": """
            <path d="M256 140v180" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
            <path d="M200 200l56-60 56 60" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
            <path d="M150 360h212" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
            <path d="M150 360v40h212v-40" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
        """,
        "documents": """
            <path d="M170 120h130l90 90v200a28 28 0 0 1-28 28H170a28 28 0 0 1-28-28V148a28 28 0 0 1 28-28z" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M300 120v90h90" fill="none" stroke="#fff" stroke-width="26" stroke-linejoin="round"/>
            <path d="M195 280h120M195 330h90" stroke="#fff" stroke-width="20" stroke-linecap="round"/>
        """,
        "emailing": """
            <rect x="110" y="160" width="292" height="200" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M130 185l126 95 126-95" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
        """,
        "engage": """
            <path d="M256 120l40 90h95l-77 58 30 92-88-64-88 64 30-92-77-58h95z"/>
            <circle cx="256" cy="256" r="150" fill="none" stroke="#fff" stroke-width="22" opacity=".45"/>
        """,
        "esign": """
            <path d="M150 340c40-80 70-120 110-120 30 0 40 30 70 30 40 0 60-50 100-90" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
            <path d="M330 150l40 40-120 120h-40v-40z"/>
        """,
        "feeds": """
            <circle cx="170" cy="340" r="28"/>
            <path d="M170 250a90 90 0 0 1 90 90" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
            <path d="M170 170a170 170 0 0 1 170 170" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
            <path d="M170 110a230 230 0 0 1 230 230" fill="none" stroke="#fff" stroke-width="22" stroke-linecap="round" opacity=".55"/>
        """,
        "fleet": """
            <path d="M120 300h272M150 300v45M370 300v45" stroke="#fff" stroke-width="24" stroke-linecap="round"/>
            <path d="M140 300c18-65 48-95 90-95h60c42 0 68 30 90 95" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="190" cy="300" r="26" fill="none" stroke="#fff" stroke-width="18"/>
            <circle cx="340" cy="300" r="26" fill="none" stroke="#fff" stroke-width="18"/>
            <rect x="200" y="175" width="90" height="30" rx="8"/>
        """,
        "forum": """
            <path d="M140 150h180a36 36 0 0 1 36 36v90a36 36 0 0 1-36 36H220l-50 50v-50h-30a36 36 0 0 1-36-36v-90a36 36 0 0 1 36-36z" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M250 220h160a28 28 0 0 1 28 28v70a28 28 0 0 1-28 28h-20v40l-45-40h-30" fill="none" stroke="#fff" stroke-width="22" opacity=".75"/>
        """,
        "gereq": """
            <path d="M256 110l120 70v140l-120 70-120-70V180z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <path d="M256 200v120M210 250h92" stroke="#fff" stroke-width="26" stroke-linecap="round"/>
        """,
        "idoklad": """
            <rect x="150" y="120" width="212" height="272" rx="24" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M190 190h132M190 240h132M190 290h90" stroke="#fff" stroke-width="20" stroke-linecap="round"/>
            <circle cx="320" cy="320" r="28"/>
        """,
        "listings": """
            <path d="M256 120l140 120v160a24 24 0 0 1-24 24H140a24 24 0 0 1-24-24V240z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <rect x="210" y="280" width="92" height="100" rx="10"/>
        """,
        "loyalty": """
            <path d="M256 360s-110-70-110-150c0-45 35-75 75-75 25 0 45 12 55 30 10-18 30-30 55-30 40 0 75 30 75 75 0 80-110 150-110 150z"/>
        """,
        "media": """
            <rect x="120" y="150" width="272" height="212" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <circle cx="200" cy="230" r="28"/>
            <path d="M150 330l70-70 50 50 60-80 70 100z"/>
        """,
        "messenger": """
            <path d="M140 150h232a36 36 0 0 1 36 36v120a36 36 0 0 1-36 36H220l-55 55v-55h-25a36 36 0 0 1-36-36V186a36 36 0 0 1 36-36z"/>
            <circle cx="200" cy="250" r="14"/><circle cx="256" cy="250" r="14"/><circle cx="312" cy="250" r="14"/>
        """,
        "moneys3": """
            <ellipse cx="256" cy="170" rx="110" ry="40"/>
            <path d="M146 170v90c0 22 49 40 110 40s110-18 110-40v-90" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M146 230v90c0 22 49 40 110 40s110-18 110-40v-90" fill="none" stroke="#fff" stroke-width="26"/>
        """,
        "newsletter": """
            <rect x="130" y="150" width="252" height="180" rx="24" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M150 170l106 80 106-80" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M180 360h152" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
            <path d="M210 390h92" stroke="#fff" stroke-width="18" stroke-linecap="round" opacity=".7"/>
        """,
        "notifications": """
            <path d="M256 120c-55 0-95 45-95 100v60l-30 45h250l-30-45v-60c0-55-40-100-95-100z"/>
            <path d="M220 370a36 36 0 0 0 72 0" fill="none" stroke="#fff" stroke-width="24"/>
        """,
        "orders": """
            <path d="M150 160h40l30 180h170" fill="none" stroke="#fff" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>
            <circle cx="250" cy="380" r="24"/><circle cx="350" cy="380" r="24"/>
            <path d="M200 220h170l-20 100H230z"/>
        """,
        "partscatalog": """
            <rect x="150" y="150" width="90" height="90" rx="14"/>
            <rect x="272" y="150" width="90" height="90" rx="14"/>
            <rect x="150" y="272" width="90" height="90" rx="14"/>
            <circle cx="317" cy="317" r="40" fill="none" stroke="#fff" stroke-width="22"/>
            <path d="M345 345l30 30" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
        """,
        "payments": """
            <rect x="110" y="170" width="292" height="180" rx="28" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M110 230h292" stroke="#fff" stroke-width="28"/>
            <rect x="150" y="280" width="90" height="28" rx="8"/>
        """,
        "portals": """
            <circle cx="190" cy="256" r="70" fill="none" stroke="#fff" stroke-width="26"/>
            <circle cx="322" cy="256" r="70" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M230 220h52M230 292h52" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
        """,
        "product_discounts": """
            <path d="M150 200l106-70 106 70v140a28 28 0 0 1-28 28H178a28 28 0 0 1-28-28z" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M210 270l40 40 70-80" fill="none" stroke="#fff" stroke-width="26" stroke-linecap="round" stroke-linejoin="round"/>
        """,
        "products": """
            <path d="M256 130l130 70v120l-130 70-130-70V200z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <path d="M256 200v190M126 200l130 70 130-70" fill="none" stroke="#fff" stroke-width="26" stroke-linejoin="round"/>
        """,
        "projects": """
            <rect x="140" y="150" width="232" height="240" rx="24" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M180 140h152v40H180z"/>
            <path d="M185 250h140M185 300h100M185 350h120" stroke="#fff" stroke-width="20" stroke-linecap="round"/>
        """,
        "returns": """
            <path d="M180 200h140a70 70 0 1 1 0 140H200" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
            <path d="M220 160l-50 40 50 40" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
        """,
        "reviews": """
            <path d="M256 140l30 70h75l-60 46 22 74-67-48-67 48 22-74-60-46h75z"/>
            <path d="M160 360h192" stroke="#fff" stroke-width="22" stroke-linecap="round" opacity=".6"/>
        """,
        "searchengines": """
            <circle cx="230" cy="230" r="95" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M300 300l80 80" stroke="#fff" stroke-width="28" stroke-linecap="round"/>
        """,
        "shop": """
            <path d="M170 200h172l-20 180H190z" fill="none" stroke="#fff" stroke-width="26" stroke-linejoin="round"/>
            <path d="M200 200c0-40 20-70 56-70s56 30 56 70" fill="none" stroke="#fff" stroke-width="26"/>
        """,
        "translate": """
            <circle cx="210" cy="240" r="100" fill="none" stroke="#fff" stroke-width="26"/>
            <path d="M110 240h200M210 140c35 40 35 160 0 200M210 140c-35 40-35 160 0 200" fill="none" stroke="#fff" stroke-width="22"/>
            <path d="M300 300h90M320 280l50 70M370 280l-50 70" stroke="#fff" stroke-width="20" stroke-linecap="round"/>
        """,
        "viewings": """
            <path d="M100 256c40-80 100-120 156-120s116 40 156 120c-40 80-100 120-156 120s-116-40-156-120z" fill="none" stroke="#fff" stroke-width="28"/>
            <circle cx="256" cy="256" r="48"/>
        """,
        "vitality": """
            <path d="M120 256h60l30-70 50 150 40-100 30 50h62" fill="none" stroke="#fff" stroke-width="28" stroke-linecap="round" stroke-linejoin="round"/>
        """,
        "warehouse": """
            <path d="M120 220l136-90 136 90v150H120z" fill="none" stroke="#fff" stroke-width="28" stroke-linejoin="round"/>
            <path d="M200 370V260h112v110" fill="none" stroke="#fff" stroke-width="26"/>
        """,
        "webhooks": """
            <circle cx="180" cy="180" r="36" fill="none" stroke="#fff" stroke-width="22"/>
            <circle cx="332" cy="180" r="36" fill="none" stroke="#fff" stroke-width="22"/>
            <circle cx="256" cy="340" r="36" fill="none" stroke="#fff" stroke-width="22"/>
            <path d="M210 200l30 100M302 200l-30 100M216 180h80" fill="none" stroke="#fff" stroke-width="22" stroke-linecap="round"/>
        """,
        "webplus": """
            <circle cx="256" cy="256" r="120" fill="none" stroke="#fff" stroke-width="28"/>
            <path d="M136 256h240M256 136c45 40 70 90 70 120s-25 80-70 120c-45-40-70-90-70-120s25-80 70-120z"
                  fill="none" stroke="#fff" stroke-width="22"/>
            <path d="M330 150v60M300 180h60" stroke="#fff" stroke-width="24" stroke-linecap="round"/>
        """,
    }
    return g.get(module_id, f'<text x="256" y="280" text-anchor="middle" font-family="Inter,Arial,sans-serif" font-size="120" font-weight="700" fill="#fff">{module_id[:2].upper()}</text>')


def colors_for(module_id: str) -> tuple[str, str]:
    curated = {
        "achievements": "#1b3320",
        "ai": "#0e2430",
        "bikeservice": "#1a2740",
        "bundles": "#2a1f18",
        "calendar": "#1c2c38",
        "carservice": "#241c1a",
        "cms": "#1a2433",
        "community": "#1f2a24",
        "coupons": "#33241a",
        "crm": "#1a2230",
        "currency": "#1c3020",
        "customers": "#1a2830",
        "customer_uploads": "#243018",
        "documents": "#222830",
        "emailing": "#182830",
        "engage": "#302018",
        "esign": "#1c2430",
        "feeds": "#302818",
        "fleet": "#1a2030",
        "forum": "#18262e",
        "gereq": "#0f1a14",
        "idoklad": "#1e2830",
        "listings": "#20301c",
        "loyalty": "#301820",
        "media": "#241828",
        "messenger": "#182838",
        "moneys3": "#1c3028",
        "newsletter": "#203028",
        "notifications": "#302818",
        "orders": "#281c18",
        "partscatalog": "#22222c",
        "payments": "#1a3028",
        "portals": "#182830",
        "product_discounts": "#30241a",
        "products": "#24301c",
        "projects": "#1c2830",
        "returns": "#30201c",
        "reviews": "#302818",
        "searchengines": "#1a2830",
        "shop": "#281c20",
        "translate": "#1c2830",
        "viewings": "#182430",
        "vitality": "#201828",
        "warehouse": "#28301c",
        "webhooks": "#30241a",
        "webplus": "#183028",
    }
    accents = {
        "achievements": "#c8f542",
        "ai": "#3dd6c6",
        "bikeservice": "#6eb6ff",
        "bundles": "#f0a46a",
        "calendar": "#7ec8e3",
        "carservice": "#f08a6a",
        "cms": "#8bb4ff",
        "community": "#7ddea0",
        "coupons": "#ffb454",
        "crm": "#8aa4ff",
        "currency": "#8fd96b",
        "customers": "#6ec6e0",
        "customer_uploads": "#a3e06a",
        "documents": "#b0bec8",
        "emailing": "#6ec4ff",
        "engage": "#ff9a62",
        "esign": "#9ab6ff",
        "feeds": "#ffc04d",
        "fleet": "#7aa2ff",
        "forum": "#5ec8e0",
        "gereq": "#d7ff45",
        "idoklad": "#9eb4c4",
        "listings": "#8fd47a",
        "loyalty": "#ff7a9a",
        "media": "#d08ad8",
        "messenger": "#6ab0ff",
        "moneys3": "#5ed6a8",
        "newsletter": "#7dcea0",
        "notifications": "#ffbf4d",
        "orders": "#f0a070",
        "partscatalog": "#a0a8c8",
        "payments": "#5ed0a8",
        "portals": "#6ec0e8",
        "product_discounts": "#ffb05c",
        "products": "#9ad46a",
        "projects": "#7eb0d8",
        "returns": "#f09070",
        "reviews": "#ffc04d",
        "searchengines": "#6ec8e0",
        "shop": "#f090a8",
        "translate": "#7eb8e8",
        "viewings": "#6eb0e8",
        "vitality": "#e080b0",
        "warehouse": "#b4d46a",
        "webhooks": "#ffb06a",
        "webplus": "#5ed8a8",
    }
    bg = curated.get(module_id)
    accent = accents.get(module_id)
    if bg and accent:
        return bg, accent
    digest = hashlib.sha256(module_id.encode()).hexdigest()
    hue = int(digest[:2], 16) * 360 // 255
    return f"hsl({hue} 28% 14%)", f"hsl({(hue + 40) % 360} 70% 62%)"


def dir_for_id(module_id: str) -> Path | None:
    for path in MODULES.iterdir():
        if not path.is_dir():
            continue
        manifest = path / "module.php"
        if not manifest.exists():
            continue
        text = manifest.read_text(encoding="utf-8")
        if f"'id' => '{module_id}'" in text or f'"id" => "{module_id}"' in text:
            return path
    return None


def build_svg(module_id: str, name: str) -> str:
    bg, accent = colors_for(module_id)
    mark = glyph(module_id)
    # Soft radial highlight uses accent as a glow disc behind the glyph.
    return f'''<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" role="img" aria-label="{name} module logo">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="{bg}"/>
      <stop offset="100%" stop-color="{accent}" stop-opacity="0.35"/>
    </linearGradient>
  </defs>
  <rect width="512" height="512" rx="112" fill="url(#bg)"/>
  <circle cx="256" cy="256" r="168" fill="{accent}" opacity="0.16"/>
  <g fill="#fff" stroke="none">{mark}</g>
</svg>
'''


def main() -> None:
    rows: list[tuple[str, str, Path]] = []
    for path in sorted(MODULES.iterdir()):
        manifest = path / "module.php"
        if not manifest.is_file():
            continue
        # Parse id/name lightly without executing PHP.
        text = manifest.read_text(encoding="utf-8")
        mid = name = None
        for line in text.splitlines():
            line = line.strip()
            if line.startswith("'id'"):
                mid = line.split("=>", 1)[1].strip(" ',\"")
            if line.startswith("'name'"):
                name = line.split("=>", 1)[1].strip(" ',\"")
        if not mid:
            continue
        rows.append((mid, name or mid, path))

    for mid, name, path in rows:
        assets = path / "assets"
        assets.mkdir(exist_ok=True)
        svg_path = assets / "logo.svg"
        png_path = assets / "logo.png"
        svg_path.write_text(build_svg(mid, name), encoding="utf-8")
        # Rasterize for GitLab avatars / README.
        subprocess.run(
            [
                "magick",
                "-background",
                "none",
                str(svg_path),
                "-resize",
                "512x512",
                str(png_path),
            ],
            check=True,
        )
        print(f"OK {mid} -> {svg_path.relative_to(ROOT)}")

    print(f"DONE {len(rows)} logos")


if __name__ == "__main__":
    main()
