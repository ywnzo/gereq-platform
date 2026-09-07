from __future__ import annotations

import math
from pathlib import Path

from reportlab.lib.colors import HexColor, Color
from reportlab.lib.enums import TA_LEFT, TA_CENTER
from reportlab.lib.pagesizes import landscape
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph


ROOT = Path(__file__).resolve().parents[1]
BRAND = Path(r"V:\home\dev\projects\GEREQ.cz\.brand")
OUT = ROOT / "output" / "pdf" / "GEREQ_Platform_prezentace_a_cenik_2026.pdf"

PAGE_W = 960
PAGE_H = 540

INK = HexColor("#0A0B0A")
PAPER = HexColor("#F5F5EF")
SIGNAL = HexColor("#D7FF45")
MUTED = HexColor("#6B7067")
BORDER = HexColor("#DADDD4")
WHITE = HexColor("#FFFFFF")
WASH = HexColor("#ECEEE7")
DARK_CARD = HexColor("#161816")


def register_fonts() -> None:
    fonts = Path(r"C:\Windows\Fonts")
    pdfmetrics.registerFont(TTFont("GereqSans", str(fonts / "segoeui.ttf")))
    pdfmetrics.registerFont(TTFont("GereqSans-Semi", str(fonts / "seguisb.ttf")))
    pdfmetrics.registerFont(TTFont("GereqSans-Bold", str(fonts / "segoeuib.ttf")))
    pdfmetrics.registerFont(TTFont("GereqMono", str(fonts / "consola.ttf")))


def pstyle(size: float, color=INK, font="GereqSans", leading=None, align=TA_LEFT) -> ParagraphStyle:
    return ParagraphStyle(
        name=f"p-{size}-{font}",
        fontName=font,
        fontSize=size,
        leading=leading or size * 1.28,
        textColor=color,
        alignment=align,
        spaceAfter=0,
        spaceBefore=0,
    )


def para(c: canvas.Canvas, text: str, x: float, y_top: float, width: float, style: ParagraphStyle, height=200) -> float:
    q = Paragraph(text, style)
    _, h = q.wrap(width, height)
    q.drawOn(c, x, y_top - h)
    return h


def draw_cover_image(c: canvas.Canvas, path: Path) -> None:
    img = ImageReader(str(path))
    iw, ih = img.getSize()
    scale = max(PAGE_W / iw, PAGE_H / ih)
    w, h = iw * scale, ih * scale
    c.drawImage(img, (PAGE_W - w) / 2, (PAGE_H - h) / 2, width=w, height=h, mask="auto")


def draw_logo(c: canvas.Canvas, x: float, y: float, width: float, light=False) -> None:
    path = BRAND / "logos" / ("gereq-logo-header.png" if light else "gereq-logo-header.png")
    img = ImageReader(str(path))
    iw, ih = img.getSize()
    c.drawImage(img, x, y, width=width, height=width * ih / iw, mask="auto")


def page_base(c: canvas.Canvas, page: int, dark=False, section="GEREQ PLATFORM") -> None:
    c.setFillColor(INK if dark else PAPER)
    c.rect(0, 0, PAGE_W, PAGE_H, fill=1, stroke=0)
    c.setFillColor(SIGNAL)
    c.rect(0, PAGE_H - 6, PAGE_W, 6, fill=1, stroke=0)
    c.setFont("GereqMono", 8.5)
    c.setFillColor(WHITE if dark else MUTED)
    c.drawString(42, PAGE_H - 30, section)
    c.drawRightString(PAGE_W - 42, PAGE_H - 30, f"{page:02d}")


def footer(c: canvas.Canvas, dark=False) -> None:
    c.setStrokeColor(Color(1, 1, 1, 0.18) if dark else BORDER)
    c.setLineWidth(0.6)
    c.line(42, 28, PAGE_W - 42, 28)
    c.setFont("GereqSans", 7.5)
    c.setFillColor(HexColor("#B4B8AE") if dark else MUTED)
    c.drawString(42, 14, "GEREQ - Digital systems by Jiří Grygerek")
    c.drawRightString(PAGE_W - 42, 14, "gereq.cz")


def kicker(c: canvas.Canvas, text: str, x: float, y: float, dark=False) -> None:
    c.setFont("GereqMono", 8.5)
    c.setFillColor(SIGNAL if dark else MUTED)
    c.drawString(x, y, text.upper())


def title(c: canvas.Canvas, text: str, x: float, y_top: float, width: float, dark=False, size=31) -> float:
    return para(c, text, x, y_top, width, pstyle(size, WHITE if dark else INK, "GereqSans-Bold", size * 1.06))


def rounded_card(c: canvas.Canvas, x: float, y: float, w: float, h: float, fill=WHITE, stroke=BORDER, radius=12) -> None:
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.setLineWidth(0.8)
    c.roundRect(x, y, w, h, radius, fill=1, stroke=1)


def connector(c: canvas.Canvas, x1: float, y1: float, r1: float, x2: float, y2: float, r2: float) -> None:
    dx, dy = x2 - x1, y2 - y1
    length = math.hypot(dx, dy) or 1
    ux, uy = dx / length, dy / length
    c.line(x1 + ux * r1, y1 + uy * r1, x2 - ux * r2, y2 - uy * r2)


def feature_card(c: canvas.Canvas, x: float, y: float, w: float, h: float, number: str, heading: str, body: str) -> None:
    rounded_card(c, x, y, w, h)
    c.setFillColor(SIGNAL)
    c.roundRect(x + 16, y + h - 38, 30, 22, 6, fill=1, stroke=0)
    c.setFillColor(INK)
    c.setFont("GereqMono", 9)
    c.drawCentredString(x + 31, y + h - 30, number)
    para(c, heading, x + 16, y + h - 54, w - 32, pstyle(14, INK, "GereqSans-Semi", 16))
    para(c, body, x + 16, y + h - 88, w - 32, pstyle(9.5, MUTED, "GereqSans", 14), h - 90)


def bullet_list(c: canvas.Canvas, items: list[str], x: float, y_top: float, width: float, dark=False, gap=24) -> None:
    fg = WHITE if dark else INK
    for idx, item in enumerate(items):
        y = y_top - idx * gap
        c.setFillColor(SIGNAL)
        c.roundRect(x, y - 7, 8, 8, 2, fill=1, stroke=0)
        para(c, item, x + 17, y + 2, width - 17, pstyle(10.5, fg, "GereqSans", 14), gap)


def layer(c: canvas.Canvas, y: float, label: str, items: str, fill, text_color=INK, number="01") -> None:
    x, w, h = 94, 772, 68
    c.setFillColor(fill)
    c.setStrokeColor(BORDER if fill != INK else HexColor("#2C2F2A"))
    c.roundRect(x, y, w, h, 12, fill=1, stroke=1)
    c.setFillColor(SIGNAL if fill == INK else INK)
    c.setFont("GereqMono", 10)
    c.drawString(x + 18, y + h - 23, number)
    c.setFont("GereqSans-Semi", 15)
    c.setFillColor(text_color)
    c.drawString(x + 56, y + h - 25, label)
    para(c, items, x + 238, y + h - 18, w - 260, pstyle(9.5, text_color if fill == INK else MUTED, "GereqSans", 13), 55)


def page_1(c: canvas.Canvas) -> None:
    draw_cover_image(c, BRAND / "ads" / "google" / "gereq-ads-b-02-systemy-laptop.png")
    c.setFillColor(Color(0, 0, 0, 0.76))
    c.rect(0, 0, PAGE_W, 62, fill=1, stroke=0)
    c.setFillColor(SIGNAL)
    c.rect(0, 0, 10, 62, fill=1, stroke=0)
    c.setFont("GereqMono", 9)
    c.setFillColor(SIGNAL)
    c.drawString(38, 38, "PREZENTAČNÍ NABÍDKA 2026")
    c.setFont("GereqSans", 11)
    c.setFillColor(WHITE)
    c.drawString(38, 18, "Modulární platforma pro řízení firmy, provozu a digitálních služeb")
    c.drawRightString(PAGE_W - 36, 18, "gereq.cz")
    c.showPage()


def ad_page(c: canvas.Canvas, page: int, filename: str, label: str, note: str) -> None:
    draw_cover_image(c, BRAND / "ads" / "google" / filename)
    c.setFillColor(Color(0, 0, 0, 0.78))
    c.roundRect(626, 466, 292, 40, 8, fill=1, stroke=0)
    c.setFillColor(SIGNAL)
    c.roundRect(638, 479, 8, 8, 2, fill=1, stroke=0)
    c.setFont("GereqMono", 8)
    c.setFillColor(WHITE)
    c.drawString(656, 479, label.upper())
    c.drawRightString(902, 479, f"{page:02d}")
    c.setFillColor(Color(0, 0, 0, 0.78))
    c.roundRect(42, 24, 876, 44, 9, fill=1, stroke=0)
    para(c, note, 64, 56, 832, pstyle(10.2, WHITE, "GereqSans-Semi", 14, TA_CENTER), 28)
    c.showPage()


def page_problem_flow(c: canvas.Canvas) -> None:
    page_base(c, 3, section="OD CHAOSU K SYSTÉMU")
    kicker(c, "01 / KDY PLATFORMU POTŘEBUJETE", 42, 476)
    title(c, "Informace mají proudit. Ne cestovat ručně.", 42, 447, 760)
    para(c, "GEREQ propojí práci lidí, data i externí služby do jednoho srozumitelného toku.", 42, 394, 700, pstyle(12, MUTED, "GereqSans", 17))

    c.setFont("GereqMono", 8)
    c.setFillColor(MUTED)
    c.drawString(62, 346, "DNES")
    c.drawString(634, 346, "S GEREQ")

    current = [("TABULKY", 290), ("E-MAILY", 237), ("SAMOSTATNÉ APLIKACE", 184), ("RUČNÍ KONTROLY", 131)]
    for label, y in current:
        rounded_card(c, 62, y, 248, 38, WHITE, BORDER, 8)
        c.setFillColor(INK)
        c.setFont("GereqSans-Semi", 10)
        c.drawString(78, y + 14, label)
        c.setFillColor(HexColor("#FF6B5E"))
        c.circle(286, y + 19, 4, fill=1, stroke=0)

    c.setStrokeColor(SIGNAL)
    c.setLineWidth(5)
    c.line(354, 222, 514, 222)
    c.line(494, 238, 514, 222)
    c.line(494, 206, 514, 222)
    c.setFont("GereqMono", 8)
    c.setFillColor(MUTED)
    c.drawCentredString(434, 247, "PROPOJIT")

    c.setFillColor(INK)
    c.circle(684, 221, 62, fill=1, stroke=0)
    c.setFillColor(SIGNAL)
    c.setFont("GereqSans-Bold", 20)
    c.drawCentredString(684, 216, "CORE")
    nodes = [("CRM", 580, 304), ("SKLAD", 788, 304), ("PROJEKTY", 556, 145), ("DOKLADY", 812, 145), ("API", 684, 135)]
    for label, nx, ny in nodes:
        c.setStrokeColor(BORDER)
        c.setLineWidth(1.3)
        connector(c, 684, 221, 62, nx, ny, 30)
        c.setFillColor(WHITE)
        c.setStrokeColor(BORDER)
        c.circle(nx, ny, 30, fill=1, stroke=1)
        c.setFillColor(INK)
        c.setFont("GereqMono", 7.5)
        c.drawCentredString(nx, ny - 3, label)

    c.setFillColor(SIGNAL)
    c.roundRect(62, 68, 794, 32, 7, fill=1, stroke=0)
    para(c, "Výsledek: méně přepisování, jasný vlastník procesu a data dostupná tam, kde jsou potřeba.", 82, 91, 754, pstyle(10.3, INK, "GereqSans-Semi", 14, TA_CENTER), 24)
    footer(c)
    c.showPage()


def page_module_map(c: canvas.Canvas) -> None:
    page_base(c, 6, dark=True, section="EKOSYSTÉM GEREQ")
    kicker(c, "04 / MODULY JAKO STAVEBNICE", 42, 476, dark=True)
    title(c, "Jedno jádro. Desítky kombinací.", 42, 447, 670, dark=True)
    para(c, "Každý modul řeší konkrétní oblast a komunikuje přes stabilní rozhraní platformy.", 42, 394, 680, pstyle(11.5, HexColor("#B4B8AE"), "GereqSans", 16))

    cx, cy = 478, 224
    c.setFillColor(SIGNAL)
    c.circle(cx, cy, 58, fill=1, stroke=0)
    c.setFillColor(INK)
    c.setFont("GereqSans-Bold", 18)
    c.drawCentredString(cx, cy + 4, "GEREQ")
    c.setFont("GereqMono", 8)
    c.drawCentredString(cx, cy - 15, "CORE")
    nodes = [
        ("CRM", 244, 318), ("PROJEKTY", 383, 344), ("DOKUMENTY", 574, 344), ("E-COMMERCE", 720, 318),
        ("SERVIS", 236, 157), ("SKLAD", 371, 105), ("REALITY", 585, 105), ("INTEGRACE", 726, 157),
    ]
    for i, (label, nx, ny) in enumerate(nodes):
        c.setStrokeColor(HexColor("#3B4038"))
        c.setLineWidth(1.2)
        connector(c, cx, cy, 58, nx, ny, 36)
        c.setFillColor(DARK_CARD)
        c.setStrokeColor(SIGNAL if i in (0, 3, 4, 7) else HexColor("#3B4038"))
        c.circle(nx, ny, 36, fill=1, stroke=1)
        c.setFillColor(WHITE)
        c.setFont("GereqMono", 7.5)
        c.drawCentredString(nx, ny - 3, label)

    chips = ["iDoklad", "Money S3", "Comgate", "GoPay", "Stripe", "Packeta", "Google", "Microsoft"]
    x = 87
    for chip in chips:
        c.setFillColor(DARK_CARD)
        c.setStrokeColor(HexColor("#3B4038"))
        c.roundRect(x, 45, 91, 27, 7, fill=1, stroke=1)
        c.setFillColor(HexColor("#B4B8AE"))
        c.setFont("GereqSans", 8)
        c.drawCentredString(x + 45.5, 55, chip)
        x += 98
    footer(c, dark=True)
    c.showPage()


def page_2(c: canvas.Canvas) -> None:
    page_base(c, 2)
    kicker(c, "01 / PROČ GEREQ", 42, 476)
    title(c, "Jedna platforma. Váš provoz.", 42, 447, 550)
    para(c, "Když tabulky, samostatné aplikace a ruční přepisování přestanou stačit, GEREQ spojí klíčové procesy do jednoho systému.", 42, 388, 620, pstyle(14, MUTED, "GereqSans", 20))
    cards = [
        ("01", "Méně ruční práce", "Data vznikají jednou a dál proudí mezi zákazníky, zakázkami, skladem, doklady a integracemi."),
        ("02", "Přehled v reálném provozu", "Role, dashboardy, auditní stopa a stav každého procesu na jednom místě."),
        ("03", "Růst bez výměny systému", "Začněte potřebnými moduly. Další oblasti přidáte ve chvíli, kdy na ně firma dozraje."),
    ]
    for i, data in enumerate(cards):
        feature_card(c, 42 + i * 294, 105, 274, 205, *data)
    footer(c)
    c.showPage()


def page_3(c: canvas.Canvas) -> None:
    page_base(c, 4, section="MODULÁRNÍ ARCHITEKTURA")
    kicker(c, "02 / JAK TO FUNGUJE", 42, 476)
    title(c, "Stabilní jádro. Funkce podle potřeby.", 42, 447, 700)
    para(c, "Každá vrstva má jasnou roli. Díky tomu lze systém rozšiřovat bez přepisování celého řešení.", 42, 392, 690, pstyle(12.5, MUTED, "GereqSans", 18))
    layer(c, 280, "GEREQ CORE", "Uživatelé - role - oprávnění - audit - soubory - nastavení - API - události - fronty", INK, WHITE, "01")
    layer(c, 202, "FUNKČNÍ MODULY", "CRM - projekty - kalendář - dokumenty - produkty - objednávky - sklady - CMS", WHITE, INK, "02")
    layer(c, 124, "OBOROVÁ ŘEŠENÍ", "Autoservis - cykloservis - e-commerce - reality - zákaznické portály", WASH, INK, "03")
    layer(c, 46, "INTEGRACE", "iDoklad - Money S3 - Comgate - GoPay - Stripe - Packeta - externí kalendáře", SIGNAL, INK, "04")
    c.showPage()


def page_4(c: canvas.Canvas) -> None:
    page_base(c, 4, dark=True, section="KATALOG MODULŮ")
    kicker(c, "03 / CO LZE ZAPNOUT", 42, 476, dark=True)
    title(c, "Platforma, která se přizpůsobí firmě.", 42, 447, 700, dark=True)
    groups = [
        ("Zákazníci a obchod", "Customers, CRM, Projects, Calendar, Notifications"),
        ("Dokumenty a finance", "Documents, ESign, Currency, Orders, Payments, iDoklad, Money S3"),
        ("Produkty a prodej", "Products, Warehouse, Shop, Coupons, Bundles, Discounts, Returns"),
        ("Obsah a komunikace", "CMS, Media, Newsletter, Reviews, Feeds, Translate"),
        ("Servisní provozy", "Fleet, CarService, BikeService, PartsCatalog, SearchEngines"),
        ("Reality", "Listings, Viewings, Portals, CRM, Calendar, Documents"),
    ]
    for i, (heading, body) in enumerate(groups):
        col, row = i % 3, i // 3
        x, y = 42 + col * 294, 258 - row * 140
        rounded_card(c, x, y, 274, 118, DARK_CARD, HexColor("#2C2F2A"))
        c.setFillColor(SIGNAL)
        c.rect(x + 16, y + 88, 34, 3, fill=1, stroke=0)
        para(c, heading, x + 16, y + 79, 240, pstyle(13, WHITE, "GereqSans-Semi", 16))
        para(c, body, x + 16, y + 48, 240, pstyle(9.2, HexColor("#B4B8AE"), "GereqSans", 13), 48)
    footer(c, dark=True)
    c.showPage()


def use_case(c: canvas.Canvas, x: float, y: float, w: float, h: float, code: str, heading: str, result: str, modules: str) -> None:
    rounded_card(c, x, y, w, h)
    c.setFont("GereqMono", 8)
    c.setFillColor(MUTED)
    c.drawString(x + 18, y + h - 24, code)
    para(c, heading, x + 18, y + h - 40, w - 36, pstyle(16, INK, "GereqSans-Semi", 18))
    para(c, result, x + 18, y + h - 78, w - 36, pstyle(10, MUTED, "GereqSans", 14), 70)
    c.setFillColor(WASH)
    c.roundRect(x + 18, y + 18, w - 36, 37, 7, fill=1, stroke=0)
    para(c, modules, x + 28, y + 45, w - 56, pstyle(7.8, INK, "GereqMono", 10), 28)


def page_5(c: canvas.Canvas) -> None:
    page_base(c, 7, section="PŘÍKLADY ŘEŠENÍ")
    kicker(c, "05 / JEDNO JÁDRO, RŮZNÉ SYSTÉMY", 42, 476)
    title(c, "Sestava podle skutečného provozu.", 42, 447, 650)
    cases = [
        ("SESTAVA 01", "Autoservis", "Od přijetí vozidla přes díly a práci až po protokol, podpis a fakturu.", "Customers + Fleet + CarService + PartsCatalog + Documents"),
        ("SESTAVA 02", "E-commerce", "Produkty, sklad, checkout, platby, doprava, objednávky a účetnictví.", "Products + Warehouse + Shop + Orders + Payments + Feeds"),
        ("SESTAVA 03", "Realitní kancelář", "Nabídky, makléři, poptávky, prohlídky, dokumenty a exporty na portály.", "Listings + CRM + Viewings + Documents + Portals"),
        ("SESTAVA 04", "Interní řízení firmy", "Kontakty, obchodní případy, úkoly, kalendář, dokumenty a přehled vedení.", "Customers + CRM + Projects + Calendar + Documents"),
    ]
    for i, data in enumerate(cases):
        col, row = i % 2, i // 2
        use_case(c, 42 + col * 445, 244 - row * 174, 424, 154, *data)
    footer(c)
    c.showPage()


def page_6(c: canvas.Canvas) -> None:
    page_base(c, 9, dark=True, section="TECHNOLOGICKÝ ZÁKLAD")
    kicker(c, "06 / POD KAPOTOU", 42, 476, dark=True)
    title(c, "Připraveno pro bezpečný provoz a další růst.", 42, 447, 760, dark=True)
    left = [
        "Granulární role a oprávnění",
        "Auditní historie důležitých změn",
        "Dvoufaktorové přihlášení",
        "Verzované REST API a API tokeny",
    ]
    right = [
        "Události a podepsané webhooky",
        "Fronty pro asynchronní úlohy",
        "Modulové závislosti a migrace",
        "Možnost vlastního hostingu i řízeného provozu",
    ]
    bullet_list(c, left, 62, 337, 350, dark=True, gap=47)
    bullet_list(c, right, 500, 337, 370, dark=True, gap=47)
    c.setFillColor(SIGNAL)
    c.roundRect(42, 72, 876, 58, 10, fill=1, stroke=0)
    para(c, "Technologie je prostředek. Výsledkem je systém, kterému rozumí uživatelé a na který se může spolehnout provoz.", 64, 112, 830, pstyle(12.5, INK, "GereqSans-Semi", 17, TA_CENTER), 46)
    footer(c, dark=True)
    c.showPage()


def process_step(c: canvas.Canvas, x: float, number: str, heading: str, text: str, price: str) -> None:
    c.setFillColor(SIGNAL)
    c.circle(x + 17, 330, 17, fill=1, stroke=0)
    c.setFillColor(INK)
    c.setFont("GereqMono", 9)
    c.drawCentredString(x + 17, 327, number)
    para(c, heading, x, 292, 190, pstyle(15, INK, "GereqSans-Semi", 18))
    para(c, text, x, 256, 190, pstyle(9.5, MUTED, "GereqSans", 14), 86)
    c.setFont("GereqSans-Bold", 12)
    c.setFillColor(INK)
    c.drawString(x, 132, price)


def page_7(c: canvas.Canvas) -> None:
    page_base(c, 11, section="SPOLUPRÁCE")
    kicker(c, "07 / OD PROBLÉMU K FUNKČNÍMU SYSTÉMU", 42, 476)
    title(c, "Začínáme malým, ověřitelným krokem.", 42, 447, 720)
    c.setStrokeColor(BORDER)
    c.setLineWidth(2)
    c.line(72, 330, 840, 330)
    steps = [
        ("01", "Diagnostic", "Mapa procesu, rizika, priority a doporučená sestava modulů.", "15-30 tis. Kč"),
        ("02", "Sprint", "Pilot, integrace nebo vyřešení jednoho úzkého provozního problému.", "45-120 tis. Kč"),
        ("03", "System", "Implementace platformy, migrace, úpravy, nasazení a dokumentace.", "od 180 tis. Kč"),
        ("04", "Care", "Monitoring, aktualizace, podpora a dlouhodobý rozvoj systému.", "od 15 tis. Kč / měs."),
    ]
    for i, s in enumerate(steps):
        process_step(c, 54 + i * 222, *s)
    para(c, "Orientační ceny bez DPH. Konkrétní rozsah a investici potvrzuje nabídka po úvodní diagnostice.", 42, 76, 876, pstyle(8.5, MUTED, "GereqSans", 12, TA_CENTER))
    footer(c)
    c.showPage()


def pricing_card(c: canvas.Canvas, x: float, y: float, w: float, h: float, label: str, price: str, unit: str, lines: list[str], accent=False) -> None:
    rounded_card(c, x, y, w, h, INK if accent else WHITE, INK if accent else BORDER)
    fg = WHITE if accent else INK
    c.setFont("GereqMono", 8)
    c.setFillColor(SIGNAL if accent else MUTED)
    c.drawString(x + 18, y + h - 28, label.upper())
    c.setFont("GereqSans-Bold", 22)
    c.setFillColor(fg)
    c.drawString(x + 18, y + h - 64, price)
    c.setFont("GereqSans", 8.5)
    c.setFillColor(HexColor("#B4B8AE") if accent else MUTED)
    c.drawString(x + 18, y + h - 81, unit)
    bullet_list(c, lines, x + 18, y + h - 112, w - 36, dark=accent, gap=27)


def page_8(c: canvas.Canvas) -> None:
    page_base(c, 12, section="ORIENTAČNÍ CENÍK")
    kicker(c, "08 / LICENCE A ROZŠÍŘENÍ", 42, 476)
    title(c, "Platíte za základ, který skutečně používáte.", 42, 447, 760)
    para(c, "Doporučený startovní model pro nasazení GEREQ Platform.", 42, 395, 600, pstyle(11.5, MUTED, "GereqSans", 16))
    pricing_card(c, 42, 118, 274, 244, "GEREQ PLATFORM PRO", "36 000 Kč", "ročně / až 25 uživatelů", ["Core a administrace", "Role, audit, API a webhooky", "Aktualizace platformy"], accent=True)
    pricing_card(c, 343, 118, 274, 244, "VOLITELNÉ MODULY", "od 8 500 Kč", "ročně / podle modulu", ["CMS od 8 500 Kč", "E-commerce od 12 000 Kč", "Oborové balíčky individuálně"])
    pricing_card(c, 644, 118, 274, 244, "IMPLEMENTACE", "od 45 000 Kč", "jednorázově / dle rozsahu", ["Nastavení a onboarding", "Migrace dat a integrace", "Školení a úpravy workflow"])
    para(c, "Hosting, servisní úroveň, zakázkové integrace a dlouhodobý rozvoj se naceňují samostatně. Všechny ceny jsou bez DPH.", 42, 82, 876, pstyle(8.7, MUTED, "GereqSans", 12, TA_CENTER))
    footer(c)
    c.showPage()


def page_9(c: canvas.Canvas) -> None:
    page_base(c, 13, dark=True, section="DALŠÍ KROK")
    c.setFillColor(SIGNAL)
    c.roundRect(42, 404, 126, 25, 6, fill=1, stroke=0)
    c.setFont("GereqMono", 8.5)
    c.setFillColor(INK)
    c.drawCentredString(105, 413, "15 MINUT NA ÚVOD")
    title(c, "Začněme jedním procesem, který dnes firmu brzdí.", 42, 372, 680, dark=True, size=35)
    para(c, "Společně ověříme, zda dává smysl hotová sestava modulů, integrační sprint, nebo širší implementace platformy.", 42, 260, 690, pstyle(14, HexColor("#B4B8AE"), "GereqSans", 21))
    c.setStrokeColor(HexColor("#2C2F2A"))
    c.line(42, 183, 918, 183)
    c.setFont("GereqSans-Bold", 17)
    c.setFillColor(WHITE)
    c.drawString(42, 145, "hello@gereq.cz")
    c.drawString(330, 145, "gereq.cz")
    c.setFont("GereqSans", 10)
    c.setFillColor(HexColor("#B4B8AE"))
    c.drawString(42, 116, "Jiří Grygerek - návrh, vývoj a provoz digitálních systémů")
    c.drawString(42, 90, "IČO 23216930")
    footer(c, dark=True)
    c.showPage()


def main() -> None:
    register_fonts()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    c = canvas.Canvas(str(OUT), pagesize=(PAGE_W, PAGE_H), pageCompression=1)
    c.setTitle("GEREQ Platform - prezentace a orientační ceník 2026")
    c.setAuthor("GEREQ - Jiří Grygerek")
    c.setSubject("Modulární platforma pro řízení firmy, provozu a digitálních služeb")
    page_1(c)
    ad_page(c, 2, "gereq-ads-b-01-mene-prace.png", "Výsledek před funkcemi", "GEREQ odstraňuje provozní tření. Začíná u práce lidí, ne u seznamu technologií.")
    page_problem_flow(c)
    page_3(c)
    ad_page(c, 5, "gereq-ads-b-10-automatizace.png", "Propojený provoz", "Objednávka, zákazník, sklad, doklad i notifikace mohou být součástí jednoho toku.")
    page_module_map(c)
    page_5(c)
    ad_page(c, 8, "gereq-ads-b-06-eshop-erp.png", "Jedna z možných sestav", "E-shop je pouze jedna vrstva. GEREQ propojuje prodej s procesy, které pokračují za checkoutem.")
    page_6(c)
    ad_page(c, 10, "gereq-ads-b-11-navrh-provoz.png", "Dlouhodobý partner", "Návrhem spolupráce nekončíme. Součástí řešení je spuštění, provoz, podpora a další rozvoj.")
    page_7(c)
    page_8(c)
    page_9(c)
    c.save()
    print(OUT)


if __name__ == "__main__":
    main()
