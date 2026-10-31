/** Container purpose presets (keep in sync with cms-lib layoutPresets + PHP LAYOUT_VARIANTS). */

export const LAYOUT_VARIANTS = [
  "stack",
  "row",
  "image",
  "cta",
  "grid-2",
  "grid-3",
  "hero",
  "cards",
] as const;

export type LayoutVariantId = (typeof LAYOUT_VARIANTS)[number];

export type LayoutSeedChild = {
  type: string;
  item?: Record<string, string | number>;
};

export type LayoutPresetDef = {
  display: "flex" | "grid";
  dir: string;
  flex_wrap: string;
  gap: number;
  justify: string;
  align_items: string;
  align_content: string;
  justify_items: string;
  grid_cols: string;
  min_height: number;
  seed?: LayoutSeedChild[];
};

export const LAYOUT_PRESETS: Record<LayoutVariantId, LayoutPresetDef> = {
  stack: {
    display: "flex",
    dir: "column",
    flex_wrap: "nowrap",
    gap: 16,
    justify: "flex-start",
    align_items: "stretch",
    align_content: "stretch",
    justify_items: "stretch",
    grid_cols: "2",
    min_height: 0,
  },
  row: {
    display: "flex",
    dir: "row",
    flex_wrap: "wrap",
    gap: 16,
    justify: "flex-start",
    align_items: "stretch",
    align_content: "stretch",
    justify_items: "stretch",
    grid_cols: "2",
    min_height: 0,
  },
  image: {
    display: "flex",
    dir: "row",
    flex_wrap: "nowrap",
    gap: 24,
    justify: "flex-start",
    align_items: "center",
    align_content: "stretch",
    justify_items: "stretch",
    grid_cols: "2",
    min_height: 0,
    seed: [
      { type: "image", item: { flex_basis: "45", flex_grow: "0", flex_shrink: "0" } },
      { type: "text", item: { flex_grow: "1", flex_basis: "0", flex_shrink: "1" } },
    ],
  },
  cta: {
    display: "flex",
    dir: "column",
    flex_wrap: "nowrap",
    gap: 12,
    justify: "center",
    align_items: "center",
    align_content: "center",
    justify_items: "center",
    grid_cols: "2",
    min_height: 0,
    seed: [
      { type: "heading", item: { align: "center" } },
      { type: "text", item: { align: "center" } },
      { type: "cta" },
    ],
  },
  "grid-2": {
    display: "grid",
    dir: "column",
    flex_wrap: "nowrap",
    gap: 16,
    justify: "stretch",
    align_items: "stretch",
    align_content: "stretch",
    justify_items: "stretch",
    grid_cols: "2",
    min_height: 0,
  },
  "grid-3": {
    display: "grid",
    dir: "column",
    flex_wrap: "nowrap",
    gap: 16,
    justify: "stretch",
    align_items: "stretch",
    align_content: "stretch",
    justify_items: "stretch",
    grid_cols: "3",
    min_height: 0,
  },
  hero: {
    display: "flex",
    dir: "column",
    flex_wrap: "nowrap",
    gap: 20,
    justify: "center",
    align_items: "center",
    align_content: "center",
    justify_items: "center",
    grid_cols: "2",
    min_height: 320,
    seed: [
      { type: "heading", item: { align: "center" } },
      { type: "text", item: { align: "center" } },
      { type: "cta" },
    ],
  },
  cards: {
    display: "grid",
    dir: "column",
    flex_wrap: "nowrap",
    gap: 20,
    justify: "stretch",
    align_items: "stretch",
    align_content: "stretch",
    justify_items: "stretch",
    grid_cols: "3",
    min_height: 0,
  },
};

export function resolveLayoutVariantId(raw: unknown): LayoutVariantId {
  const value = String(raw ?? "stack").trim();
  return (LAYOUT_VARIANTS as readonly string[]).includes(value)
    ? (value as LayoutVariantId)
    : "stack";
}

/** Layout container props from a variant (no seed). */
export function layoutVariantPayload(variant: LayoutVariantId): Record<string, string | number> {
  const preset = LAYOUT_PRESETS[variant];
  return {
    variant,
    display: preset.display,
    dir: preset.dir,
    flex_wrap: preset.flex_wrap,
    wrap: preset.flex_wrap === "nowrap" ? 0 : 1,
    gap: preset.gap,
    justify: preset.justify,
    align_items: preset.align_items,
    align_content: preset.align_content,
    justify_items: preset.justify_items,
    grid_cols: preset.grid_cols,
    min_height: preset.min_height,
  };
}
