#!/usr/bin/env python3
"""Heureka public search via curl_cffi (Chrome TLS impersonation).

Stdout: JSON { ok, items, error, has_more }
Usage:
  heureka_search.py <query> [page=1] [limit=40]
  heureka_search.py --offers <product_url>
"""

from __future__ import annotations

import html as html_lib
import json
import re
import sys
from urllib.parse import urlencode, urlparse

try:
    from curl_cffi import requests
except ImportError:
    print(json.dumps({"ok": False, "items": [], "offers": [], "error": "curl_cffi_missing", "has_more": False}))
    sys.exit(0)


UA_IMPERSONATE = "chrome"
TIMEOUT = 25
PAGE_SIZE_HINT = 20
EXTRA_PAGES = 3  # +3× „Zobrazit další produkty“


def fetch(url: str) -> str:
    r = requests.get(
        url,
        impersonate=UA_IMPERSONATE,
        timeout=TIMEOUT,
        headers={
            "Accept-Language": "cs,en;q=0.8",
            "Accept": "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8",
        },
    )
    if r.status_code >= 400:
        raise RuntimeError(f"http_{r.status_code}")
    text = r.text or ""
    if "Checking security" in text or "Just a moment" in text:
        raise RuntimeError("cloudflare_challenge")
    return text


def parse_price(raw: str) -> float | None:
    raw = html_lib.unescape(raw)
    raw = raw.replace("\xa0", " ").replace("\u202f", " ")
    raw = re.sub(r"(?i)Kč|CZK|€|EUR", "", raw)
    raw = re.sub(r"\s+", " ", raw).strip()
    if not raw:
        return None
    # 1.234.567,89 / 3.490,00
    if re.fullmatch(r"\d{1,3}(?:\.\d{3})+(?:,\d{1,2})?", raw):
        raw = raw.replace(".", "").replace(",", ".")
    elif re.fullmatch(r"\d{1,3}(?: \d{3})+(?:,\d{1,2})?", raw):
        raw = raw.replace(" ", "").replace(",", ".")
    elif re.fullmatch(r"\d{1,3}(?:\.\d{3})+", raw):
        raw = raw.replace(".", "")
    elif "," in raw and "." not in raw:
        raw = raw.replace(" ", "").replace(",", ".")
    else:
        m = re.search(r"(\d{1,3}(?:[.\s]\d{3})+(?:,\d{1,2})?|\d+(?:[.,]\d{1,2})?)", raw)
        if not m:
            return None
        return parse_price(m.group(1))
    try:
        value = float(raw)
    except ValueError:
        return None
    return value if value > 0 else None


def shop_count_from_label(shop: str | None) -> int | None:
    if not shop:
        return None
    cm = re.search(r"(?:^|\s)v(?:e)?\s+(\d+)\s+obchod", shop, re.I)
    if cm:
        return int(cm.group(1))
    cm = re.search(r"(\d+)\s+z\s+(\d+)\s+obchod", shop, re.I)
    if cm:
        return int(cm.group(2))
    return None


def parse_items(html: str) -> list[dict]:
    parts = re.split(r'<li[^>]*data-testid="product-list-item"', html)
    items: list[dict] = []
    for block in parts[1:]:
        pid_m = re.search(r'data-product-id="(\d+)"', block)
        title_m = re.search(
            r'data-testid="product-title"[^>]*>\s*<a[^>]*href="([^"]+)"[^>]*>([\s\S]*?)</a>',
            block,
        )
        if not title_m:
            continue
        href = html_lib.unescape(title_m.group(1)).strip()
        name = html_lib.unescape(re.sub(r"<[^>]+>", "", title_m.group(2)))
        name = re.sub(r"\s+", " ", name).strip()
        if not name:
            continue
        if href.startswith("/"):
            href = "https://www.heureka.cz" + href
        cat = re.search(r'href="(https://[^"]+\.heureka\.cz/[^"]+/)"', block)
        if cat and "exit-click" in href:
            href = html_lib.unescape(cat.group(1))

        price_m = re.search(r'data-testid="ProductPrice"[^>]*>\s*([^<]+)', block)
        min_price = parse_price(price_m.group(1)) if price_m else None
        max_price = None
        if price_m:
            raw = html_lib.unescape(price_m.group(1)).replace("\xa0", " ")
            nums = re.findall(r"(\d[\d\s]*)", raw)
            if len(nums) >= 2:
                try:
                    max_price = float(nums[1].replace(" ", ""))
                except ValueError:
                    max_price = None

        img_m = re.search(
            r'data-testid="product-image-link"[\s\S]{0,900}?src="(https://img-cdn[^"]+)"',
            block,
        )
        shops_m = re.search(
            r'c-product__shops[^>]*>\s*([^<]+)|data-testid="product-shops"[^>]*>\s*([^<]+)',
            block,
        )
        shop = None
        if shops_m:
            shop = html_lib.unescape((shops_m.group(1) or shops_m.group(2) or "")).strip() or None

        brand = None
        for b in ("Shimano", "SRAM", "BTWIN", "ROCKRIDER", "Magura", "Hope", "Tektro"):
            if b.lower() in name.lower():
                brand = b
                break

        pid = pid_m.group(1) if pid_m else str(abs(hash(name)) % 10_000_000)
        items.append(
            {
                "id": f"heureka-{pid}",
                "name": name,
                "url": href,
                "min_price": min_price,
                "max_price": max_price,
                "currency": "CZK",
                "shop": shop or "Heureka",
                "shop_count": shop_count_from_label(shop),
                "source": "heureka",
                "image": html_lib.unescape(img_m.group(1)) if img_m else None,
                "description": None,
                "brand": brand,
                "parameters": [],
            }
        )
    return items


def _offer_from_block(block: str, base_url: str, host) -> dict | None:
    shop_m = re.search(r'aria-label="Do obchodu ([^"]+)"', block)
    shop = html_lib.unescape(shop_m.group(1)).strip() if shop_m else None
    if not shop:
        logo_m = re.search(r'alt="Logo ([^"]+)"', block)
        shop = html_lib.unescape(logo_m.group(1)).strip() if logo_m else None
    if not shop:
        shop_m = re.search(
            r'c-top-offer__shop[^>]*>[\s\S]{0,200}?>([^<]+)|'
            r'data-testid="Offer Shop Name"[^>]*>\s*([^<]+)',
            block,
        )
        if shop_m:
            shop = html_lib.unescape((shop_m.group(1) or shop_m.group(2) or "")).strip() or None
    if not shop:
        return None
    price_m = re.search(
        r'c-offer__price[^>]*>\s*([^<]+)|c-top-offer__price[^>]*>\s*([^<]+)',
        block,
    )
    price = None
    if price_m:
        price = parse_price(price_m.group(1) or price_m.group(2) or "")
    avail_m = re.search(
        r'data-testid="Availability Badge"[\s\S]{0,240}?<span[^>]*>\s*([^<]+)',
        block,
    )
    availability = html_lib.unescape(avail_m.group(1)).strip() if avail_m else None
    shipping = None
    desc_m = re.search(
        r'c-offer__price-desc[^>]*>([\s\S]*?)</div>|c-top-offer__delivery[^>]*>([\s\S]*?)</[^>]+>',
        block,
    )
    if desc_m:
        shipping = html_lib.unescape(re.sub(r"<[^>]+>", " ", desc_m.group(1) or desc_m.group(2) or ""))
        shipping = re.sub(r"\s+", " ", shipping).strip() or None
    free_shipping = bool(shipping and re.search(r"zdarma|\bfree\b", shipping, re.I))
    href_m = re.search(r'href="(/exit-click-web[^"]+)"', block)
    href = html_lib.unescape(href_m.group(1)) if href_m else base_url
    if href.startswith("/"):
        href = f"{host.scheme}://{host.netloc}{href}"
    return {
        "shop": shop,
        "price": price,
        "currency": "CZK",
        "availability": availability,
        "shipping": shipping,
        "free_shipping": free_shipping,
        "url": href,
    }


def parse_offers(html: str, base_url: str) -> list[dict]:
    offers: list[dict] = []
    host = urlparse(base_url)
    seen: set[str] = set()

    for block in re.split(r'<section class="c-offer"', html)[1:]:
        row = _offer_from_block(block, base_url, host)
        if not row:
            continue
        key = f"{row['shop']}|{row['price']}"
        if key in seen:
            continue
        seen.add(key)
        offers.append(row)

    if not offers:
        top = re.search(
            r'class="c-top-offer__wrapper"[\s\S]{0,4000}?c-top-offer__exit',
            html,
        )
        if top:
            row = _offer_from_block(top.group(0), base_url, host)
            if row:
                offers.append(row)

    return offers


def fetch_offers(url: str) -> dict:
    url = url.strip()
    if not url:
        return {"ok": False, "offers": [], "error": "empty_url"}
    html = fetch(url)
    offers = parse_offers(html, url)
    return {
        "ok": len(offers) > 0,
        "offers": offers,
        "error": None if offers else "no_results",
    }


def search(query: str, page: int = 1, limit: int = 40) -> dict:
    query = query.strip()
    if not query:
        return {"ok": False, "items": [], "error": "empty_query", "has_more": False}

    page = max(1, page)
    start_f = (page - 1) * (1 + EXTRA_PAGES)
    pages = 1 + EXTRA_PAGES
    seen: set[str] = set()
    items: list[dict] = []
    last_count = 0

    for i in range(pages):
        f = start_f + i
        params = {"h[fraze]": query}
        if f > 0:
            params["f"] = str(f)
        url = "https://www.heureka.cz/?" + urlencode(params)
        html = fetch(url)
        batch = parse_items(html)
        last_count = len(batch)
        for row in batch:
            key = row["id"]
            if key in seen:
                continue
            seen.add(key)
            items.append(row)
            if len(items) >= limit:
                break
        if len(items) >= limit:
            break
        if last_count == 0:
            break

    return {
        "ok": len(items) > 0,
        "items": items[:limit],
        "error": None if items else "no_results",
        "has_more": last_count >= PAGE_SIZE_HINT - 2,
    }


def main() -> None:
    if len(sys.argv) > 1 and sys.argv[1] == "--offers":
        url = sys.argv[2] if len(sys.argv) > 2 else ""
        try:
            result = fetch_offers(url)
        except Exception as e:  # noqa: BLE001
            result = {"ok": False, "offers": [], "error": str(e)}
        print(json.dumps(result, ensure_ascii=False))
        return

    query = sys.argv[1] if len(sys.argv) > 1 else ""
    page = int(sys.argv[2]) if len(sys.argv) > 2 else 1
    limit = int(sys.argv[3]) if len(sys.argv) > 3 else 40
    try:
        result = search(query, page, limit)
    except Exception as e:  # noqa: BLE001
        result = {"ok": False, "items": [], "error": str(e), "has_more": False}
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
