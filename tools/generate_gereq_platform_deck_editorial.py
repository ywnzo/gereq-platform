from __future__ import annotations

import math
from io import BytesIO
from pathlib import Path

from PIL import Image
from fontTools.ttLib import TTFont as FTFont
from fontTools.varLib.instancer import instantiateVariableFont
from reportlab.lib.colors import Color, HexColor
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.utils import ImageReader
from reportlab.pdfbase import pdfmetrics
from reportlab.pdfbase.ttfonts import TTFont
from reportlab.pdfgen import canvas
from reportlab.platypus import Paragraph


ROOT = Path(__file__).resolve().parents[1]
SITE = Path(r"V:\home\dev\projects\GEREQ.cz")
BRAND = SITE / ".brand"
OUT = ROOT / "output" / "pdf" / "GEREQ_Platform_prezentace_a_cenik_2026.pdf"

W, H = 960, 540
INK = HexColor("#0A0B0A")
PAPER = HexColor("#F5F5EF")
SIGNAL = HexColor("#D7FF45")
MUTED = HexColor("#6B7067")
BORDER = HexColor("#DADDD4")
WHITE = HexColor("#FFFFFF")
WASH = HexColor("#ECEEE7")
SKY = HexColor("#72D6FF")
CORAL = HexColor("#FF6B5E")


def register_fonts() -> None:
    font_dir = ROOT / "tmp" / "pdfs" / "fonts"
    font_dir.mkdir(parents=True, exist_ok=True)

    def static_instance(source: Path, target: Path, weight: int) -> Path:
        variable = FTFont(str(source))
        static = instantiateVariableFont(variable, {"wght": weight}, inplace=False)
        static.save(str(target))
        return target

    space = static_instance(
        SITE / "resources" / "fonts" / "SpaceGrotesk.ttf",
        font_dir / "SpaceGrotesk-Semibold.ttf",
        600,
    )
    dm = static_instance(
        SITE / "resources" / "fonts" / "DMSans.ttf",
        font_dir / "DMSans-Regular.ttf",
        400,
    )
    pdfmetrics.registerFont(TTFont("Space", str(space)))
    pdfmetrics.registerFont(TTFont("DM", str(dm)))
    pdfmetrics.registerFont(TTFont("Mono", r"C:\Windows\Fonts\consola.ttf"))


def style(size: float, color=INK, font="DM", leading=None, align=TA_LEFT) -> ParagraphStyle:
    return ParagraphStyle(
        f"s-{font}-{size}", fontName=font, fontSize=size,
        leading=leading or size * 1.35, textColor=color, alignment=align,
        spaceBefore=0, spaceAfter=0,
    )


def text(c: canvas.Canvas, value: str, x: float, top: float, width: float, s: ParagraphStyle, max_h=300) -> float:
    p = Paragraph(value, s)
    _, h = p.wrap(width, max_h)
    p.drawOn(c, x, top - h)
    return h


def logo_reader(light=False) -> ImageReader:
    src = Image.open(BRAND / "logos" / "gereq-logo-header.png").convert("RGBA")
    if light:
        px = src.load()
        for y in range(src.height):
            for x in range(src.width):
                r, g, b, a = px[x, y]
                if a and r < 110 and g < 110 and b < 110:
                    px[x, y] = (245, 245, 239, a)
    buf = BytesIO()
    src.save(buf, format="PNG")
    buf.seek(0)
    return ImageReader(buf)


def draw_logo(c: canvas.Canvas, x: float, y: float, width: float, light=False) -> None:
    img = logo_reader(light)
    iw, ih = img.getSize()
    c.drawImage(img, x, y, width=width, height=width * ih / iw, mask="auto")


def line(c: canvas.Canvas, x1, y1, x2, y2, color=BORDER, width=0.75) -> None:
    c.setStrokeColor(color)
    c.setLineWidth(width)
    c.line(x1, y1, x2, y2)


def base(c: canvas.Canvas, page: int, label: str, dark=False, signal=False) -> None:
    bg = SIGNAL if signal else (INK if dark else PAPER)
    fg = INK if signal else (PAPER if dark else INK)
    c.setFillColor(bg)
    c.rect(0, 0, W, H, fill=1, stroke=0)
    draw_logo(c, 42, 498, 92, light=dark and not signal)
    c.setFont("Mono", 7.5)
    c.setFillColor(fg if signal else (HexColor("#C5C9BD") if dark else MUTED))
    c.drawString(160, 511, label.upper())
    c.drawRightString(918, 511, f"{page:02d} / 10")
    line(c, 42, 490, 918, 490, INK if signal else (HexColor("#3A3D38") if dark else BORDER))


def footer(c: canvas.Canvas, dark=False, signal=False) -> None:
    fg = INK if signal else (HexColor("#C5C9BD") if dark else MUTED)
    border = INK if signal else (HexColor("#3A3D38") if dark else BORDER)
    line(c, 42, 29, 918, 29, border)
    c.setFont("Mono", 7)
    c.setFillColor(fg)
    c.drawString(42, 14, "GEREQ - DIGITAL SYSTEMS BY JIŘÍ GRYGEREK")
    c.drawRightString(918, 14, "GEREQ.CZ")


def kicker(c: canvas.Canvas, value: str, x: float, y: float, dark=False, signal=False) -> None:
    c.setFont("Mono", 8)
    c.setFillColor(INK if signal else (SIGNAL if dark else MUTED))
    c.drawString(x, y, value.upper())


def heading(c: canvas.Canvas, value: str, x: float, top: float, width: float, size=32, dark=False, signal=False) -> float:
    color = INK if signal else (PAPER if dark else INK)
    return text(c, value, x, top, width, style(size, color, "Space", size * 1.05))


def button(c: canvas.Canvas, label: str, x: float, y: float, w: float, primary=True, dark=False) -> None:
    fill = SIGNAL if primary else (INK if dark else WHITE)
    stroke = SIGNAL if primary else (HexColor("#3A3D38") if dark else BORDER)
    fg = INK if primary else (PAPER if dark else INK)
    c.setFillColor(fill)
    c.setStrokeColor(stroke)
    c.roundRect(x, y, w, 35, 7, fill=1, stroke=1)
    c.setFont("DM", 9)
    c.setFillColor(fg)
    c.drawCentredString(x + w / 2, y + 12, label)


def organic_network(c: canvas.Canvas, cx: float, cy: float, scale=1.0, dark=False) -> None:
    nodes = [
        ("WEB", -142, 1.00), ("PORTÁL", -92, .94), ("E-SHOP", -34, .98), ("APLIKACE", 18, .88),
        ("CMS", 66, .96), ("CRM", 116, .90), ("ERP", 160, .95), ("API", -178, .92),
    ]
    rx, ry = 156 * scale, 112 * scale
    fg = PAPER if dark else INK
    for idx, (label, angle, radial) in enumerate(nodes):
        rad = math.radians(angle)
        x = cx + rx * radial * math.cos(rad)
        y = cy + ry * radial * math.sin(rad)
        dx, dy = x - cx, y - cy
        c.setStrokeColor(Color(fg.red, fg.green, fg.blue, 0.22))
        c.setLineWidth(1)
        c.bezier(cx, cy, cx + dx * .28, cy + dy * .12, cx + dx * .72, cy + dy * .88, x, y)
        c.setFillColor(PAPER if not dark else INK)
        c.setStrokeColor(Color(fg.red, fg.green, fg.blue, 0.22))
        c.circle(x, y, 8 * scale, fill=1, stroke=1)
        c.setFillColor(fg)
        c.circle(x, y, 3.2 * scale, fill=1, stroke=0)
        c.setFont("Mono", 6.5 * scale)
        tx = x + (13 * scale if dx >= 0 else -13 * scale)
        ty = y - 2
        if abs(dy) > abs(dx) * .9:
            c.drawCentredString(x, y + (15 if dy > 0 else -20) * scale, label)
        elif dx >= 0:
            c.drawString(tx, ty, label)
        else:
            c.drawRightString(tx, ty, label)
    c.setFillColor(Color(SIGNAL.red, SIGNAL.green, SIGNAL.blue, .20))
    c.circle(cx, cy, 43 * scale, fill=1, stroke=0)
    c.setFillColor(PAPER if not dark else INK)
    c.setStrokeColor(Color(fg.red, fg.green, fg.blue, .12))
    c.circle(cx, cy, 34 * scale, fill=1, stroke=1)
    c.setFillColor(fg)
    c.setFont("Space", 19 * scale)
    c.drawCentredString(cx, cy - 3, "G")
    c.setFillColor(SIGNAL)
    c.rect(cx - 3, cy - 12 * scale, 17 * scale, 4 * scale, fill=1, stroke=0)


def page_1(c: canvas.Canvas) -> None:
    base(c, 1, "GEREQ PLATFORM")
    kicker(c, "MODULÁRNÍ FIREMNÍ SYSTÉM", 42, 453)
    heading(c, "Jeden systém.<br/>Přesně tolik funkcí,<br/>kolik potřebujete.", 42, 422, 500, 39)
    text(c, "GEREQ propojuje zákazníky, zakázky, dokumenty, obchod, sklad i externí služby. Začnete menší sestavou a platforma poroste společně s firmou.", 42, 270, 470, style(13, MUTED, "DM", 19))
    button(c, "PROBRAT VÁŠ PROCES", 42, 188, 172, True)
    button(c, "PROHLÉDNOUT SESTAVY", 226, 188, 187, False)
    organic_network(c, 726, 302, .93)
    stats = [("35+", "FUNKČNÍCH MODULŮ"), ("1", "STABILNÍ JÁDRO"), ("API", "EVENTS + WEBHOOKS"), ("CZ / EN", "PŘIPRAVENÉ JAZYKY")]
    x0, y, cell_w = 42, 61, 219
    c.setFillColor(WHITE)
    c.rect(x0, y, cell_w * 4, 78, fill=1, stroke=0)
    for i, (value, label) in enumerate(stats):
        x = x0 + i * cell_w
        c.setStrokeColor(BORDER)
        c.rect(x, y, cell_w, 78, fill=0, stroke=1)
        c.setFont("Space", 17)
        c.setFillColor(INK)
        c.drawString(x + 15, y + 43, value)
        c.setFont("Mono", 7)
        c.setFillColor(MUTED)
        c.drawString(x + 15, y + 20, label)
    footer(c)
    c.showPage()


def page_2(c: canvas.Canvas) -> None:
    base(c, 2, "PROČ PLATFORMa", signal=True)
    kicker(c, "01 / VÝSLEDEK", 42, 454, signal=True)
    heading(c, "Firma nemá pracovat pro software.<br/>Software má pracovat pro firmu.", 42, 423, 770, 36, signal=True)
    text(c, "Modularita není technická hračka. Je to způsob, jak nepřeplácet funkce, které nepotřebujete, a přitom si nezavřít cestu k dalšímu růstu.", 42, 322, 760, style(13, Color(0.04, .04, .04, .78), "DM", 19))
    items = [
        ("01", "Méně ruční práce", "Data se nepřepisují mezi tabulkami, e-maily a samostatnými aplikacemi."),
        ("02", "Jeden zdroj pravdy", "Zákazník, zakázka, dokument i stav procesu mají jasné místo."),
        ("03", "Rozšiřování po částech", "Nový sklad, pobočka nebo služba neznamenají výměnu celého systému."),
        ("04", "Kontrola nad řešením", "Role, audit, API, dokumentace a možnost vlastního provozu."),
    ]
    for i, (num, title, body) in enumerate(items):
        col, row = i % 2, i // 2
        x, y = 42 + col * 438, 168 - row * 105
        c.setFillColor(INK)
        c.rect(x, y, 424, 93, fill=1, stroke=0)
        c.setFillColor(PAPER)
        c.setFont("Mono", 8)
        c.drawString(x + 16, y + 68, num)
        c.setFont("Space", 14)
        c.drawString(x + 58, y + 65, title)
        text(c, body, x + 58, y + 45, 340, style(9.2, HexColor("#C5C9BD"), "DM", 13), 38)
    footer(c, signal=True)
    c.showPage()


def page_3(c: canvas.Canvas) -> None:
    base(c, 3, "ARCHITEKTURA")
    kicker(c, "02 / STABILNÍ JÁDRO", 42, 454)
    heading(c, "Jedna platforma.<br/>Mnoho podob systému.", 42, 423, 430, 35)
    text(c, "Core řeší společné věci jednou. Business logika zůstává v samostatných modulech. Výsledkem je řešení, které lze skládat, aktualizovat a dále rozvíjet.", 42, 319, 410, style(11.5, MUTED, "DM", 17))
    points = [
        ("01", "CORE", "Uživatelé, role, audit, soubory, nastavení, API, události a fronty."),
        ("02", "MODULY", "CRM, projekty, produkty, dokumenty, objednávky, sklady nebo CMS."),
        ("03", "INTEGRACE", "Účetnictví, platby, doprava, kalendáře a další externí služby."),
    ]
    for i, (num, name, body) in enumerate(points):
        y = 211 - i * 57
        line(c, 42, y + 38, 433, y + 38)
        c.setFont("Mono", 8)
        c.setFillColor(MUTED)
        c.drawString(42, y + 18, num)
        c.setFillColor(INK)
        c.drawString(78, y + 18, name)
        text(c, body, 170, y + 28, 260, style(8.6, MUTED, "DM", 12), 31)
    organic_network(c, 710, 263, 1.12)
    footer(c)
    c.showPage()


def page_4(c: canvas.Canvas) -> None:
    base(c, 4, "KATALOG MODULŮ", dark=True)
    kicker(c, "03 / CO LZE SESTAVIT", 42, 454, dark=True)
    heading(c, "Funkce podle procesu. Ne podle škatulky.", 42, 423, 760, 34, dark=True)
    text(c, "Platforma obsahuje obecné moduly i oborové vrstvy. Následující přehled ukazuje typické kombinace.", 42, 366, 720, style(11.5, HexColor("#C5C9BD"), "DM", 17))
    groups = [
        ("ZÁKAZNÍCI", "Customers / CRM / Calendar / Projects / Notifications"),
        ("OBCHOD", "Products / Orders / Payments / Coupons / Loyalty / Reviews"),
        ("PROVOZ", "Warehouse / Documents / ESign / Media / Currency / Webhooks"),
        ("E-COMMERCE", "Shop / Feeds / Bundles / Discounts / Returns / Uploads"),
        ("SERVIS", "Fleet / CarService / BikeService / PartsCatalog / SearchEngines"),
        ("REALITY", "Listings / Viewings / Portals / CRM / Documents / Newsletter"),
    ]
    y0 = 315
    for i, (group, modules) in enumerate(groups):
        y = y0 - i * 46
        line(c, 42, y + 31, 918, y + 31, HexColor("#3A3D38"))
        c.setFont("Mono", 8)
        c.setFillColor(SIGNAL if i in (3, 4, 5) else SKY)
        c.drawString(42, y + 10, f"0{i+1}")
        c.setFont("Space", 12.5)
        c.setFillColor(PAPER)
        c.drawString(92, y + 8, group)
        c.setFont("Mono", 8.2)
        c.setFillColor(HexColor("#C5C9BD"))
        c.drawString(272, y + 9, modules)
    c.setFillColor(SIGNAL)
    c.rect(42, 52, 876, 38, fill=1, stroke=0)
    c.setFont("DM", 10)
    c.setFillColor(INK)
    c.drawCentredString(480, 66, "Každá instalace začíná pouze moduly, které mají v daném provozu jasnou hodnotu.")
    footer(c, dark=True)
    c.showPage()


def solution_block(c: canvas.Canvas, x: float, y: float, w: float, h: float, num: str, name: str, lead: str, modules: str, result: str) -> None:
    c.setFillColor(WHITE)
    c.setStrokeColor(BORDER)
    c.rect(x, y, w, h, fill=1, stroke=1)
    c.setFont("Mono", 7.5)
    c.setFillColor(MUTED)
    c.drawString(x + 16, y + h - 25, num)
    c.setFont("Space", 19)
    c.setFillColor(INK)
    c.drawString(x + 16, y + h - 54, name)
    text(c, lead, x + 16, y + h - 72, w - 32, style(9.5, MUTED, "DM", 13), 45)
    line(c, x + 16, y + 51, x + w - 16, y + 51)
    c.setFont("Mono", 6.8)
    c.setFillColor(MUTED)
    c.drawString(x + 16, y + 39, modules.upper())
    c.setFillColor(SIGNAL)
    c.rect(x + 16, y + 8, w - 32, 22, fill=1, stroke=0)
    c.setFont("DM", 8.3)
    c.setFillColor(INK)
    c.drawString(x + 25, y + 15, result)


def page_5(c: canvas.Canvas) -> None:
    base(c, 5, "PŘÍKLADY SESTAV")
    kicker(c, "04 / JEDNO JÁDRO, RŮZNÉ FIRMY", 42, 454)
    heading(c, "Platforma se skládá podle skutečné práce.", 42, 423, 790, 33)
    text(c, "Oborový balíček je připravená kombinace modulů. Každý lze dále upravit bez opuštění společného základu.", 42, 365, 760, style(11, MUTED, "DM", 16))
    solution_block(c, 42, 202, 424, 142, "01 / SERVIS", "Autoservis", "Zakázky, vozidla, práce, díly, protokoly, podpis a fakturace.", "Customers + Fleet + CarService + PartsCatalog + Documents", "Výsledek: zakázka od přijetí vozidla po fakturu")
    solution_block(c, 494, 202, 424, 142, "02 / COMMERCE", "E-commerce", "Produkty, sklad, checkout, platby, doprava a účetnictví.", "Products + Warehouse + Shop + Orders + Payments", "Výsledek: prodej propojený s provozem")
    solution_block(c, 42, 49, 424, 142, "03 / REALITY", "Realitní kancelář", "Nabídky, kontakty, prohlídky, dokumenty a exporty.", "Listings + CRM + Viewings + Documents + Portals", "Výsledek: nabídka od náběru po prohlídku")
    solution_block(c, 494, 49, 424, 142, "04 / BUSINESS", "Interní řízení", "Zákazníci, obchodní případy, projekty, úkoly a kalendář.", "Customers + CRM + Projects + Calendar + Documents", "Výsledek: jedno místo pro každodenní provoz")
    footer(c)
    c.showPage()


def flow_box(c: canvas.Canvas, x, y, w, label, active=False) -> None:
    c.setFillColor(SIGNAL if active else PAPER)
    c.setStrokeColor(SIGNAL if active else HexColor("#3A3D38"))
    c.rect(x, y, w, 42, fill=1, stroke=1)
    c.setFont("Mono", 7.5)
    c.setFillColor(INK if active else PAPER)
    c.drawCentredString(x + w / 2, y + 16, label.upper())


def page_6(c: canvas.Canvas) -> None:
    base(c, 6, "DATA A INTEGRACE", dark=True)
    kicker(c, "05 / PROPOJENÝ PROVOZ", 42, 454, dark=True)
    heading(c, "Objednávka nekončí tlačítkem Zaplatit.", 42, 423, 780, 34, dark=True)
    text(c, "GEREQ propojí kroky, které dnes často zůstávají mezi e-shopem, skladem, účetnictvím, dopravcem a lidmi.", 42, 366, 720, style(11.5, HexColor("#C5C9BD"), "DM", 17))
    boxes = [("E-SHOP", 42, 258, 126), ("OBJEDNÁVKA", 196, 258, 140), ("SKLAD", 364, 258, 112), ("DOPRAVCE", 504, 258, 126), ("DOKLAD", 658, 258, 112), ("ZÁKAZNÍK", 798, 258, 120)]
    for i, (lab, x, y, w) in enumerate(boxes):
        flow_box(c, x, y, w, lab, active=i in (1, 5))
        if i < len(boxes) - 1:
            nx = boxes[i + 1][1]
            line(c, x + w, y + 21, nx, y + 21, SIGNAL, 1.6)
            c.setFillColor(SIGNAL)
            c.line(nx - 7, y + 25, nx, y + 21)
            c.line(nx - 7, y + 17, nx, y + 21)
    safeguards = [
        ("01", "Chybové stavy", "Integrace má log, stav a možnost opakování."),
        ("02", "Bezpečnost", "Role, audit, 2FA a podepsané webhooky."),
        ("03", "Rozšiřitelnost", "Verzované API a události pro další systémy."),
        ("04", "Provoz", "Monitoring, zálohy a dlouhodobá technická péče."),
    ]
    for i, (num, title, body) in enumerate(safeguards):
        x = 42 + i * 219
        line(c, x, 206, x + 198, 206, HexColor("#3A3D38"))
        c.setFont("Mono", 7.5)
        c.setFillColor(SKY if i % 2 == 0 else SIGNAL)
        c.drawString(x, 184, num)
        c.setFont("Space", 12)
        c.setFillColor(PAPER)
        c.drawString(x, 156, title)
        text(c, body, x, 137, 192, style(8.8, HexColor("#C5C9BD"), "DM", 12), 47)
    footer(c, dark=True)
    c.showPage()


def page_7(c: canvas.Canvas) -> None:
    base(c, 7, "OD MODULU K WORKFLOW", signal=True)
    kicker(c, "06 / PRAKTICKÝ PŘÍKLAD", 42, 454, signal=True)
    heading(c, "Autoservis: jeden záznam provází celou zakázku.", 42, 423, 780, 33, signal=True)
    stages = [
        ("01", "PŘIJETÍ", "Zákazník, vozidlo, stav, fotografie"),
        ("02", "DIAGNOSTIKA", "Požadavky, doporučené práce, kalkulace"),
        ("03", "REALIZACE", "Úkony, díly, čas, náklady, změny"),
        ("04", "PŘEDÁNÍ", "Protokol, podpis, doklad, historie"),
    ]
    for i, (num, name, body) in enumerate(stages):
        x = 42 + i * 219
        c.setFillColor(PAPER)
        c.setStrokeColor(INK)
        c.rect(x, 214, 198, 132, fill=1, stroke=1)
        c.setFillColor(INK)
        c.rect(x, 313, 198, 33, fill=1, stroke=0)
        c.setFont("Mono", 7.5)
        c.setFillColor(SIGNAL)
        c.drawString(x + 12, 325, num)
        c.setFillColor(PAPER)
        c.drawRightString(x + 186, 325, name)
        text(c, body, x + 14, 290, 170, style(10, INK, "DM", 14), 60)
        if i < 3:
            c.setFillColor(INK)
            c.rect(x + 198, 274, 21, 3, fill=1, stroke=0)
    c.setFillColor(INK)
    c.rect(42, 86, 876, 92, fill=1, stroke=0)
    c.setFillColor(PAPER)
    c.setFont("Space", 16)
    c.drawString(62, 137, "Stejný princip funguje pro e-commerce, reality i interní řízení.")
    c.setFont("DM", 9.5)
    c.setFillColor(HexColor("#C5C9BD"))
    c.drawString(62, 111, "Jádro zůstává. Mění se moduly, workflow, oprávnění a integrace podle konkrétního provozu.")
    footer(c, signal=True)
    c.showPage()


def page_8(c: canvas.Canvas) -> None:
    base(c, 8, "CENÍK PLATFORMY")
    kicker(c, "07 / ORIENTAČNÍ INVESTICE", 42, 454)
    heading(c, "Cena za jasně vymezený výsledek.", 42, 423, 720, 34)
    text(c, "Platforma se naceňuje jako implementace, konkrétní moduly a provoz. Začít lze jedním ohraničeným krokem.", 42, 367, 700, style(11.5, MUTED, "DM", 17))
    rows = [
        ("Diagnostika řešení", "Mapa procesu, rizika a doporučená sestava", "10-25 tis. Kč"),
        ("Vlastní modul", "Samostatná funkce včetně nasazení a předání", "od 18 000 Kč"),
        ("Integrace a automatizace", "API, datové toky, logování a chybové stavy", "od 40 000 Kč"),
        ("GEREQ System", "Interní aplikace, portál nebo kompletní řešení", "od 120 000 Kč"),
        ("Spravované prostředí", "Nasazení, monitoring, zálohy a aktualizace", "od 1 490 Kč / měs."),
    ]
    y0 = 313
    for i, (name, desc, price) in enumerate(rows):
        y = y0 - i * 49
        if i == 3:
            c.setFillColor(Color(SIGNAL.red, SIGNAL.green, SIGNAL.blue, .32))
            c.rect(42, y - 9, 876, 47, fill=1, stroke=0)
        line(c, 42, y + 38, 918, y + 38, INK if i == 3 else BORDER, 1 if i == 3 else .7)
        c.setFont("Space", 12)
        c.setFillColor(INK)
        c.drawString(42, y + 11, name)
        c.setFont("DM", 8.7)
        c.setFillColor(MUTED)
        c.drawString(270, y + 12, desc)
        c.setFont("Mono", 8.3)
        c.setFillColor(INK)
        c.drawRightString(918, y + 12, price)
    c.setFillColor(INK)
    c.rect(42, 48, 876, 42, fill=1, stroke=0)
    c.setFont("DM", 8.8)
    c.setFillColor(PAPER)
    c.drawString(58, 64, "Ceny jsou konečné; nejsem plátce DPH. Licence a poplatky třetích stran nejsou zahrnuty.")
    footer(c)
    c.showPage()


def page_9(c: canvas.Canvas) -> None:
    base(c, 9, "SPOLUPRÁCE", dark=True)
    kicker(c, "08 / NEJDŘÍV JASNO, POTOM PRÁCE", 42, 454, dark=True)
    heading(c, "Každá fáze má konkrétní výstup.", 42, 423, 700, 34, dark=True)
    process = [
        ("01", "Krátké zadání", "Současný stav, uživatelé a očekávaný výsledek."),
        ("02", "Návrh rozsahu", "Sestava modulů, integrace, rizika, termín a cena."),
        ("03", "Realizace", "Menší kontrolovatelné celky, demo a průběžné ověření."),
        ("04", "Předání a provoz", "Testy, dokumentace, přístupy, monitoring a další rozvoj."),
    ]
    y = 326
    for i, (num, name, body) in enumerate(process):
        line(c, 42, y + 29, 918, y + 29, HexColor("#3A3D38"))
        c.setFillColor(SIGNAL)
        c.rect(42, y - 2, 38, 24, fill=1, stroke=0)
        c.setFont("Mono", 8)
        c.setFillColor(INK)
        c.drawCentredString(61, y + 6, num)
        c.setFont("Space", 14)
        c.setFillColor(PAPER)
        c.drawString(108, y + 4, name)
        text(c, body, 350, y + 16, 530, style(9.5, HexColor("#C5C9BD"), "DM", 13), 28)
        y -= 61
    c.setFillColor(SIGNAL)
    c.rect(42, 58, 876, 47, fill=1, stroke=0)
    c.setFillColor(INK)
    c.setFont("Space", 13)
    c.drawString(58, 76, "Začínáme nejmenším krokem, který dokáže ověřit hodnotu řešení.")
    footer(c, dark=True)
    c.showPage()


def page_10(c: canvas.Canvas) -> None:
    base(c, 10, "KONTAKT", signal=True)
    kicker(c, "DALŠÍ KROK", 42, 454, signal=True)
    heading(c, "Co dnes ve vaší firmě<br/>funguje jen díky ruční práci?", 42, 416, 760, 42, signal=True)
    text(c, "Stačí stručně popsat současný stav, cílové uživatele a výsledek, kterého chcete dosáhnout. Doporučím vhodnou sestavu platformy nebo menší první krok.", 42, 292, 650, style(13, Color(0.04, .04, .04, .78), "DM", 19))
    c.setFillColor(INK)
    c.rect(42, 132, 876, 90, fill=1, stroke=0)
    c.setFont("Space", 19)
    c.setFillColor(PAPER)
    c.drawString(62, 181, "hello@gereq.cz")
    c.drawString(374, 181, "gereq.cz")
    c.setFont("Mono", 8)
    c.setFillColor(HexColor("#C5C9BD"))
    c.drawString(62, 153, "JIŘÍ GRYGEREK - NÁVRH, VÝVOJ A PROVOZ DIGITÁLNÍCH SYSTÉMŮ")
    c.drawRightString(898, 153, "IČO 23216930")
    button(c, "NEZÁVAZNĚ PROBRAT PROJEKT", 42, 72, 220, False)
    footer(c, signal=True)
    c.showPage()


def main() -> None:
    register_fonts()
    OUT.parent.mkdir(parents=True, exist_ok=True)
    c = canvas.Canvas(str(OUT), pagesize=(W, H), pageCompression=1)
    c.setTitle("GEREQ Platform - prezentace a orientační ceník 2026")
    c.setAuthor("GEREQ - Jiří Grygerek")
    c.setSubject("Modulární platforma pro firemní procesy, systémy a integrace")
    for fn in (page_1, page_2, page_3, page_4, page_5, page_6, page_7, page_8, page_9, page_10):
        fn(c)
    c.save()
    print(OUT)


if __name__ == "__main__":
    main()
