/**
 * Full-page demos that showcase layout container variants (task #13).
 * Returned trees use nested `children` on layout blocks.
 */

import {
  layoutVariantPayload,
  type LayoutVariantId,
} from "./cmsLayoutPresets";

export type TemplateNode = {
  type: string;
  payload?: Record<string, unknown>;
  children?: TemplateNode[];
};

export type LayoutPageTemplateId = "atelier" | "launch" | "editorial";

export type LayoutPageTemplateMeta = {
  id: LayoutPageTemplateId;
  /** boot.labels key */
  labelKey: string;
  descKey: string;
  tag: string;
};

export const LAYOUT_PAGE_TEMPLATE_META: LayoutPageTemplateMeta[] = [
  {
    id: "atelier",
    labelKey: "layoutPageAtelier",
    descKey: "layoutPageAtelierDesc",
    tag: "portfolio · image · cards",
  },
  {
    id: "launch",
    labelKey: "layoutPageLaunch",
    descKey: "layoutPageLaunchDesc",
    tag: "product · cta · grid",
  },
  {
    id: "editorial",
    labelKey: "layoutPageEditorial",
    descKey: "layoutPageEditorialDesc",
    tag: "magazine · nested · row",
  },
];

const IMG = {
  loft: "https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1600&q=80",
  studio: "https://images.unsplash.com/photo-1497366216548-37526070297c?w=1600&q=80",
  desk: "https://images.unsplash.com/photo-1497215728101-856f4ea42174?w=1600&q=80",
  craft: "https://images.unsplash.com/photo-1454165804606-c3d57bc86b40?w=1600&q=80",
  city: "https://images.unsplash.com/photo-1486406146926-c627a92ad1ab?w=1600&q=80",
  cafe: "https://images.unsplash.com/photo-1554118811-1e0d58224f24?w=1200&q=80",
  paper: "https://images.unsplash.com/photo-1456513080080-7e3f1dfb647e?w=1200&q=80",
  meet: "https://images.unsplash.com/photo-1522071820081-009f0129c71c?w=1600&q=80",
};

function heading(text: string, level = 2, extra: Record<string, unknown> = {}): TemplateNode {
  return { type: "heading", payload: { text, level, ...extra } };
}

function text(body: string, extra: Record<string, unknown> = {}): TemplateNode {
  return { type: "text", payload: { body, ...extra } };
}

function cta(label: string, url = "#", style: "primary" | "ghost" = "primary"): TemplateNode {
  return { type: "cta", payload: { label, url, style } };
}

function image(url: string, alt: string, extra: Record<string, unknown> = {}): TemplateNode {
  return {
    type: "image",
    payload: { media_id: "", url, alt, caption: "", img_fit: "cover", ...extra },
  };
}

function quote(q: string, author: string, role = ""): TemplateNode {
  return { type: "quote", payload: { quote: q, author, role } };
}

function divider(style = "solid"): TemplateNode {
  return { type: "divider", payload: { style } };
}

function layout(
  variant: LayoutVariantId,
  children: TemplateNode[],
  extra: Record<string, unknown> = {},
): TemplateNode {
  return {
    type: "layout",
    payload: {
      ...layoutVariantPayload(variant),
      ...extra,
    },
    children,
  };
}

/** Nested stack used as a “card” inside cards/grid. */
function card(title: string, body: string, linkLabel?: string): TemplateNode {
  const kids: TemplateNode[] = [
    heading(title, 3),
    text(body),
  ];
  if (linkLabel) kids.push(cta(linkLabel, "#", "ghost"));
  return layout("stack", kids, { admin_label: title, gap: 10, padding: 8 });
}

function atelierPage(): TemplateNode[] {
  return [
    layout(
      "hero",
      [
        text("Ateliér · typografie · prostor", { align: "center" }),
        heading("Prostor, který mluví tiše a jasně", 1, { align: "center" }),
        text(
          "Ukázková stránka postavená na typech kontejnerů: hero, obrázek+text, karty a CTA. Vše lze v builderu přepínat a skládat dál.",
          { align: "center" },
        ),
        cta("Prohlédnout práci", "#work"),
      ],
      { admin_label: "Hero", min_height: 380, gap: 18 },
    ),

    divider("about"),

    layout(
      "image",
      [
        image(IMG.loft, "Světlý ateliér", {
          flex_basis: "48",
          flex_grow: "0",
          flex_shrink: "0",
        }),
        layout(
          "stack",
          [
            text("Studio"),
            heading("Světlo, rytmus, materiál", 2),
            text(
              "Kontejner typu „Obrázek + text“ skládá dvě strany vedle sebe. Vnitřní sloupec drží typografii; strany prohodíte tlačítkem ve vlastnostech kontejneru.",
            ),
            cta("Jak skládáme layout", "#", "ghost"),
          ],
          { admin_label: "Textová strana", gap: 12, flex_grow: "1", flex_basis: "0" },
        ),
      ],
      { admin_label: "Split A · foto vlevo", gap: 28, align_items: "center" },
    ),

    layout(
      "image",
      [
        layout(
          "stack",
          [
            text("Proces"),
            heading("Od skici k realizaci", 2),
            text(
              "Stejný typ kontejneru, jen obsah prohozený — foto vpravo. Žádný nový blok: jen jiné pořadí dětí.",
            ),
            cta("Naše metoda", "#", "ghost"),
          ],
          { admin_label: "Textová strana", gap: 12, flex_grow: "1", flex_basis: "0" },
        ),
        image(IMG.desk, "Pracovní stůl", {
          flex_basis: "48",
          flex_grow: "0",
          flex_shrink: "0",
        }),
      ],
      { admin_label: "Split B · foto vpravo", gap: 28, align_items: "center" },
    ),

    divider("services"),

    layout(
      "stack",
      [
        heading("Tři pilíře praxe", 2, { align: "center" }),
        text("Kontejner „Karty“ = mřížka 3 sloupců. Každá karta je zanořený sloupec.", {
          align: "center",
        }),
        layout(
          "cards",
          [
            card("Koncept", "Mapujeme cíl, tón a omezení dřív, než vznikne první vizuál.", "Detail"),
            card("Kompozice", "Kontejnery drží rytmus stránky — ne náhodné mezery.", "Detail"),
            card("Dodání", "Čistá struktura v CMS, kterou klient dál spravuje sám.", "Detail"),
          ],
          { admin_label: "Karty služeb", gap: 20 },
        ),
      ],
      { admin_label: "Sekce karet", gap: 20 },
    ),

    layout(
      "cta",
      [
        heading("Chcete podobnou skladbu?", 2, { align: "center" }),
        text("Vložte šablonu, přepínejte typ kontejneru a upravte obsah — struktura zůstane.", {
          align: "center",
        }),
        cta("Domluvit konzultaci", "/kontakt"),
      ],
      { admin_label: "Závěrečné CTA", gap: 14, min_height: 200 },
    ),
  ];
}

function launchPage(): TemplateNode[] {
  return [
    layout(
      "hero",
      [
        text("Product launch · demo", { align: "center" }),
        heading("Jedna stránka. Jasná nabídka.", 1, { align: "center" }),
        text(
          "Launch šablona ukazuje CTA kontejnery, mřížky a řádkové skupiny — ideální pro produkt nebo službu.",
          { align: "center" },
        ),
        layout(
          "row",
          [
            cta("Začít zdarma", "#"),
            cta("Podívat se na demo", "#", "ghost"),
          ],
          {
            admin_label: "CTA řádek",
            justify: "center",
            gap: 12,
            align_items: "center",
          },
        ),
      ],
      { admin_label: "Hero launch", min_height: 360 },
    ),

    divider("trust"),

    layout(
      "grid-3",
      [
        card("Rychlý start", "Preset kontejneru nastaví flex/grid — nemusíte skládat od nuly."),
        card("Vnořené bloky", "Do kontejneru patří text, CTA, obrázek i další kontejner."),
        card("Stejný jazyk", "Stejné typy jako u galerie: vyberete účel, doladíte detaily."),
      ],
      { admin_label: "Trust mřížka", gap: 18 },
    ),

    layout(
      "image",
      [
        image(IMG.city, "Produkt v kontextu", {
          flex_basis: "50",
          flex_grow: "0",
          flex_shrink: "0",
        }),
        layout(
          "stack",
          [
            text("Proč to sedí"),
            heading("Struktura místo chaosu", 2),
            text(
              "Místo desítek volných bloků pod sebou používáte kontejnery s jasným účelem. Editor i klient vidí, co kam patří.",
            ),
            cta("Jak na to v builderu", "#", "ghost"),
          ],
          { gap: 12, flex_grow: "1", flex_basis: "0" },
        ),
      ],
      { admin_label: "Feature split", gap: 28 },
    ),

    divider("types"),

    layout(
      "stack",
      [
        heading("Tarify jako karty", 2, { align: "center" }),
        text("Tři zanořené sloupce v mřížce — typický pricing pattern.", { align: "center" }),
        layout(
          "grid-3",
          [
            layout(
              "stack",
              [
                heading("Start", 3),
                text("Pro malé weby a první experimenty s kontejnery."),
                text("0 Kč"),
                cta("Vybrat", "#", "ghost"),
              ],
              { admin_label: "Tarif Start", gap: 10 },
            ),
            layout(
              "stack",
              [
                heading("Studio", 3),
                text("Víc sekcí, galerie a připravené page šablony."),
                text("1 490 Kč"),
                cta("Vybrat", "#"),
              ],
              { admin_label: "Tarif Studio", gap: 10 },
            ),
            layout(
              "stack",
              [
                heading("Agency", 3),
                text("Týmy, partials a opakovatelné layout vzory."),
                text("na míru"),
                cta("Kontakt", "#", "ghost"),
              ],
              { admin_label: "Tarif Agency", gap: 10 },
            ),
          ],
          { admin_label: "Pricing", gap: 18 },
        ),
      ],
      { admin_label: "Pricing sekce", gap: 18 },
    ),

    layout(
      "cta",
      [
        heading("Připraveni spustit?", 2, { align: "center" }),
        text("Vložte šablonu Launch, uložte stránku a publikujte z topbaru.", {
          align: "center",
        }),
        cta("Vyzkoušet builder", "#"),
      ],
      { admin_label: "Bottom CTA", min_height: 220 },
    ),

    layout(
      "row",
      [
        layout("stack", [heading("Podpora", 4), text("docs@example.com")], { gap: 6 }),
        layout("stack", [heading("Sales", 4), text("+420 777 000 111")], { gap: 6 }),
        layout("stack", [heading("Status", 4), text("Všechny systémy v pořádku")], { gap: 6 }),
      ],
      { admin_label: "Kontaktní řádek", gap: 24, justify: "space-between" },
    ),
  ];
}

function editorialPage(): TemplateNode[] {
  return [
    layout(
      "hero",
      [
        text("Revue · jaro", { align: "center" }),
        heading("Číst pomalu. Skládat přesně.", 1, { align: "center" }),
        text(
          "Editoriální demo: vnořené kontejnery, dvou sloupcový grid a řádkové patičky — jako časopis, ale v CMS.",
          { align: "center" },
        ),
      ],
      { admin_label: "Editorial hero", min_height: 300, gap: 14 },
    ),

    divider("insights"),

    layout(
      "image",
      [
        image(IMG.paper, "Detail tisku", {
          flex_basis: "42",
          flex_grow: "0",
          flex_shrink: "0",
        }),
        layout(
          "stack",
          [
            text("Úvodník"),
            heading("Proč kontejnery mění skladbu stránky", 2),
            text(
              "Místo lineárního seznamu bloků skládáte významové celky. Hero, sloupec článku, mřížka tipů — každý má vlastní typ kontejneru.",
            ),
            text(
              "Vrstvy vpravo ukazují zanoření: kontejner · typ. Pojmenujte si bloky (admin label), ať se v tom orientujete.",
            ),
          ],
          { gap: 12, flex_grow: "1", flex_basis: "0" },
        ),
      ],
      { admin_label: "Featured story", gap: 28, align_items: "start" },
    ),

    layout(
      "stack",
      [
        heading("Z čísla", 2),
        layout(
          "grid-2",
          [
            layout(
              "stack",
              [
                image(IMG.cafe, "Kavárna"),
                heading("Ranní rituály v typografii", 3),
                text("Krátký sloupek o rytmu nadpisů a mezerách mezi sekcemi."),
                cta("Číst dál", "#", "ghost"),
              ],
              { admin_label: "Článek A", gap: 10 },
            ),
            layout(
              "stack",
              [
                image(IMG.meet, "Tým"),
                heading("Jak editoři skládají homepage", 3),
                text("Praktický pohled na nested layouty a proč méně bloků znamená víc kontroly."),
                cta("Číst dál", "#", "ghost"),
              ],
              { admin_label: "Článek B", gap: 10 },
            ),
          ],
          { admin_label: "Dvojice článků", gap: 22 },
        ),
      ],
      { admin_label: "Feed", gap: 16 },
    ),

    layout(
      "stack",
      [
        quote(
          "Když má každá sekce svůj kontejner, přestane být stránka hromadou odstavců a začne být kompozicí.",
          "Eliška K.",
          "Art director",
        ),
      ],
      { admin_label: "Citace", gap: 8, align_items: "center" },
    ),

    layout(
      "grid-3",
      [
        card("Tip 01", "Hero kontejner na úvod — jeden účel, jeden CTA."),
        card("Tip 02", "Obrázek+text na příběh — prohoďte strany bez nového layoutu."),
        card("Tip 03", "Karty / grid na výčty — každá karta jako malý stack."),
      ],
      { admin_label: "Tipy v mřížce", gap: 16 },
    ),

    layout(
      "cta",
      [
        heading("Newsletter revue", 2, { align: "center" }),
        text("Jednou měsíčně skladba, typografie a nové vzory kontejnerů.", {
          align: "center",
        }),
        cta("Odebírat", "#"),
      ],
      { admin_label: "Newsletter CTA", min_height: 200 },
    ),

    layout(
      "row",
      [
        text("© Revue demo"),
        text("Kontejnery · CMS"),
        cta("Zpět nahoru", "#", "ghost"),
      ],
      {
        admin_label: "Patička",
        justify: "space-between",
        align_items: "center",
        gap: 16,
      },
    ),
  ];
}

export function buildLayoutPageTemplate(id: LayoutPageTemplateId): TemplateNode[] {
  switch (id) {
    case "atelier":
      return atelierPage();
    case "launch":
      return launchPage();
    case "editorial":
      return editorialPage();
    default:
      return atelierPage();
  }
}

/** Schematic node for hover preview (rendered with brand CSS vars). */
export type WireNode = {
  t: string;
  /** Stable Alpine x-for key (set by stampWireKeys). */
  k?: string;
  kids?: WireNode[];
};

/** Assign unique `k` for Alpine CSP x-for (duplicate `t` is common). */
export function stampWireKeys(nodes: WireNode[], prefix = "w"): WireNode[] {
  return nodes.map((node, i) => {
    const k = `${prefix}${i}`;
    return {
      t: node.t,
      k,
      kids: node.kids ? stampWireKeys(node.kids, `${k}-`) : undefined,
    };
  });
}

export function templateToWire(nodes: TemplateNode[]): WireNode[] {
  return nodes.slice(0, 12).map((node) => {
    if (node.type === "layout") {
      const variant = String(node.payload?.variant || "stack");
      return {
        t: `layout-${variant}`,
        kids: templateToWire(node.children || []).slice(0, 6),
      };
    }
    return { t: node.type };
  });
}

/** Compact wireframes for section presets (no full tree). */
export function sectionToWire(kind: string): WireNode[] {
  switch (kind) {
    case "hero":
      return [
        { t: "layout-hero", kids: [{ t: "text" }, { t: "heading" }, { t: "text" }, { t: "image" }] },
      ];
    case "trust":
      return [
        { t: "layout-grid-3", kids: [{ t: "heading" }, { t: "heading" }, { t: "heading" }] },
      ];
    case "listings":
      return [{ t: "heading" }, { t: "text" }];
    case "why":
      return [
        {
          t: "layout-image",
          kids: [{ t: "image" }, { t: "layout-stack", kids: [{ t: "heading" }, { t: "text" }, { t: "cta" }] }],
        },
      ];
    case "services":
    case "types":
      return [
        { t: "heading" },
        { t: "layout-grid-2", kids: [{ t: "layout-stack" }, { t: "layout-stack" }] },
      ];
    case "how":
      return [
        { t: "heading" },
        { t: "layout-row", kids: [{ t: "heading" }, { t: "heading" }, { t: "heading" }] },
      ];
    case "testimonials":
    case "quote":
      return [{ t: "heading" }, { t: "quote" }];
    case "insights":
      return [
        { t: "heading" },
        { t: "layout-grid-2", kids: [{ t: "layout-stack" }, { t: "layout-stack" }] },
      ];
    case "cta":
      return [{ t: "layout-cta", kids: [{ t: "heading" }, { t: "text" }, { t: "cta" }] }];
    case "contact":
      return [
        { t: "layout-row", kids: [{ t: "layout-stack" }, { t: "layout-stack" }, { t: "layout-stack" }] },
      ];
    case "footer":
      return [{ t: "layout-row", kids: [{ t: "text" }, { t: "text" }, { t: "cta" }] }];
    case "faq":
      return [
        { t: "heading" },
        { t: "layout-stack", kids: [{ t: "heading" }, { t: "text" }, { t: "heading" }, { t: "text" }] },
      ];
    case "about":
      return [
        {
          t: "layout-image",
          kids: [{ t: "image" }, { t: "layout-stack", kids: [{ t: "heading" }, { t: "text" }] }],
        },
      ];
    default:
      return [{ t: "layout-stack", kids: [{ t: "heading" }, { t: "text" }] }];
  }
}

export function layoutPageWire(id: LayoutPageTemplateId): WireNode[] {
  return templateToWire(buildLayoutPageTemplate(id));
}
