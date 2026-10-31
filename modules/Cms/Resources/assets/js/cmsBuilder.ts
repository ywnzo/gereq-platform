import { bindTemplateVariables } from "./cmsTemplateVariables";
import DOMPurify from "dompurify";
import type Alpine from "@alpinejs/csp";
import {
  LAYOUT_PRESETS,
  resolveLayoutVariantId,
  type LayoutVariantId,
} from "./cmsLayoutPresets";
import {
  LAYOUT_PAGE_TEMPLATE_META,
  buildLayoutPageTemplate,
  layoutPageWire,
  sectionToWire,
  stampWireKeys,
  type LayoutPageTemplateId,
  type TemplateNode,
  type WireNode,
} from "./cmsLayoutPageTemplates";

const FORM_CHILD_TYPES = new Set(["form_field", "layout", "text", "heading", "spacer", "divider"]);
const DYNAMIC_CHILD_TYPES = new Set([
  "text", "heading", "html", "layout", "image", "icon", "avatar", "badge", "rating",
  "cta", "quote", "spacer", "divider", "table", "gallery", "video", "columns", "widget",
]);
const ENTITY_FIELDS: Record<string, string[]> = {
  text: ["body"], heading: ["text"], html: ["html"], image: ["url", "alt", "caption"],
  avatar: ["name", "url", "initials"], badge: ["label"], rating: ["value"],
  cta: ["label", "url"], quote: ["quote", "author", "role"], icon: ["title"],
  video: ["url", "caption"], table: ["html", "caption"], columns: ["left", "right"],
};

type TplHoverState = {
  key: string;
  title: string;
  desc: string;
  wire: WireNode[];
  left: number;
  top: number;
};

type BlockPayload = Record<string, string | number | null | BuilderBlock[] | Record<string, unknown> | Record<string, unknown>[]>;

type BuilderBlock = {
  id: string;
  type: string;
  sort_order: number;
  payload: BlockPayload;
  _new?: boolean;
};

type MediaItem = {
  id: string;
  title: string;
  url: string;
  mime: string;
  alt: string;
  width?: number | null;
  height?: number | null;
  created?: number;
};

type DocItem = {
  id: string;
  title: string;
  mime: string;
  url: string;
};

type BuilderVar = {
  path: string;
  label: string;
  description: string;
  group: string;
  kind: "text" | "color" | "image" | "link" | "number";
  example: string;
  token: string;
};

type BuilderPartial = {
  id: string;
  name: string;
  description: string | null;
  block_count: number;
  blocks: Array<{ type: string; payload: Record<string, unknown> }>;
  updated_at?: string;
};

type BuilderBoot = {
  pageId: string;
  title: string;
  slug: string;
  template: string;
  sandbox?: boolean;
  saveUrl: string;
  previewUrl: string;
  storefrontUrl: string;
  exitUrl: string;
  partialsUrl: string;
  partialsListUrl: string;
  partialDeleteUrl: string;
  partialUpdateUrl?: string;
  csrf: string;
  formTestUrl?: string;
  formAttemptsUrl?: string;
  formActions?: Array<{ id: string; label: string; fields: Record<string, string> }>;
  staged?: boolean;
  dynamicSources?: Array<{ id: string; label: string; fields: string[]; template: string; items: Record<string, unknown>[] }>;
  blockTypes: Array<{ value: string; label: string }>;
  blocks: BuilderBlock[];
  icons?: Array<{ name: string; glyph: string }>;
  media: MediaItem[];
  mediaUploadUrl?: string;
  documents: DocItem[];
  variables: BuilderVar[];
  varValues: Record<string, string>;
  themeSaveUrl?: string;
  previewPalette?: { storefront_mode: "light" | "dark"; [key: string]: unknown };
  partials: BuilderPartial[];
  labels: Record<string, string>;
};

type MediaUploadPayload = {
  id?: string;
  title?: string;
  url?: string;
  mime?: string;
  alt?: string;
  width?: number | null;
  height?: number | null;
};

type MediaUploadResponse = {
  data?: MediaUploadPayload;
  id?: string;
  title?: string;
  url?: string;
  mime?: string;
  alt?: string;
  error?: { message?: string };
};

let previewTimer: ReturnType<typeof setTimeout> | undefined;
let patchGateTimer: ReturnType<typeof setTimeout> | undefined;

function previewOrigin(url: string): string {
  try {
    return new URL(url).origin;
  } catch {
    return "";
  }
}

function csrfToken(boot: BuilderBoot): string {
  return (
    boot.csrf ||
    (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ ||
    ""
  );
}

function defaultPayload(type: string): BlockPayload {
  const design: BlockPayload = {
    spacing: "normal",
    align: "start",
    z_index: 1,
    img_w: "100",
    img_h: "auto",
    img_fit: "cover",
    width: "100",
    height: "auto",
    visible: 1,
    opacity: 100,
    bg_color: "none",
    text_color: "inherit",
    border_w: 0,
    border_style: "solid",
    border_color: "ink",
    shadow: "none",
    radius: 0,
    padding: 0,
    font_family: "inherit",
    font_size: "inherit",
    font_weight: "inherit",
  };
  switch (type) {
    case "heading":
      return { ...design, text: "Nadpis", level: 2 };
    case "text":
      return { ...design, body: "<p>Nový text…</p>" };
    case "html":
      return { ...design, html: "<p>HTML blok</p>" };
    case "image":
      return {
        ...design,
        media_id: "",
        url: "",
        alt: "",
        caption: "",
        width: "100",
        height: "auto",
      };
    case "cta":
      return { ...design, label: "Call to action", url: "#", style: "primary" };
    case "layout":
      return {
        ...design,
        variant: "stack",
        display: "flex",
        dir: "column",
        flex_wrap: "nowrap",
        wrap: 0,
        gap: 16,
        row_gap: "",
        column_gap: "",
        justify: "flex-start",
        align_items: "stretch",
        align_content: "stretch",
        justify_items: "stretch",
        grid_cols: "2",
        grid_rows: "",
        grid_auto_flow: "row",
        grid_auto_rows: "",
        grid_auto_cols: "",
        min_height: 0,
        children: [],
      };
    case "divider":
      return { ...design };
    case "products":
      return { ...design, product_ids: "" };
    case "widget":
      return { ...design, provider: "openstreetmap", url: "", title: "", widget_height: 420 };
    case "avatar":
      return { ...design, name: "", initials: "", url: "", avatar_size: 38, shape: "circle" };
    case "badge":
      return { ...design, label: "Štítek", tone: "soft" };
    case "rating":
      return { ...design, text_color: "brand", value: "{{ item.rating }}" };
    case "dynamic":
      return { ...design, source: "", limit: 12, variant: "grid", item_template: "", item_blocks: [], empty_text: "" };
    case "gallery":
      return { ...design, media_ids: "", variant: "grid", film_speed: 40 };
    case "quote":
      return {
        ...design,
        quote: "Krátká citace klienta…",
        author: "",
        role: "",
      };
    case "video":
      return { ...design, url: "", caption: "" };
    case "spacer":
      return { ...design, size: "md" };
    case "icon":
      return {
        ...design,
        name: "local_shipping",
        icon_style: "outlined",
        size: "48",
        fill: "0",
        weight: "400",
        grade: "0",
        opsz: "48",
        text_color: "brand",
        hover_color: "ink",
        title: "",
      };
    case "form":
      return { ...design, schema_version: 1, action: "", submit_label: "", success_message: "", error_summary: 0, columns: 2, children: [] };
    case "form_field":
      return {
        ...design,
        name: "field",
        control: "text",
        options_layout: "vertical",
        label: "",
        placeholder: "",
        required: 0,
        options: "",
        full: 0,
      };
    case "table":
      return {
        ...design,
        caption: "",
        html:
          "<table><thead><tr><th><p>A</p></th><th><p>B</p></th><th><p>C</p></th></tr></thead>" +
          "<tbody><tr><td><p>1</p></td><td><p>2</p></td><td><p>3</p></td></tr>" +
          "<tr><td><p>4</p></td><td><p>5</p></td><td><p>6</p></td></tr></tbody></table>",
      };
    default:
      return { ...design };
  }
}

function stripHtml(html: string): string {
  // Template contents stay inert: detached divs still execute image error handlers.
  const el = document.createElement("template");
  el.innerHTML = html;
  return (el.content.textContent || "").trim();
}

function normalizePayload(
  type: string,
  raw: Record<string, unknown> | null | undefined,
): BlockPayload {
  const p = { ...(raw || {}) } as Record<string, unknown>;
  const out: BlockPayload = {};
  for (const [k, v] of Object.entries(p)) {
    if (['gereq_attrs', 'gereq_initial', 'gereq_sources', 'text_limits', 'item_design'].includes(k) && v && typeof v === 'object') {
      out[k] = JSON.parse(JSON.stringify(v)) as Record<string, unknown> | Record<string, unknown>[];
      continue;
    }
    if (k === 'children' || k === 'item_blocks') {
      out[k] = Array.isArray(v) ? v.map((child, i) => normalizeBlock(child as BuilderBlock, i)) : [];
      continue;
    }
    if (["images", "rendered_items", "resolved_items"].includes(k)) continue;
    if (Array.isArray(v)) {
      out[k] = v.map(String).join(",");
    } else if (v === null || v === undefined) {
      out[k] = "";
    } else if (typeof v === "number") {
      out[k] = v;
    } else if (
      typeof v === "string" &&
      [
        "z_index",
        "level",
        "visible",
        "opacity",
        "border_w",
        "radius",
        "padding",
      ].includes(k) &&
      v.trim() !== "" &&
      !Number.isNaN(Number(v))
    ) {
      out[k] = Number(v);
    } else {
      out[k] = String(v);
    }
  }
  if (type === "form_field" && out.control === "consent") out.control = "checkbox";
  if (type === "gallery") {
    if (out.media_ids == null) out.media_ids = "";
    if (out.variant == null || out.variant === "") out.variant = "grid";
  }
  if (type === "layout") {
    if (out.variant == null || out.variant === "") out.variant = "stack";
    if (!Array.isArray(out.children)) out.children = [];
  }
  return out;
}

function tmpBlockId(suffix = ""): string {
  return "tmp_" + Math.random().toString(36).slice(2, 10) + suffix;
}

/** Hydrate a template node (incl. nested layout children) into a BuilderBlock. */
function hydrateTemplateNode(node: TemplateNode, index = 0): BuilderBlock {
  const base = defaultPayload(node.type);
  const raw = { ...(node.payload || {}) } as Record<string, unknown>;
  const nested = node.children;
  const payload = { ...base, ...raw } as BlockPayload;
  if (Array.isArray(nested) && nested.length > 0) {
    payload.children = nested.map((child, i) => hydrateTemplateNode(child, i));
  } else if (node.type === "layout" && !Array.isArray(payload.children)) {
    payload.children = [];
  }
  return {
    id: tmpBlockId(String(index)),
    type: node.type,
    sort_order: index + 1,
    payload,
    _new: true,
  };
}

function normalizeBlock(b: BuilderBlock, i: number): BuilderBlock {
  return {
    id: b.id,
    type: b.type,
    sort_order: b.sort_order ?? i + 1,
    payload: normalizePayload(b.type, b.payload as Record<string, unknown>),
  };
}

function toast(
  message: string,
  tone: "success" | "error" | "info" = "info",
): void {
  window.gereq?.toast?.(message, tone);
}

async function askConfirm(opts: {
  title?: string;
  body?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
}): Promise<boolean> {
  if (window.gereq?.confirm) {
    return window.gereq.confirm(opts);
  }
  return window.confirm(opts.body || opts.title || "");
}

async function askPrompt(opts: {
  title?: string;
  body?: string;
  inputLabel?: string;
  inputValue?: string;
  inputPlaceholder?: string;
  confirmLabel?: string;
}): Promise<string | null> {
  if (window.gereq?.prompt) {
    return window.gereq.prompt({
      ...opts,
      input: true,
      inputRequired: true,
      danger: false,
    });
  }
  return window.prompt(
    opts.body || opts.inputLabel || opts.title || "",
    opts.inputValue || "",
  );
}

export function registerCmsBuilder(alpine: typeof Alpine): void {
  alpine.data("cmsBuilder", () => ({
    templateOwner: null as BuilderBlock | null,
    savedTemplateSession: null as {
      partial: BuilderPartial;
      blocks: BuilderBlock[];
      selectedId: string | null;
      dirty: boolean;
      staged: boolean;
      undoStack: string[];
      redoStack: string[];
      historyState: string;
      partialName: string;
    } | null,
    textLimitTarget: "",
    formTestValues: Object.create(null) as Record<string, string>,
    formTestFiles: Object.create(null) as Record<string, File>,
    formTestResult: "",
    formTestErrors: Object.create(null) as Record<string, boolean>,
    formTestBusy: false,
    dynamicLibrarySourceId: "",
    entityBindingTarget: "",
    entityBindingSourceFields: {} as Record<string, string>,
    templateSelectionId: null as string | null,
    staged: false,
    boot: {
      pageId: "",
      title: "",
      slug: "",
      template: "default",
      sandbox: false,
      saveUrl: "",
      previewUrl: "",
      storefrontUrl: "",
      exitUrl: "",
      csrf: "",
      blockTypes: [],
      blocks: [],
      icons: [],
      media: [],
      mediaUploadUrl: "",
      documents: [],
      variables: [],
      varValues: {},
      partials: [],
      partialsUrl: "",
      partialsListUrl: "",
      partialDeleteUrl: "",
      labels: {},
    } as BuilderBoot,
    device: "desktop" as "desktop" | "tablet" | "mobile",
    previewTheme: "light" as "light" | "dark",
    themeSaving: false,
    previewColors: {} as Record<string, string>,
    deviceMenu: false,
    modalTab: null as null | "vars" | "media" | "docs" | "dynamic",
    mediaMode: "apply" as "apply" | "gallery",
    inspectorOpen: true,
    createOpen: false,
    /** When set, FAB / add panel inserts after this block id. */
    insertAfterId: null as string | null,
    insertPlacement: "auto" as "auto" | "before" | "after" | "inside",
    historyGroup: null as { key: string; at: number } | null,
    historyTransactionDepth: 0,
    historyTransactionRecorded: false,
    createTab: "elements" as "elements" | "templates",
    rightTab: "content" as "content" | "design",
    designSection: "styles" as
      "size" | "styles" | "typography" | "image" | null,
    selectedId: null as string | null,
    collapsedLayerIds: [] as string[],
    /** Layers panel drag state (Photoshop-style tree). */
    layerDragId: null as string | null,
    dropTargetId: null as string | null,
    layerDropPlacement: null as "before" | "after" | "inside" | null,
    /** Inline rename in layers dock. */
    layerRenameId: null as string | null,
    inlineEdit: null as { id: string; key: string } | null,
    blocks: [] as BuilderBlock[],
    partials: [] as BuilderPartial[],
    partialName: "",
    partialSaving: false,
    undoStack: [] as string[],
    redoStack: [] as string[],
    historyState: "",
    dirty: false,
    previewPatchOpen: false,
    previewInspectorPending: false,
    previewRestorePending: false,
    htmlEditorModes: {} as Record<string, "preview" | "source">,
    saving: false,
    status: "",
    mediaQuery: "",
    mediaUploading: false,
    mediaUploadError: "",
    mediaDragOver: false,
    iconQuery: "",
    docsQuery: "",
    varFilter: "",
    varKind: "all" as "all" | "text" | "color" | "image" | "link" | "number",
    insertTarget: null as { key: string } | null,
    /** Soft-clear on delete only for branded Vue layouts; freeform pages hard-delete. */
    fixedLayout: false,
    /** True until storefront iframe posts ready (skeleton + loading dialog). */
    previewLoading: true,
    /** Hover preview for templates (popover next to anchor). */
    tplHover: null as TplHoverState | null,
    _tplHoverTimer: 0 as ReturnType<typeof setTimeout> | number,
    _onCreateWheel: null as ((e: WheelEvent) => void) | null,

    init() {
      const root = this.$el as HTMLElement;
      try {
        this.boot = JSON.parse(root.dataset.boot || "{}") as BuilderBoot;
        this.staged = !!this.boot.staged;
        this.previewTheme = this.boot.previewPalette?.storefront_mode === "dark" ? "dark" : "light";
      } catch {
        /* keep defaults */
      }
      this.blocks = (this.boot.blocks || []).map(normalizeBlock);
      this.partials = Array.isArray(this.boot.partials)
        ? this.boot.partials
        : [];
      if (this.boot.sandbox) {
        this.createOpen = true;
        this.createTab = "templates";
      }
      if (this.blocks.length > 0) {
        this.selectedId = this.blocks[0].id;
      }

      this.restoreLibraryPreference();
      for (const field of ["createOpen", "createTab", "modalTab"]) {
        this.$watch(field, () => this.saveLibraryPreference());
      }

      const focusForm = (id: string | null) => {
        if (!id || this.findBlock(id)?.type !== 'form') return;
        this.select(id);
        this.$nextTick(() => {
          const details = document.querySelector<HTMLDetailsElement>('.cms-builder__form-required');
          if (details) { details.open = true; details.scrollIntoView({ block: 'nearest' }); }
        });
      };
      window.addEventListener('cms:focus-form', (event) => focusForm((event as CustomEvent<string>).detail));
      focusForm(new URLSearchParams(window.location.search).get('focus_form'));
      this.ensureInspectorTab();
      this.$watch("selectedId", () => {
        this.ensureInspectorTab();
        this.formTestErrors = Object.create(null); this.formTestResult = "";
        if (this.modalTab === "dynamic" && this.formInsertionContext) this.openLibraryTab("elements");
      });
      this.historyState = this.captureHistory();
      window.addEventListener("keydown", this.onBuilderKeydown.bind(this));

      // Keep wheel scrolling inside the create drawer (not the page behind).
      this._onCreateWheel = (e: WheelEvent) => {
        const el = (e.target as HTMLElement | null)?.closest(
          ".cms-builder__create-body",
        );
        if (!el) return;
        el.scrollTop += e.deltaY;
        e.preventDefault();
      };
      document.addEventListener("wheel", this._onCreateWheel, { passive: false });

      if (this.boot.storefrontUrl) {
        this.previewLoading = true;
        window.addEventListener("message", (e) => this.onPreviewMessage(e));
        // Alpine supports getter watchers; @types/alpinejs only types property keys.
        (
          this as {
            $watch: (expr: string | (() => unknown), cb: () => void) => void;
          }
        ).$watch(
          () => JSON.stringify(this.blocks),
          () => this.queuePreviewState(),
        );
        this.$watch("selectedId", (id: string | null) =>
          this.postPreview({ t: "select", id: this.selectedId }),
        );
        // Safety: never leave the boot overlay stuck forever.
        setTimeout(() => this.markPreviewReady(), 20000);
      } else {
        // Canvas-only builder — brief boot chrome, then show.
        this.previewLoading = true;
        queueMicrotask(() => this.markPreviewReady());
      }

      return () => {
        if (this._onCreateWheel) {
          document.removeEventListener("wheel", this._onCreateWheel);
          this._onCreateWheel = null;
        }
      };
    },

    previewBooted() {
      requestAnimationFrame(() => {
        requestAnimationFrame(() => {
          this.markPreviewReady();
          this.scheduleFixedLayoutNotice();
        });
      });
    },

    markPreviewReady() {
      if (!this.previewLoading) return;
      this.previewLoading = false;
    },

    findBlock(id: string, list?: BuilderBlock[]): BuilderBlock | null {
      const scope = list ?? this.blocks;
      for (const b of scope) {
        if (b.id === id) return b;
        const kids = b.payload.children;
        if (Array.isArray(kids)) {
          const hit = this.findBlock(id, kids as BuilderBlock[]);
          if (hit) return hit;
        }
      }
      return null;
    },

    listOf(id: string | null): BuilderBlock[] {
      if (!id) return this.blocks;
      const queue: BuilderBlock[][] = [this.blocks];
      while (queue.length) {
        const list = queue.shift()!;
        if (list.some((b) => b.id === id)) return list;
        for (const b of list) {
          if (Array.isArray(b.payload.children)) queue.push(b.payload.children as BuilderBlock[]);
        }
      }
      return this.blocks;
    },

    /** True when `maybeDescendantId` is nested under `ancestorId`. */
    isDescendantOf(ancestorId: string, maybeDescendantId: string): boolean {
      const root = this.findBlock(ancestorId);
      if (!root) return false;
      const walk = (list: BuilderBlock[]): boolean => {
        for (const b of list) {
          if (b.id === maybeDescendantId) return true;
          const kids = b.payload.children;
          if (Array.isArray(kids) && walk(kids as BuilderBlock[])) return true;
        }
        return false;
      };
      const kids = root.payload.children;
      return Array.isArray(kids) ? walk(kids as BuilderBlock[]) : false;
    },

    extractBlock(id: string): { block: BuilderBlock; list: BuilderBlock[]; index: number } | null {
      const list = this.listOf(id);
      const index = list.findIndex((b) => b.id === id);
      if (index < 0) return null;
      const [block] = list.splice(index, 1);
      return { block, list, index };
    },

    ensureLayoutChildren(block: BuilderBlock): BuilderBlock[] {
      const kids = Array.isArray(block.payload.children)
        ? [...(block.payload.children as BuilderBlock[])]
        : [];
      // Always reassign payload so Alpine deep-watch + JSON.stringify pick up nested inserts.
      block.payload = { ...block.payload, children: kids };
      return kids;
    },

    /** Force Alpine / preview watchers to see nested tree edits. */
    commitBlocks() {
      this.blocks = this.blocks.slice();
      this.reindex();
      this.markDirty();
    },

    /**
     * Move a block before/after another, or inside a layout container.
     * Used by storefront drag-and-drop (`t: 'move'`).
     */
    moveBlock(
      id: string,
      targetId: string,
      placement: "before" | "after" | "inside",
    ) {
      if (!id || !targetId || id === targetId) return;
      if (this.isDescendantOf(id, targetId)) return;

      const moving = this.findBlock(id);
      if (moving && this.formOwnerOf(targetId, placement === "inside") && !this.supportedFormTree(moving)) return;
      const destination = placement === "inside" ? this.dynamicOwnerOf(targetId)
        : this.dynamicOwnerOf(this.findParent(targetId)?.id || null);
      if (destination && moving && !this.supportedDynamicTree(moving)) return;
      const extracted = this.extractBlock(id);
      if (!extracted) return;
      if (placement === "inside" && this.findBlock(targetId)?.type === "dynamic"
        && this.templateOwner?.id !== targetId) {
        this.selectedId = targetId;
        this.editDynamicTemplate();
      }
      const restore = () => {
        extracted.list.splice(extracted.index, 0, extracted.block);
      };

      if (placement === "inside") {
        const container = this.findBlock(targetId);
        if (!container || !["layout", "dynamic", "form"].includes(container.type)) {
          restore();
          return;
        }
        const kids = this.ensureLayoutChildren(container);
        kids.push(extracted.block);
        container.payload = { ...container.payload, children: kids };
      } else {
        const list = this.listOf(targetId);
        let idx = list.findIndex((b) => b.id === targetId);
        if (idx < 0) {
          restore();
          return;
        }
        if (placement === "after") idx += 1;
        list.splice(idx, 0, extracted.block);
      }

      this.selectedId = id;
      this.commitBlocks();
    },

    get selected(): BuilderBlock | null {
      return this.selectedId ? this.findBlock(this.selectedId) : null;
    },

    /** Parent layout of the selected block (for item flex/grid props). */
    get selectedParent(): BuilderBlock | null {
      if (!this.selectedId) return null;
      return this.findParent(this.selectedId);
    },

    findParent(id: string, list?: BuilderBlock[]): BuilderBlock | null {
      const scope = list ?? this.blocks;
      for (const b of scope) {
        const kids = b.payload.children;
        if (!Array.isArray(kids)) continue;
        if ((kids as BuilderBlock[]).some((c) => c.id === id)) return b;
        const nested = this.findParent(id, kids as BuilderBlock[]);
        if (nested) return nested;
      }
      return null;
    },

    get filteredMedia(): MediaItem[] {
      const q = this.mediaQuery.trim().toLowerCase();
      if (!q) return this.boot.media || [];
      return (this.boot.media || []).filter(
        (m) =>
          m.title.toLowerCase().includes(q) || m.mime.toLowerCase().includes(q),
      );
    },

    get filteredDocs(): DocItem[] {
      const q = this.docsQuery.trim().toLowerCase();
      if (!q) return this.boot.documents || [];
      return (this.boot.documents || []).filter(
        (d) =>
          d.title.toLowerCase().includes(q) || d.mime.toLowerCase().includes(q),
      );
    },

    get filteredVariables(): BuilderVar[] {
      const q = this.varFilter.trim().toLowerCase();
      return (this.boot.variables || []).filter((v) => {
        if (this.varKind !== "all" && v.kind !== this.varKind) return false;
        if (!q) return true;
        return (
          v.path.toLowerCase().includes(q) ||
          v.label.toLowerCase().includes(q) ||
          v.group.toLowerCase().includes(q)
        );
      });
    },

    variableGroups(): Array<{ group: string; items: BuilderVar[] }> {
      const map = new Map<string, BuilderVar[]>();
      for (const item of this.filteredVariables) {
        const list = map.get(item.group) || [];
        list.push(item);
        map.set(item.group, list);
      }
      return Array.from(map.entries()).map(([group, items]) => ({
        group,
        items,
      }));
    },

    dynamicOwnerOf(id: string | null): BuilderBlock | null {
      if (!id) return null;
      const block = this.findBlock(id);
      if (block?.type === "dynamic") return block;
      const parent = this.findParent(id);
      return parent ? this.dynamicOwnerOf(parent.id) : null;
    },

    get selectedDynamicOwner(): BuilderBlock | null {
      return this.dynamicOwnerOf(this.selectedId);
    },

    get dynamicItemDesign(): Record<string, string | number> {
      const design = this.selectedDynamicOwner?.payload.item_design;
      return design && typeof design === "object" && !Array.isArray(design) ? design as Record<string, string | number> : {};
    },

    selectDynamicItemStyle() {
      const owner = this.selectedDynamicOwner;
      if (owner) this.select(owner.id);
    },

    dynamicItemColor(key: string): string {
      const value = String(this.dynamicItemDesign[key] || "");
      const fallback = key === "bg_color" ? "surface" : "ink";
      return this.colorSwatch(value === "none" || value === "inherit" ? "" : value, fallback);
    },

    dynamicItemColorChoice(key: string): string {
      const value = String(this.dynamicItemDesign[key] || (key === "bg_color" ? "none" : key === "text_color" ? "inherit" : "ink"));
      return value.startsWith("#") ? "custom" : value;
    },

    get supportsTextOverflow(): boolean {
      return !!this.selected && ["heading", "text", "html", "badge", "cta", "quote", "image", "video", "table", "columns"].includes(this.selected.type);
    },

    setBlockTextOverflow(event: Event) {
      const value = (event.target as HTMLSelectElement).value;
      if (!this.supportsTextOverflow || !["wrap", "clip", "ellipsis"].includes(value)) return;
      this.setPayload("text_overflow", value);
      this.queuePreviewState();
    },

    setDynamicItemStyle(key: string, event: Event) {
      const owner = this.selectedDynamicOwner;
      if (!owner || !["padding", "radius", "bg_color", "text_color", "border_w", "border_style", "border_color", "shadow", "max_height"].includes(key)) return;
      const raw = (event.target as HTMLInputElement).value;
      const max: Record<string, number> = { padding: 64, radius: 50, border_w: 8, max_height: 4096 };
      const value = key in max ? Math.max(0, Math.min(max[key], Math.round(Number(raw) || 0)))
        : raw === "custom" ? this.dynamicItemColor(key) : raw;
      owner.payload.item_design = { ...this.dynamicItemDesign, [key]: value };
      this.markDirty();
      this.queuePreviewState();
    },

    entitySourceFieldLabel(field: string): string {
      return this.boot.labels[`entitySourceField.${field}`] || field;
    },

    get entityBindingFields(): string[] {
      const source = this.boot.dynamicSources?.find((entry) => entry.id === this.selectedDynamicOwner?.payload.source);
      return source?.fields || [];
    },

    get entityBindingTargets(): string[] {
      return this.selected ? ENTITY_FIELDS[this.selected.type] || [] : [];
    },

    entityBindingValue(target: string): string {
      if (!this.selectedDynamicOwner || !this.selected || !target) return "";
      const value = String(this.selected.payload[target] ?? "");
      const fields = new Set([...value.matchAll(/\{\{\s*item\.([a-zA-Z0-9_.]+)\s*\}\}/g)].map((match) => match[1]));
      const active = this.entityBindingSourceFields[target];
      if (active && fields.has(active) && this.entityBindingFields.includes(active)) return active;
      if (fields.size !== 1) return "";
      const field = [...fields][0];
      return this.entityBindingFields.includes(field) ? field : "";
    },

    get selectedEntityBindingField(): string {
      return this.entityBindingValue(this.entityBindingTargets.includes(this.entityBindingTarget) ? this.entityBindingTarget : this.entityBindingTargets[0]);
    },

    bindEntityTarget(target: string, event: Event) {
      if (!this.entityBindingTargets.includes(target)) return;
      this.entityBindingTarget = target;
      this.bindEntityField(event);
    },

    disconnectEntityTarget(target: string) {
      if (!this.selected || !this.entityBindingTargets.includes(target)) return;
      const field = this.entityBindingValue(target);
      if (!field) return;
      const value = String(this.selected.payload[target] ?? "").replace(
        /\{\{\s*item\.([a-zA-Z0-9_.]+)\s*\}\}/g,
        (token, path: string) => path === field ? "" : token,
      );
      delete this.entityBindingSourceFields[target];
      if (this.selected.type === "image" && target === "url") {
        this.applyInspectorMediaSelection(this.selected.id, null, value);
      } else this.setPayload(target, value);
    },

    bindEntityField(event: Event) {
      const field = (event.target as HTMLSelectElement).value;
      const target = this.entityBindingTargets.includes(this.entityBindingTarget) ? this.entityBindingTarget : this.entityBindingTargets[0];
      if (!this.selected || !target || !this.entityBindingFields.includes(field)
        || !this.entityBindingTargets.includes(target)) return;
      if (this.historyTransactionDepth === 0) this.historyTransactionRecorded = false;
      this.historyTransactionDepth++;
      try {
        if (this.selected.type === "image" && target === "url") {
          this.setPayload("media_id", "");
          this.setPayload("media_url", "");
        }
        const value = String(this.selected.payload[target] ?? "");
        const token = `{{ item.${field} }}`;
        const previousField = this.entityBindingValue(target);
        const itemToken = /\{\{\s*item\.[a-zA-Z0-9_.]+\s*\}\}/g;
        this.setPayload(target, previousField && itemToken.test(value)
          ? value.replace(itemToken, (match) => match.replace(/\s/g, "") === `{{item.${previousField}}}` ? token : match)
          : /\{\{\s*[a-zA-Z0-9_.]+\s*\}\}/.test(value) ? `${value} ${token}` : token);
        this.entityBindingSourceFields[target] = field;
      } finally { this.historyTransactionDepth--; }
      this.queuePreviewState();
    },

    supportedDynamicTree(block: BuilderBlock): boolean {
      return DYNAMIC_CHILD_TYPES.has(block.type) && (!Array.isArray(block.payload.children)
        || (block.payload.children as BuilderBlock[]).every((child) => this.supportedDynamicTree(child)));
    },

    formOwnerOf(id: string | null, includeSelf = true): BuilderBlock | null {
      if (!id) return null;
      const walk = (blocks: BuilderBlock[]): BuilderBlock | null => {
        for (const block of blocks) {
          if (block.type === "form" && ((includeSelf && block.id === id) || this.isDescendantOf(block.id, id))) return block;
          if (Array.isArray(block.payload.children)) {
            const owner = walk(block.payload.children as BuilderBlock[]);
            if (owner) return owner;
          }
        }
        return null;
      };
      return walk(this.blocks);
    },

    supportedFormTree(block: BuilderBlock): boolean {
      return FORM_CHILD_TYPES.has(block.type) && (!Array.isArray(block.payload.children)
        || (block.payload.children as BuilderBlock[]).every((child) => this.supportedFormTree(child)));
    },

    get formInsertionContext(): boolean {
      return !!this.formOwnerOf(this.insertAfterId || this.selectedId);
    },

    get canInsertDynamic(): boolean {
      return !this.selectedDynamicOwner && !this.formInsertionContext;
    },

    get availableBlockTypes() {
      const owner = this.dynamicOwnerOf(this.insertAfterId || this.selectedId);
      const form = this.formOwnerOf(this.insertAfterId || this.selectedId);
      return (this.boot.blockTypes || []).filter((entry) => (!owner || DYNAMIC_CHILD_TYPES.has(entry.value)) && (!form || FORM_CHILD_TYPES.has(entry.value)));
    },

    editSelectedDynamicContainer() {
      const owner = this.selectedDynamicOwner;
      if (!owner) return;
      this.selectedId = owner.id;
      if (this.templateOwner?.id !== owner.id) this.editDynamicTemplate();
    },

    addDynamicChild() {
      this.editSelectedDynamicContainer();
      if (!this.templateOwner) return;
      this.selectedId = this.templateOwner.id;
      this.insertAfterId = this.templateOwner.id;
      this.createOpen = true;
      this.createTab = "elements";
    },

    dynamicPartials(): BuilderPartial[] {
      return this.partials.filter((partial) => partial.blocks.length === 1 && partial.blocks[0].type === "dynamic");
    },

    regularPartials(): BuilderPartial[] {
      const dynamicIds = new Set(this.dynamicPartials().map((partial) => partial.id));
      return this.partials.filter((partial) => !dynamicIds.has(partial.id));
    },

    newDynamicTemplate(kind: "card" | "stars" | "custom", sourceId: string) {
      if (this.formInsertionContext) return;
      if (this.templateOwner) this.finishDynamicTemplate();
      const source = this.boot.dynamicSources?.find((entry) => entry.id === sourceId);
      if (!source || (kind === "stars" && !source.fields.includes("rating"))) return;
      if (this.selectedDynamicOwner) this.selectedId = this.selectedDynamicOwner.id;
      this.insertBlockNear("dynamic", this.selectedId, { extra: !!this.boot.storefrontUrl });
      const rating = { id: "tmp_rating_" + Date.now(), type: "rating", sort_order: 0, payload: { ...defaultPayload("rating"), value: "{{ item.rating }}" } };
      const content: BuilderBlock[] = [
        rating,
        { id: "tmp_body_" + Date.now(), type: "text", sort_order: 1, payload: { ...defaultPayload("text"), body: "<p>{{ item.body }}</p>" } },
        { id: "tmp_author_" + Date.now(), type: "heading", sort_order: 2, payload: { ...defaultPayload("heading"), text: "{{ item.author_name }}", level: 3 } },
      ];
      const blocks: BuilderBlock[] = kind === "custom" ? [] : kind === "stars" ? [rating] : [{ id: "tmp_card_" + Date.now(), type: "layout", sort_order: 0, payload: { ...defaultPayload("layout"), padding: 24, radius: 0, bg_color: "none", children: content } }];
      this.setPayload("source", source.id);
      this.setPayload("item_blocks", blocks);
      this.setPayload("item_template", "");
      this.editDynamicTemplate();
    },

    editDynamicTemplate() {
      const owner = this.selected;
      if (!owner || owner.type !== "dynamic") return;
      if (this.templateOwner?.id === owner.id) {
        this.finishDynamicTemplate();
        return;
      }
      if (this.templateOwner) this.finishDynamicTemplate();
      this.templateOwner = owner;
      const saved = Array.isArray(owner.payload.item_blocks) ? owner.payload.item_blocks as BuilderBlock[] : [];
      const fallback: BuilderBlock[] = owner.payload.item_template ? [{ id: "tmp_legacy_" + Date.now(), type: "html", sort_order: 0, payload: { ...defaultPayload("html"), html: String(owner.payload.item_template) } }] : [];
      owner.payload = { ...owner.payload, children: JSON.parse(JSON.stringify(saved.length ? saved : fallback)).map(normalizeBlock) };
      this.selectedId = this.templateSelectionId && this.isDescendantOf(owner.id, this.templateSelectionId)
        ? this.templateSelectionId : owner.id;
      this.insertTarget = null;
      this.inlineEdit = null;
      this.closeLibrary();
      this.historyState = this.captureHistory();
      this.queuePreviewState(true);
    },

    finishDynamicTemplate() {
      if (!this.templateOwner) return;
      const owner = this.findBlock(this.templateOwner.id);
      if (owner) {
        this.templateSelectionId = this.selectedId && this.isDescendantOf(owner.id, this.selectedId) ? this.selectedId : null;
        owner.payload.item_blocks = JSON.parse(JSON.stringify(owner.payload.children || []));
        owner.payload.item_template = "";
        delete owner.payload.children;
        this.selectedId = owner.id;
      }
      this.templateOwner = null;
      this.insertTarget = null;
      this.inlineEdit = null;
      if (!this.historyState || JSON.stringify(JSON.parse(this.captureHistory()).blocks) !== JSON.stringify(JSON.parse(this.historyState).blocks)) this.markDirty();
      this.queuePreviewState(true);
    },

    get textLimitTargets(): Array<{ value: string; label: string }> {
      const fields: Record<string, string[]> = {
        text: ["body"], quote: ["all"], cta: ["label"],
        form_field: this.formFieldHasPlaceholder ? ["label", "placeholder"] : ["label"], badge: ["label"], image: ["caption"],
        video: ["caption"], table: ["caption"], columns: ["left", "right"],
      };
      return (fields[this.selected?.type || ""] || []).map(value => ({ value,
        label: this.boot.labels[value === "label" && this.selected?.type === "form_field" ? "textLimit.fieldLabel" : "textLimit." + value] || value,
      }));
    },

    get activeTextLimitField(): string {
      return this.textLimitTargets.some(target => target.value === this.textLimitTarget)
        ? this.textLimitTarget : this.textLimitTargets[0]?.value || "";
    },

    changeTextLimitTarget(event: Event) {
      const value = (event.target as HTMLSelectElement).value;
      if (this.textLimitTargets.some(target => target.value === value)) this.textLimitTarget = value;
    },

    textLimitValue(field: string, key: "max" | "suffix"): string | number {
      const rules = this.selected?.payload.text_limits as Record<string, { max?: number; suffix?: string }> | undefined;
      return (rules?.[field] || (this.selected?.type === "quote" && field === "all" ? rules?.quote : undefined))?.[key] ?? (key === "max" ? 0 : "…");
    },

    onTextLimitInput(field: string, key: "max" | "suffix", event: Event) {
      if (!this.selected || this.selected.type === "heading") return;
      const rules = { ...(this.selected.payload.text_limits as Record<string, unknown> || {}) };
      const rule = { max: this.textLimitValue(field, "max"), suffix: this.textLimitValue(field, "suffix") };
      const value = (event.target as HTMLInputElement).value;
      rule[key] = key === "max" ? Math.max(0, Math.min(100000, Math.floor(Number(value) || 0))) : value;
      rules[field] = rule;
      this.setPayload("text_limits", this.selected.type === "quote" ? { all: rule } : rules);
    },

    sourceLabel(sourceId: string): string {
      return this.boot.dynamicSources?.find((source) => source.id === sourceId)?.label || sourceId;
    },

    get dynamicLibraryOwner(): BuilderBlock | null {
      return this.selectedDynamicOwner;
    },

    get dynamicLibrarySources() {
      const sourceId = this.dynamicLibraryOwner
        ? String(this.dynamicLibraryOwner.payload.source || "")
        : this.dynamicLibrarySourceId;
      return (this.boot.dynamicSources || []).filter((source) => source.id === sourceId);
    },

    sourceTemplates(sourceId: string): BuilderPartial[] {
      return this.dynamicPartials().filter((partial) => partial.blocks[0].payload.source === sourceId);
    },

    async saveDynamicTemplate() {
      const owner = this.templateOwner || (this.selected?.type === "dynamic" ? this.selected : null);
      if (!owner) return;
      const snapshot = this.persistedBlocks([owner])[0];
      owner.payload.item_blocks = snapshot.payload.item_blocks;
      await this.savePartial([snapshot]);
    },

    ratingStars(block: BuilderBlock): string {
      const value = Math.max(0, Math.min(5, Math.round(Number(this.expandPreview(String(block.payload.value || 0))) || 0)));
      return "★".repeat(value) + "☆".repeat(5 - value);
    },

    connectionGroups() {
      const query = this.varFilter.trim().toLocaleLowerCase();
      return (this.boot.dynamicSources || []).filter((source) => !this.selectedDynamicOwner || source.id === this.selectedDynamicOwner.payload.source).map((source) => ({
        ...source,
        fields: source.fields.filter((field) => !query || source.label.toLocaleLowerCase().includes(query) || field.toLocaleLowerCase().includes(query) || (this.boot.labels.connections || "").toLocaleLowerCase().includes(query)),
      })).filter((source) => source.fields.length > 0);
    },

    addConnection(sourceId: string) {
      this.newDynamicTemplate(sourceId === "reviews" ? "card" : "custom", sourceId);
    },

    editSavedDynamicTemplate(partial: BuilderPartial) {
      if (this.templateOwner) this.finishDynamicTemplate();
      if (this.savedTemplateSession || partial.blocks.length !== 1 || partial.blocks[0].type !== "dynamic") return;
      this.savedTemplateSession = {
        partial, blocks: this.blocks, selectedId: this.selectedId,
        dirty: this.dirty, staged: this.staged, undoStack: this.undoStack,
        redoStack: this.redoStack, historyState: this.historyState, partialName: this.partialName,
      };
      this.blocks = [{ id: tmpBlockId(), type: "dynamic", sort_order: 0,
        payload: normalizePayload("dynamic", partial.blocks[0].payload), _new: true }];
      this.selectedId = this.blocks[0].id;
      this.partialName = partial.name;
      this.undoStack = [];
      this.redoStack = [];
      this.dirty = false;
      this.staged = false;
      this.editDynamicTemplate();
    },

    closeSavedDynamicTemplate() {
      const session = this.savedTemplateSession;
      if (!session || this.partialSaving) return;
      this.templateOwner = null;
      this.blocks = session.blocks;
      this.selectedId = session.selectedId;
      this.dirty = session.dirty;
      this.staged = session.staged;
      this.undoStack = session.undoStack;
      this.redoStack = session.redoStack;
      this.historyState = session.historyState;
      this.partialName = session.partialName;
      this.savedTemplateSession = null;
      this.inlineEdit = null;
      this.insertTarget = null;
      this.queuePreviewState();
    },

    connectionToken(field: string): string {
      return `{{ item.${field} }}`;
    },

    insertConnectionVariable(sourceId: string, field: string) {
      if (this.templateOwner?.payload.source === sourceId) {
        if (this.selected?.id === this.templateOwner.id) {
          this.addBlock("text", null, { list: this.ensureLayoutChildren(this.selected), layoutHost: this.selected });
        }
        this.insertVariable(`item.${field}`);
        return;
      }
      if (this.selected?.type !== "dynamic" || this.selected.payload.source !== sourceId) {
        toast(this.boot.labels.selectConnection || "", "info");
        return;
      }
      this.editDynamicTemplate();
      this.insertVariable(`item.${field}`);
    },

    get sectionPresets(): Array<{ kind: string; label: string; desc: string }> {
      const L = this.boot.labels || {};
      return [
        {
          kind: "hero",
          label: L.sectionHero || "Hero",
          desc: L.sectionHeroDesc || "",
        },
        {
          kind: "trust",
          label: L.sectionTrust || "Trust",
          desc: L.sectionTrustDesc || "",
        },
        {
          kind: "listings",
          label: L.sectionListings || "Listings",
          desc: L.sectionListingsDesc || "",
        },
        {
          kind: "why",
          label: L.sectionWhy || "Why",
          desc: L.sectionWhyDesc || "",
        },
        {
          kind: "services",
          label: L.sectionServices || "Services",
          desc: L.sectionServicesDesc || "",
        },
        {
          kind: "how",
          label: L.sectionHow || "How",
          desc: L.sectionHowDesc || "",
        },
        {
          kind: "types",
          label: L.sectionTypes || "Types",
          desc: L.sectionTypesDesc || "",
        },
        {
          kind: "testimonials",
          label: L.sectionTestimonials || "Testimonials",
          desc: L.sectionTestimonialsDesc || "",
        },
        {
          kind: "insights",
          label: L.sectionInsights || "Insights",
          desc: L.sectionInsightsDesc || "",
        },
        {
          kind: "cta",
          label: L.sectionCta || "CTA",
          desc: L.sectionCtaDesc || "",
        },
        {
          kind: "contact",
          label: L.sectionContact || "Contact",
          desc: L.sectionContactDesc || "",
        },
        {
          kind: "footer",
          label: L.sectionFooter || "Footer",
          desc: L.sectionFooterDesc || "",
        },
        {
          kind: "faq",
          label: L.sectionFaq || "FAQ",
          desc: L.sectionFaqDesc || "",
        },
        {
          kind: "about",
          label: L.sectionAbout || "About",
          desc: L.sectionAboutDesc || "",
        },
        {
          kind: "quote",
          label: L.sectionQuote || "Quote",
          desc: L.sectionQuoteDesc || "",
        },
      ];
    },

    varKindIcon(kind: string): string {
      switch (kind) {
        case "text":
          return "T";
        case "image":
          return "▣";
        case "link":
          return "↗";
        case "number":
          return "#";
        default:
          return "·";
      }
    },

    dynamicItems(block: BuilderBlock): string[] {
      const source = this.boot.dynamicSources?.find((entry) => entry.id === block.payload.source);
      const limit = Math.max(1, Math.min(100, Number(block.payload.limit) || 12));
      return (source?.items || []).slice(0, limit).map((item) => DOMPurify.sanitize(
        bindTemplateVariables(String(block.payload.item_template || ""), item, this.boot.varValues || {}, true), { ALLOWED_TAGS: ["p", "br", "strong", "b", "em", "i", "u", "s", "ul", "ol", "li", "blockquote", "hr", "h1", "h2", "h3", "h4", "a", "span", "div"], ALLOWED_ATTR: ["href", "title", "rel", "target", "style"] },
      ));
    },

    bindTemplateBlocks(blocks: BuilderBlock[], item: Record<string, unknown>): BuilderBlock[] {
      return blocks.map((block) => {
        const payload = { ...block.payload };
        for (const [key, value] of Object.entries(payload)) {
          if (typeof value !== "string") continue;
          payload[key] = bindTemplateVariables(value, item, this.boot.varValues || {}, ["body", "html", "text"].includes(key));
          if (["body", "html", "text"].includes(key)) payload[key] = DOMPurify.sanitize(String(payload[key]));
        }
        if (Array.isArray(payload.children)) payload.children = this.bindTemplateBlocks(payload.children as BuilderBlock[], item);
        return { ...block, payload };
      });
    },

    previewBlocks(blocks: BuilderBlock[]): BuilderBlock[] {
      return blocks.map((block) => {
        const payload = { ...block.payload };
        if (block.type === "dynamic") {
          payload.editing_template = this.templateOwner?.id === block.id ? 1 : 0;
          payload.source_label = this.sourceLabel(String(block.payload.source || ""));
          payload.rendered_items = this.dynamicItems(block) as unknown as BlockPayload[string];
          const source = this.boot.dynamicSources?.find((entry) => entry.id === block.payload.source);
          const currentTemplate = this.templateOwner?.id === block.id ? block.payload.children : block.payload.item_blocks;
          const templates = Array.isArray(currentTemplate) ? currentTemplate as BuilderBlock[] : [];
          payload.resolved_items = templates.length ? (source?.items || []).slice(0, Math.max(1, Math.min(100, Number(block.payload.limit) || 12))).map((item) => this.bindTemplateBlocks(templates, item)) as unknown as BlockPayload[string] : [];
        }
        if (Array.isArray(payload.children)) payload.children = this.previewBlocks(payload.children as BuilderBlock[]);
        return { ...block, payload };
      });
    },

    dynamicSourceChanged(event: Event) {
      if (!this.selected || this.selected.type !== "dynamic") return;
      const id = (event.target as HTMLSelectElement).value;
      if (!this.boot.dynamicSources?.some((entry) => entry.id === id)) return;
      this.setPayload("source", id);
      this.queuePreviewState();
    },

    dynamicFields(): string {
      const source = this.boot.dynamicSources?.find((entry) => entry.id === this.selected?.payload.source);
      return source?.fields.map((field) => `{{ item.${field} }}`).join(" · ") || "";
    },

    hasContentSettings(): boolean {
      return !!this.selected && [
        "heading", "text", "html", "columns", "image", "cta", "form", "form_field", "divider", "layout",
        "dynamic", "rating", "avatar", "badge", "widget", "gallery", "products", "quote", "video", "spacer", "table",
      ].includes(this.selected.type);
    },

    ensureInspectorTab() {
      if (this.selected && !this.hasContentSettings() && this.rightTab === "content") {
        this.openDesignSection("size");
      }
    },

    openDesignSection(section: "size" | "styles" | "typography" | "image") {
      this.rightTab = "design";
      this.designSection = section;
    },

    selectLayer(id: string) {
      this.select(id);
      this.postPreview({ t: "select", id, scroll: true });
    },

    select(id: string | null) {
      if (this.inlineEdit && this.inlineEdit.id !== id) {
        this.inlineEdit = null;
      }
      if (this.selectedId !== id) {
        this.insertTarget = null;
        this.entityBindingTarget = "";
        this.entityBindingSourceFields = {};
      }
      const changedSelection = this.selectedId !== id;
      if (changedSelection && this.formOwnerOf(this.selectedId)?.id !== this.formOwnerOf(id)?.id) {
        this.formTestValues = Object.create(null); this.formTestFiles = Object.create(null); this.formTestErrors = Object.create(null); this.formTestResult = '';
      }
      this.selectedId = id;
      if (changedSelection) { this.historyGroup = null; this.insertPlacement = "auto"; this.insertAfterId = null; }
      if (this.historyState) {
        const state = JSON.parse(this.historyState);
        state.selectedId = id;
        this.historyState = JSON.stringify(state);
      }
      if (id !== null && changedSelection) {
        this.rightTab = "content";
        this.ensureInspectorTab();
      }
    },

    isInline(block: BuilderBlock, key: string): boolean {
      return this.inlineEdit?.id === block.id && this.inlineEdit?.key === key;
    },

    inlineAttr(block: BuilderBlock, key: string): string {
      return block.id + ":" + key;
    },

    isRichInlineKey(key: string): boolean {
      return (
        key === "body" || key === "html" || key === "left" || key === "right"
      );
    },

    inlineRichBlocks(block: BuilderBlock, key: string): BuilderBlock[] {
      return this.isInline(block, key) && this.isRichInlineKey(key)
        ? [block]
        : [];
    },

    payloadStrFor(block: BuilderBlock, key: string): string {
      return String(block.payload[key] ?? "");
    },

    onCanvasEditorPayload(block: BuilderBlock, key: string, event: Event) {
      const t = event.target as HTMLElement | null;
      if (!t || !(t instanceof HTMLTextAreaElement)) return;
      if (
        !t.classList.contains("g-editor__input") &&
        !t.classList.contains("g-editor__html")
      )
        return;
      block.payload[key] = t.value;
      this.markDirty();
    },

    closeInline() {
      this.inlineEdit = null;
    },

    onCanvasTextClick(block: BuilderBlock, key: string, event: Event) {
      event.stopPropagation();
      if (this.selectedId === block.id && !this.isInline(block, key)) {
        this.startInline(block, key);
        return;
      }
      this.select(block.id);
    },

    startInline(block: BuilderBlock, key: string) {
      this.select(block.id);
      this.focusField(key);
      this.inlineEdit = { id: block.id, key };
      if (this.isRichInlineKey(key)) {
        return;
      }
      this.$nextTick(() => {
        const el = document.querySelector(
          '[data-inline="' + block.id + ":" + key + '"]',
        ) as HTMLElement | null;
        if (!el) return;
        el.textContent = String(block.payload[key] ?? "");
        el.focus();
        const range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        const sel = window.getSelection();
        sel?.removeAllRanges();
        sel?.addRange(range);
      });
    },

    onInlinePlainInput(block: BuilderBlock, key: string, event: Event) {
      const el = event.target as HTMLElement;
      block.payload[key] = (el.innerText || "").replace(/\n$/, "");
      this.markDirty();
    },

    onInlineRichInput(block: BuilderBlock, key: string, event: Event) {
      const el = event.target as HTMLElement;
      block.payload[key] = el.innerHTML;
      this.markDirty();
    },

    endInline(block: BuilderBlock, key: string, event: Event) {
      if (!this.isInline(block, key)) return;
      if (this.isRichInlineKey(key)) {
        this.inlineEdit = null;
        return;
      }
      const el = event.target as HTMLElement;
      block.payload[key] = (el.innerText || "").replace(/\n$/, "");
      this.markDirty();
      this.inlineEdit = null;
    },

    canvasPlain(html: unknown): string {
      const text = stripHtml(this.expandPreview(String(html || "")));
      return text || "…";
    },

    persistedBlocks(blocks: BuilderBlock[] | null = null): BuilderBlock[] {
      const canonical = (blocks: BuilderBlock[]): BuilderBlock[] => blocks.map((block) => {
        const payload = { ...block.payload };
        if (block.type === "dynamic" && Array.isArray(payload.children)) {
          payload.item_blocks = canonical(payload.children as BuilderBlock[]);
          payload.item_template = "";
          delete payload.children;
        } else if (Array.isArray(payload.children)) payload.children = canonical(payload.children as BuilderBlock[]);
        return { ...block, payload };
      });
      return canonical(blocks || this.blocks);
    },

    captureHistory(): string {
      return JSON.stringify({ blocks: this.persistedBlocks(), selectedId: this.selectedId });
    },

    restoreHistory(direction: "undo" | "redo") {
      this.historyGroup = null;
      const source = direction === "undo" ? this.undoStack : this.redoStack;
      const target = direction === "undo" ? this.redoStack : this.undoStack;
      const previous = source.pop();
      if (!previous) return;
      target.push(this.captureHistory());
      const state = JSON.parse(previous) as { blocks: BuilderBlock[]; selectedId: string | null };
      const ownerId = this.templateOwner?.id;
      this.blocks = state.blocks;
      this.templateOwner = ownerId ? this.findBlock(ownerId) : null;
      if (this.templateOwner) {
        this.templateOwner.payload.children = JSON.parse(JSON.stringify(this.templateOwner.payload.item_blocks || []));
      }
      this.selectedId = state.selectedId;
      this.inlineEdit = null;
      this.historyState = this.captureHistory();
      this.dirty = true;
      this.status = "";
      this.previewPatchOpen = false;
      this.previewInspectorPending = false;
      this.previewRestorePending = true;
      if (patchGateTimer) { clearTimeout(patchGateTimer); patchGateTimer = undefined; }
      if (previewTimer) { clearTimeout(previewTimer); previewTimer = undefined; }
      if (this.boot.storefrontUrl) {
        this.postPreview({ t: "state", blocks: this.previewBlocks(this.blocks), restore: true });
        this.postPreview({ t: "select", id: this.selectedId });
        this.previewRestorePending = false;
      } else this.queuePreviewState();
    },

    onBuilderKeydown(event: KeyboardEvent) {
      const target = event.target;
      if (target instanceof Element && target.closest("input, textarea, select, [contenteditable]:not([contenteditable='false']), [role='textbox'], [role='dialog']")) return;
      if (event.altKey) return;
      const modifier = event.ctrlKey || event.metaKey;
      if (modifier && event.key.toLowerCase() === "z") {
        event.preventDefault();
        this.restoreHistory(event.shiftKey ? "redo" : "undo");
      } else if (modifier && event.key.toLowerCase() === "y") {
        event.preventDefault();
        this.restoreHistory("redo");
      } else if (!modifier && !event.shiftKey && this.selectedId) {
        if (event.key === "Delete") { event.preventDefault(); this.removeSelected(); }
        else if (event.key === "ArrowUp") { event.preventDefault(); this.moveSelected(-1); }
        else if (event.key === "ArrowDown") { event.preventDefault(); this.moveSelected(1); }
      }
    },

    markDirty(group = "", done = false) {
      const now = Date.now();
      const grouped = !!group && this.historyGroup?.key === group && now - this.historyGroup.at < 1000;
      const inTransaction = this.historyTransactionDepth > 0 && this.historyTransactionRecorded;
      const next = this.captureHistory();
      if (this.historyState && JSON.stringify(JSON.parse(this.historyState).blocks) !== JSON.stringify(JSON.parse(next).blocks)) {
        if (!grouped && !inTransaction) this.undoStack.push(this.historyState);
        if (this.historyTransactionDepth > 0) this.historyTransactionRecorded = true;
        if (this.undoStack.length > 100) this.undoStack.shift();
        this.redoStack = [];
      }
      this.historyGroup = group && !done ? { key: group, at: now } : null;
      this.historyState = next;
      this.dirty = true;
      this.status = "";
    },

    async togglePreviewTheme() {
      if (this.themeSaving || !this.boot.themeSaveUrl) return;
      const mode = this.previewTheme === "light" ? "dark" : "light";
      this.themeSaving = true;
      try {
        const response = await fetch(this.boot.themeSaveUrl, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded", "X-CSRF-TOKEN": csrfToken(this.boot), "X-Requested-With": "XMLHttpRequest" },
          body: new URLSearchParams({ mode }),
        });
        if (!response.ok) throw new Error("Theme save failed");
        this.previewTheme = mode;
        if (this.boot.previewPalette) this.boot.previewPalette.storefront_mode = mode;
        this.postPreview({ t: "preview-theme", mode });
        toast(this.boot.labels.themeSaved, "success");
      } catch {
        toast(this.boot.labels.themeSaveFailed, "error");
      } finally {
        this.themeSaving = false;
      }
    },

    postPreview(msg: unknown) {
      const frame = document.getElementById("cmsPreview");
      if (!(frame instanceof HTMLIFrameElement) || !frame.contentWindow) return;
      const target = previewOrigin(this.boot.storefrontUrl) || "*";
      frame.contentWindow.postMessage(JSON.parse(JSON.stringify(msg)), target);
    },

    queuePreviewState(fromInspector = false) {
      if (fromInspector) this.previewInspectorPending = true;
      if (previewTimer) clearTimeout(previewTimer);
      previewTimer = setTimeout(() => {
        previewTimer = undefined;
        if (this.previewPatchOpen) return;
        this.previewInspectorPending = false;
        this.postPreview({ t: "state", blocks: this.previewBlocks(this.blocks), ...(this.previewRestorePending ? { restore: true } : {}) });
        this.previewRestorePending = false;
        if (this.selectedDynamicOwner) this.postPreview({ t: "select", id: this.selectedId });
      }, 50);
    },

    onPreviewMessage(event: MessageEvent) {
      const msg = event.data as
        | {
            t?: string;
            action: string;
            id?: string;
            key?: string;
            value?: string;
            done?: boolean;
            operation?: string;
            field?: string;
            afterId?: string;
            type?: string;
            extra?: boolean;
            fixedLayout?: boolean;
            handshake?: number;
            mode?: "preview" | "source";
            targetId?: string;
            placement?: "before" | "after" | "inside";
            colors?: Record<string, unknown>;
          }
        | undefined;
      if (!msg || typeof msg !== "object" || !this.boot.storefrontUrl) return;
      if (event.origin !== previewOrigin(this.boot.storefrontUrl)) return;
      const frame = document.getElementById("cmsPreview");
      if (!(frame instanceof HTMLIFrameElement) || event.source !== frame.contentWindow) return;
      if (msg.t === "ready") {
        this.htmlEditorModes = {};
        // New iframe boot — allow the chrome notice again this load.
        (this as { _fixedLayoutNoticeShown?: boolean })._fixedLayoutNoticeShown =
          false;
        this.fixedLayout = !!(msg as { fixedLayout?: boolean }).fixedLayout;
        this.postPreview({
          t: "boot",
          pageId: this.boot.pageId,
          blocks: this.previewBlocks(this.blocks),
          variables: this.boot.variables,
          labels: this.boot.labels,
          palette: this.boot.previewPalette ? { ...this.boot.previewPalette, storefront_mode: this.previewTheme } : undefined
        });
        this.postPreview({ t: "select", id: this.selectedId });
        // Older clients have no acknowledgement; modern clients confirm boot reception.
        if (msg.handshake !== 1) this.previewBooted();
      } else if (msg.t === "palette" && msg.colors && typeof msg.colors === "object") {
        const colors: Record<string, string> = {};
        for (const key of ["brand", "secondary", "accent", "background", "surface", "header", "ink", "muted"]) {
          const value = msg.colors[key];
          if (typeof value === "string" && /^#[0-9a-f]{6}$/i.test(value)) colors[key] = value.toLowerCase();
        }
        this.previewColors = colors;
      } else if (msg.t === "booted") {
        this.previewBooted();
      } else if (msg.t === "layout") {
        this.fixedLayout = !!(msg as { fixedLayout?: boolean }).fixedLayout;
        // Debounced: initial Vue route can briefly be `/` (fixed) before soft
        // pages like /galerie resolve — don't flash the notice on that race.
        this.scheduleFixedLayoutNotice();
      } else if (msg.t === "html-editor-mode" && typeof msg.id === "string"
        && (msg.mode === "preview" || msg.mode === "source")) {
        this.htmlEditorModes[msg.id] = msg.mode;
      } else if (msg.t === "click") {
        this.select(typeof msg.id === "string" ? msg.id : null);
      } else if (msg.t === "patch" && msg.id && msg.key) {
        this.applyPatch({
          id: msg.id,
          key: msg.key,
          value: msg.value,
          done: msg.done,
          operation: msg.operation,
        });
      } else if (msg.t === "bind-field" && msg.id && msg.key) {
        this.select(msg.id);
        if (!this.selectedDynamicOwner || !this.entityBindingTargets.includes(msg.key)) return;
        this.entityBindingTarget = msg.key;
        const field = typeof msg.field === "string" && msg.field.startsWith("item.") ? msg.field.slice(5) : "";
        if (this.entityBindingFields.includes(field)) this.entityBindingSourceFields[msg.key] = field;
        this.rightTab = "content";
        // Keep the clicked variable selected in the iframe for rich-text formatting.
      } else if (msg.t === "action") {
        if (msg.action === "history-begin") {
          if (this.historyTransactionDepth === 0) this.historyTransactionRecorded = false;
          this.historyTransactionDepth++;
          return;
        }
        if (msg.action === "history-end") {
          this.historyTransactionDepth = Math.max(0, this.historyTransactionDepth - 1);
          return;
        }
        if (msg.action === "undo" || msg.action === "redo") {
          this.restoreHistory(msg.action);
          return;
        }
        if (this.templateOwner?.id === msg.id && ["duplicate", "delete"].includes(msg.action)) this.finishDynamicTemplate();
        if (msg.id !== this.selectedId) this.select(msg.id as string);
        if (msg.action === "up") this.moveSelected(-1);
        else if (msg.action === "down") this.moveSelected(1);
        else if (msg.action === "duplicate") this.duplicateSelected();
        else if (msg.action === "swap") this.swapLayoutChildren();
        else if (msg.action === "delete") this.removeSelected();
        else if (msg.action === "template-edit") this.editDynamicTemplate();
        else if (msg.action === "template-preview") this.finishDynamicTemplate();
      } else if (msg.t === "move") {
        const moveMsg = msg as {
          id?: string;
          targetId?: string;
          placement?: "before" | "after" | "inside";
        };
        if (
          typeof moveMsg.id === "string"
          && typeof moveMsg.targetId === "string"
          && (moveMsg.placement === "before"
            || moveMsg.placement === "after"
            || moveMsg.placement === "inside")
        ) {
          this.moveBlock(moveMsg.id, moveMsg.targetId, moveMsg.placement);
        }
      } else if (msg.t === "open-add") {
        const afterId =
          typeof msg.id === "string"
            ? msg.id
            : typeof msg.afterId === "string"
              ? msg.afterId
              : this.selectedId;
        this.insertAfterId = afterId;
        if (afterId) this.select(afterId);
        this.createOpen = true;
        this.createTab = "elements";
      } else if (msg.t === "add" && typeof msg.type === "string") {
        const afterId = msg.afterId || this.selectedId;
        this.insertBlockNear(msg.type, afterId ?? null, {
          extra: msg.extra !== false && !!this.boot.storefrontUrl,
        });
      }
    },

    applyPatch(msg: {
      id: string;
      key: string;
      value?: string;
      done?: boolean;
      operation?: string;
    }) {
      const block = this.findBlock(msg.id);
      if (!block) return;
      if (block.payload[msg.key] === (msg.value ?? "")) {
        if (msg.done && this.historyGroup?.key === `${msg.id}:${msg.key}`) this.historyGroup = null;
        return;
      }
      const designKeys = new Set([
        "gallery_items",
        "text_color",
        "bg_color",
        "border_color",
        "quote_border_color",
        "hover_color",
        "font_family",
        "font_size",
        "font_weight",
        "align",
        "spacing",
        "opacity",
        "visible",
        "border_w",
        "border_style",
        "shadow",
        "radius",
        "padding",
        "margin", "margin_top", "margin_right", "margin_bottom", "margin_left",
        "padding_top", "padding_right", "padding_bottom", "padding_left",
        "line",
        "thickness",
        "size",
        "weight",
        "fill",
        "grade",
        "opsz",
        "icon_style",
        "name",
      ]);
      const isDesign = designKeys.has(msg.key);
      // Suppress preview state echo while the iframe is the source of truth for
      // content HTML. Design props must sync so textDesign/blockDesign re-render.
      if (!isDesign) {
        this.previewPatchOpen = true;
        if (previewTimer) {
          clearTimeout(previewTimer);
          previewTimer = undefined;
        }
      }
      block.payload = { ...block.payload, [msg.key]: msg.value ?? "" };
      this.markDirty(msg.operation === "typing" ? `${msg.id}:${msg.key}` : "", !!msg.done);
      const owner = this.dynamicOwnerOf(msg.id);
      if (owner && this.templateOwner?.id === owner.id) {
        const source = this.boot.dynamicSources?.find((entry) => entry.id === owner.payload.source);
        const templates = Array.isArray(owner.payload.children) ? owner.payload.children as BuilderBlock[] : [];
        const limit = Math.max(1, Math.min(100, Number(owner.payload.limit) || 12));
        const items = (source?.items || []).slice(0, limit).map((item) => this.bindTemplateBlocks(templates, item));
        this.postPreview({ t: "dynamic-items", id: owner.id, items });
      }
      if (isDesign) {
        this.queuePreviewState(true);
        return;
      }
      if (patchGateTimer) clearTimeout(patchGateTimer);
      patchGateTimer = setTimeout(
        () => {
          this.previewPatchOpen = false;
          patchGateTimer = undefined;
          if (this.previewInspectorPending) this.queuePreviewState();
        },
        msg.done ? 120 : 500,
      );
    },

    typeLabel(type: string): string {
      return (
        (this.boot.blockTypes || []).find((t) => t.value === type)?.label ||
        type
      );
    },

    /** Layers / headings: layout shows container purpose (variant). */
    layerTypeLabel(block: BuilderBlock): string {
      const base = this.typeLabel(block.type);
      if (block.type !== "layout") return base;
      return base + " · " + this.layoutVariantLabel(block);
    },

    workspaceClass(): string {
      const parts: string[] = ["has-sidebar"];
      if (this.modalTab || this.createOpen) parts.push("has-library");
      if (this.previewLoading) parts.push("is-booting");
      return parts.join(" ");
    },

    layerItemClass(row: {
      block: BuilderBlock;
      depth: number;
    }): string {
      const parts: string[] = [];
      if (this.layerDragId === row.block.id) parts.push("is-dragging");
      if (this.dropTargetId === row.block.id && this.layerDropPlacement) {
        parts.push("is-drop-target", "is-drop-" + this.layerDropPlacement);
      }
      if (["layout", "dynamic", "form"].includes(row.block.type)) parts.push("is-container");
      return parts.join(" ");
    },

    layerItemStyle(row: { depth: number }): string {
      return "--cms-layer-depth:" + row.depth;
    },

    /** Flat rows for the layers dock (nested layouts indented by depth). */
    layerRows(includeCollapsed = false): Array<{
      block: BuilderBlock;
      depth: number;
      index: number;
      parentId: string | null;
    }> {
      type Row = {
        block: BuilderBlock;
        depth: number;
        index: number;
        parentId: string | null;
      };
      const rows: Row[] = [];
      const walk = (
        list: BuilderBlock[],
        depth: number,
        parentId: string | null,
      ) => {
        list.forEach((block, index) => {
          rows.push({ block, depth, index, parentId });
          const kids = block.payload.children;
          if (Array.isArray(kids) && kids.length > 0 && (includeCollapsed || !this.collapsedLayerIds.includes(block.id))) {
            walk(kids as BuilderBlock[], depth + 1, block.id);
          }
        });
      };
      walk(this.blocks, 0, null);
      return rows;
    },

    hasLayerChildren(block: BuilderBlock): boolean {
      return Array.isArray(block.payload.children) && block.payload.children.length > 0;
    },

    toggleLayer(id: string) {
      this.collapsedLayerIds = this.collapsedLayerIds.includes(id)
        ? this.collapsedLayerIds.filter((collapsedId) => collapsedId !== id)
        : [...this.collapsedLayerIds, id];
    },

    layerDefaultName(index: number): string {
      const tpl =
        this.boot.labels.layerDefaultName || "block #%num%";
      return tpl.replace("%num%", String(index + 1));
    },

    layerAdminLabel(block: BuilderBlock): string {
      const raw = block.payload.admin_label;
      return typeof raw === "string" ? raw : "";
    },

    layerDisplayName(block: BuilderBlock, index: number): string {
      const custom = this.layerAdminLabel(block).trim();
      if (custom) {
        return custom.length > 42 ? custom.slice(0, 42) + "…" : custom;
      }
      return this.layerDefaultName(index);
    },

    selectedLayerDefaultName(): string {
      if (!this.selectedId) return this.layerDefaultName(0);
      const row = this.layerRows(true).find((r) => r.block.id === this.selectedId);
      return this.layerDefaultName(row?.index ?? 0);
    },

    startLayerRename(id: string, event?: Event) {
      event?.preventDefault();
      event?.stopPropagation();
      this.select(id);
      this.layerRenameId = id;
      this.$nextTick(() => {
        const root = this.$el as HTMLElement;
        const input = root.querySelector(
          ".cms-builder__layer-name-input",
        ) as HTMLInputElement | null;
        input?.focus();
        input?.select();
      });
    },

    commitLayerRename(id: string, event: Event) {
      if (this.layerRenameId !== id) return;
      const input = event.target as HTMLInputElement;
      const block = this.findBlock(id);
      if (block) {
        const next = (input.value || "").trim();
        block.payload = { ...block.payload, admin_label: next };
        this.markDirty();
      }
      this.layerRenameId = null;
    },

    cancelLayerRename() {
      this.layerRenameId = null;
    },

    layerLabel(block: BuilderBlock): string {
      const p = block.payload;
      const keys = [
        "text",
        "label",
        "alt",
        "caption",
        "title",
        "url",
        "name",
        "body",
      ];
      for (const key of keys) {
        const raw = p[key];
        if (typeof raw !== "string" || !raw.trim()) continue;
        const plain = raw.replace(/<[^>]+>/g, "").replace(/\s+/g, " ").trim();
        if (!plain) continue;
        if (plain.toLocaleLowerCase() === this.typeLabel(block.type).toLocaleLowerCase()) return "";
        return plain.length > 42 ? plain.slice(0, 42) + "…" : plain;
      }
      if (block.type === "layout") {
        const kids = Array.isArray(p.children) ? p.children.length : 0;
        return kids ? `${kids}×` : "—";
      }
      if (block.type === "spacer") {
        return String(p.size || "md");
      }
      return "";
    },

    onLayerDragStart(id: string, event: DragEvent) {
      event.stopPropagation();
      this.layerDragId = id;
      this.dropTargetId = null;
      this.layerDropPlacement = null;
      event.dataTransfer?.setData("text/plain", id);
      if (event.dataTransfer) event.dataTransfer.effectAllowed = "move";
    },

    onLayerDragEnd() {
      this.layerDragId = null;
      this.dropTargetId = null;
      this.layerDropPlacement = null;
    },

    layerPlacement(id: string, event: DragEvent): "before" | "after" | "inside" {
      const rect = (event.currentTarget as HTMLElement).getBoundingClientRect();
      const y = (event.clientY - rect.top) / Math.max(1, rect.height);
      if (["layout", "dynamic", "form"].includes(this.findBlock(id)?.type || "")) {
        if (y < 0.25) return "before";
        if (y > 0.75) return "after";
        return "inside";
      }
      return y < 0.5 ? "before" : "after";
    },

    onLayerDragOver(id: string | null, event: DragEvent) {
      event.stopPropagation();
      const dragId = this.layerDragId;
      if (!dragId || dragId === id || (id && this.isDescendantOf(dragId, id))) {
        this.dropTargetId = null;
        this.layerDropPlacement = null;
        return;
      }
      event.preventDefault();
      if (event.dataTransfer) event.dataTransfer.dropEffect = "move";
      this.dropTargetId = id;
      this.layerDropPlacement = id ? this.layerPlacement(id, event) : null;
    },

    onLayerDragLeave(id: string | null, event: DragEvent) {
      event.stopPropagation();
      const next = event.relatedTarget as Node | null;
      if (next && (event.currentTarget as HTMLElement).contains(next)) return;
      if (this.dropTargetId === id) {
        this.dropTargetId = null;
        this.layerDropPlacement = null;
      }
    },

    onLayerDrop(id: string | null, event: DragEvent) {
      event.preventDefault();
      event.stopPropagation();
      const dragId =
        this.layerDragId || event.dataTransfer?.getData("text/plain") || null;
      const placement = id ? this.layerPlacement(id, event) : "after";
      this.onLayerDragEnd();
      if (!dragId || !this.findBlock(dragId)) return;
      if (!id) {
        const last = this.blocks[this.blocks.length - 1];
        if (last && last.id !== dragId) {
          this.moveBlock(dragId, last.id, "after");
        }
        return;
      }
      if (dragId === id) return;
      this.moveBlock(dragId, id, placement);
    },

    dividerLabel(block: BuilderBlock): string {
      const style = String(block.payload.style || "hero").trim() || "hero";
      return `§ ${style}`;
    },

    expandPreview(text: string): string {
      const values = { ...(this.boot.varValues || {}) };
      if (this.templateOwner) {
        const source = this.boot.dynamicSources?.find((entry) => entry.id === this.templateOwner?.payload.source);
        for (const [key, value] of Object.entries(source?.items?.[0] || {})) values[`item.${key}`] = String(value ?? "");
      }
      return text.replace(
        /\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g,
        (full, path: string) => {
          if (Object.prototype.hasOwnProperty.call(values, path)) {
            return values[path] ?? full;
          }
          return full;
        },
      );
    },

    get formFieldHasPlaceholder(): boolean {
      return this.selected?.type === "form_field" && !["radio", "checkbox", "consent", "file"].includes(String(this.selected.payload.control));
    },

    get formActionMissing(): boolean {
      return this.selected?.type === "form" && !(this.boot.formActions || []).some(action => action.id === this.selected?.payload.action);
    },

    get formFieldNameInvalid(): boolean {
      return this.selected?.type === "form_field" && (!/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/.test(String(this.selected.payload.name || "")) || ["website_url", "website", "gereq_visitor", "gereq_pass_token", "captcha_token", "captcha_answer", "constructor", "prototype", "__proto__"].includes(String(this.selected.payload.name)));
    },

    get formFieldNameDuplicate(): boolean {
      if (this.selected?.type !== "form_field") return false;
      const owner = this.formOwnerOf(this.selected.id);
      const count = (blocks: BuilderBlock[]): number => blocks.reduce((total, block) => total + (block.type === "form_field" && block.payload.name === this.selected?.payload.name ? 1 : 0) + (Array.isArray(block.payload.children) ? count(block.payload.children as BuilderBlock[]) : 0), 0);
      return owner ? count(owner.payload.children as BuilderBlock[] || []) > 1 : false;
    },

    get formFieldActionMismatch(): boolean {
      const field = this.selected;
      if (field?.type !== 'form_field') return false;
      const owner = this.formOwnerOf(field.id);
      const action = this.formActionOptions.find(item => item.id === owner?.payload.action);
      const mapping = owner?.payload.field_mapping as Record<string, string> | undefined;
      return Object.entries(action?.fields || {}).some(([target, control]) => (mapping?.[target] || target) === field.payload.name && (field.payload.control !== control || Number(field.payload.required) !== 1));
    },

    get formFieldOptionsMissing(): boolean {
      return this.selected?.type === "form_field" && ["select", "radio"].includes(String(this.selected.payload.control)) && !String(this.selected.payload.options || "").split(/\r?\n/).some(option => option.trim());
    },

    get formFields(): BuilderBlock[] {
      if (this.selected?.type !== "form") return [];
      const fields: BuilderBlock[] = [];
      const walk = (blocks: BuilderBlock[]) => {
        for (const block of blocks) {
          if (block.type === "form_field") fields.push(block);
          if (Array.isArray(block.payload.children)) walk(block.payload.children as BuilderBlock[]);
        }
      };
      walk(Array.isArray(this.selected.payload.children) ? this.selected.payload.children as BuilderBlock[] : []);
      return fields;
    },

    formFieldHasIssue(field: BuilderBlock): boolean {
      const name = String(field.payload.name || '');
      if (!/^[a-zA-Z][a-zA-Z0-9_-]{0,79}$/.test(name) || ['website_url', 'website', 'gereq_visitor', 'gereq_pass_token', 'captcha_token', 'captcha_answer', 'constructor', 'prototype', '__proto__'].includes(name)) return true;
      if (this.formFields.filter(candidate => candidate.payload.name === name).length > 1) return true;
      if (['select', 'radio'].includes(String(field.payload.control)) && !String(field.payload.options || '').split(/\r?\n/).some(option => option.trim())) return true;
      return this.formRequiredFields.some(requirement => this.formMappingValue(requirement.name) === name && !requirement.ready);
    },

    formMappingValue(name: string): string {
      const mapping = this.selected?.payload.field_mapping as Record<string, string> | undefined;
      return mapping?.[name] || name;
    },

    setFormMapping(name: string, event: Event) {
      if (this.selected?.type !== "form") return;
      const value = (event.target as HTMLSelectElement).value;
      const mapping = { ...(this.selected.payload.field_mapping as Record<string, string> || {}) };
      if (value && value !== name) mapping[name] = value; else delete mapping[name];
      this.selected.payload.field_mapping = mapping;
      this.commitBlocks();
    },

    moveFormField(id: string, direction: number) {
      const list = this.listOf(id);
      const index = list.findIndex(block => block.id === id);
      const next = index + direction;
      if (index < 0 || next < 0 || next >= list.length) return;
      [list[index], list[next]] = [list[next], list[index]];
      this.reindex(); this.commitBlocks();
    },

    dropFormField(id: string, event: DragEvent) {
      event.preventDefault(); event.stopPropagation();
      const dragged = this.layerDragId;
      if (dragged && this.formFields.some(field => field.id === dragged) && dragged !== id) {
        const selectedId = this.selectedId;
        this.moveBlock(dragged, id, "before");
        this.selectedId = selectedId;
      }
      this.onLayerDragEnd();
    },

    get formOptionRows(): Array<{ index: number; value: string }> {
      return String(this.selected?.payload.options || "").split(/\r?\n/).map((value, index) => ({ index, value }));
    },

    updateFormOption(index: number, event: Event) {
      if (this.selected?.type !== "form_field") return;
      const options = String(this.selected.payload.options || "").split(/\r?\n/);
      options[index] = (event.target as HTMLInputElement).value.replace(/[\r\n]/g, "");
      this.selected.payload.options = options.join("\n"); this.commitBlocks();
    },

    addFormOption() {
      if (this.selected?.type !== "form_field") return;
      this.selected.payload.options = String(this.selected.payload.options || "") + "\n";
      this.commitBlocks();
    },

    removeFormOption(index: number) {
      if (this.selected?.type !== "form_field") return;
      this.selected.payload.options = String(this.selected.payload.options || "").split(/\r?\n/).filter((_, i) => i !== index).join("\n");
      this.commitBlocks();
    },

    formTestOptions(field: BuilderBlock): string[] {
      return String(field.payload.options || '').split(/\r?\n/).map(option => option.trim()).filter(Boolean);
    },

    setFormTestValue(name: string, event: Event) {
      const input = event.target as HTMLInputElement;
      if (input.type === 'file') {
        if (input.files?.[0]) this.formTestFiles[name] = input.files[0]; else delete this.formTestFiles[name];
        this.formTestValues[name] = input.files?.[0]?.name || '';
      } else {
        this.formTestValues[name] = input.type === 'checkbox' ? (input.checked ? '1' : '') : input.value;
      }
      delete this.formTestErrors[name];
      if (!Object.keys(this.formTestErrors).length) this.formTestResult = "";
    },

    applyFormTestResult(json: { data?: { message?: string }; error?: { message?: string; fields?: Record<string, unknown> } }) {
      const names = new Set(this.formFields.map(field => String(field.payload.name)));
      this.formTestErrors = Object.fromEntries(Object.keys(json.error?.fields || {}).filter(name => names.has(name)).map(name => [name, true]));
      this.formTestResult = Object.keys(this.formTestErrors).length
        ? this.boot.labels.formTestInvalid
        : json.error?.fields?.form ? this.boot.labels.formTestConfiguration : json.data?.message || json.error?.message || this.boot.labels.formTestFailed;
    },

    async testFormBackend() {
      if (this.selected?.type !== "form" || !this.boot.formTestUrl || this.formTestBusy) return;
      const formId = this.selected.id;
      this.formTestBusy = true; this.formTestResult = ""; this.formTestErrors = Object.create(null);
      try {
        const security: Record<string, string> = {};
        for (const name of ["gereq_visitor", "gereq_pass_token", "captcha_token", "captcha_answer"]) {
          security[name] = (document.querySelector<HTMLInputElement>(`input[name="${name}"]`)?.value || "");
        }
        const values = Object.fromEntries(this.formFields.map(field => [String(field.payload.name), this.formTestValues[String(field.payload.name)] || '']));
        const files = this.formFields.filter(field => field.payload.control === 'file' && this.formTestFiles[String(field.payload.name)]).map(field => [String(field.payload.name), this.formTestFiles[String(field.payload.name)]] as const);
        let body: string | FormData = JSON.stringify({ ...security, form: this.selected.payload, values });
        const multipart = files.length > 0;
        if (multipart) {
          const data = new FormData();
          data.set('form', JSON.stringify(this.selected.payload)); data.set('values', JSON.stringify(values));
          for (const [name, value] of Object.entries(security)) data.set(name, value);
          for (const [name, file] of files) data.set(`files[${name}]`, file);
          body = data;
        }
        const response = await fetch(this.boot.formTestUrl, {
          method: "POST", credentials: "same-origin",
          headers: { ...(multipart ? {} : { "Content-Type": "application/json" }), "X-CSRF-TOKEN": csrfToken(this.boot), "X-Requested-With": "XMLHttpRequest" },
          body,
        });
        const json = await response.json();
        if (this.selectedId === formId) this.applyFormTestResult(json);
      } catch { this.formTestResult = this.boot.labels.formTestFailed || ""; }
      finally { this.formTestBusy = false; }
    },

    get formSubmissionsUrl(): string {
      if (this.selected?.type !== "form" || this.selected.id.startsWith("tmp_") || !this.boot.formAttemptsUrl) return "";
      const query = new URLSearchParams({ page: this.boot.slug, form: this.selected.id });
      return this.boot.formAttemptsUrl + "?" + query.toString();
    },

    get formActionOptions() { return this.boot.formActions || []; },

    formRequirementLabel(name: string): string {
      const translated = this.boot.labels["formField." + name];
      if (translated) return translated;
      const existing = this.formFields.find(field => field.payload.name === this.formMappingValue(name));
      const label = String(existing?.payload.label || name).replace(/[_-]+/g, " ");
      return label.charAt(0).toLocaleUpperCase() + label.slice(1);
    },

    formMappingCandidates(name: string): BuilderBlock[] {
      return this.formFields.filter(field => field.payload.name !== name);
    },

    get formRequiredFields(): Array<{ name: string; control: string; ready: boolean; duplicate: boolean }> {
      const host = this.selected;
      if (host?.type !== "form") return [];
      const action = this.formActionOptions.find((item) => item.id === host.payload.action);
      if (!action) return [];
      const fields: BuilderBlock[] = [];
      const walk = (blocks: BuilderBlock[]) => {
        for (const block of blocks) {
          if (block.type === "form_field") fields.push(block);
          if (Array.isArray(block.payload.children)) walk(block.payload.children as BuilderBlock[]);
        }
      };
      walk(Array.isArray(host.payload.children) ? host.payload.children as BuilderBlock[] : []);
      return Object.entries(action.fields).map(([name, control]) => {
        const source = this.formMappingValue(name);
        const matches = fields.filter((field) => field.payload.name === source);
        const conflict = (source !== name && fields.some(field => field.payload.name === name))
          || Object.keys(action.fields).filter(target => this.formMappingValue(target) === source).length > 1;
        return { name, control, duplicate: matches.length > 1 || conflict,
          ready: !conflict && matches.length === 1 && matches[0].payload.control === control && Number(matches[0].payload.required) === 1 };
      });
    },

    get formNeedsRequiredFields(): boolean {
      return this.formRequiredFields.some((field) => !field.ready);
    },

    injectRequiredFormFields() {
      const host = this.selected;
      if (host?.type !== "form") return;
      const requirements = this.formRequiredFields;
      if (!requirements.length || !this.formNeedsRequiredFields) return;
      const fields: BuilderBlock[] = [];
      const walk = (blocks: BuilderBlock[]) => {
        for (const block of blocks) {
          if (block.type === "form_field") fields.push(block);
          if (Array.isArray(block.payload.children)) walk(block.payload.children as BuilderBlock[]);
        }
      };
      const kids = this.ensureLayoutChildren(host);
      walk(kids);
      let changed = false;
      for (const field of requirements) {
        const source = this.formMappingValue(field.name);
        const matches = fields.filter((block) => block.payload.name === source);
        // Duplicate names require an editor decision; never delete or rename user fields.
        if (field.duplicate || field.ready) continue;
        if (matches.length === 1) {
          matches[0].payload = { ...matches[0].payload, control: field.control, required: 1 };
        } else {
          kids.push({
            id: "tmp_" + Math.random().toString(36).slice(2, 10), type: "form_field", sort_order: kids.length,
            payload: { ...defaultPayload("form_field"), name: source, control: field.control,
              label: this.boot.labels["formField." + field.name] || field.name,
              required: 1, full: field.control === "textarea" ? 1 : 0 },
          });
        }
        changed = true;
      }
      if (changed) {
        host.payload = { ...host.payload, children: [...kids] };
        this.commitBlocks();
      }
    },

    onFormActionChange(event: Event) {
      if (this.selected?.type !== "form") return;
      const action = this.formActionOptions.find((item) => item.id === (event.target as HTMLSelectElement).value);
      this.selected.payload.action = action?.id || "";
      this.selected.payload.field_mapping = {};
      this.formTestValues = Object.create(null); this.formTestFiles = Object.create(null); this.formTestResult = ""; this.formTestErrors = Object.create(null);
      if (action && this.ensureLayoutChildren(this.selected).length === 0 && Object.keys(action.fields).length) {
        this.injectRequiredFormFields();
        return;
      }
      this.commitBlocks();
    },

    addFormField() {
      const host = this.selected;
      if (host?.type !== "form") return;
      const kids = this.ensureLayoutChildren(host);
      this.addBlock("form_field", kids.length, { list: kids, layoutHost: host });
    },

    addBlock(
      type: string,
      atIndex: number | null = null,
      opts: {
        extra?: boolean;
        list?: BuilderBlock[];
        /** When inserting into a layout, reassign that layout’s children after splice. */
        layoutHost?: BuilderBlock;
      } = {},
    ) {
      const list = opts.list ?? this.blocks;
      const host = opts.layoutHost || this.blocks.find((block) => block.payload.children === list);
      const owner = host ? this.dynamicOwnerOf(host.id) : list !== this.blocks ? this.dynamicOwnerOf(list[0]?.id || null) : null;
      if (owner && !DYNAMIC_CHILD_TYPES.has(type)) return;
      const form = this.formOwnerOf(host?.id || (list !== this.blocks ? list[0]?.id : null) || null);
      if (form && !FORM_CHILD_TYPES.has(type)) return;
      const id = "tmp_" + Math.random().toString(36).slice(2, 10);
      const payload = defaultPayload(type);
      if (type === "form_field") {
        payload.name = "field_" + id.slice(4);
        payload.label = this.boot.labels["formField.new"] || "";
      }
      // Branded storefront chrome: keep fixed slots stable; extras render in section strip.
      if (opts.extra || (this.boot.storefrontUrl && atIndex !== null)) {
        payload.chrome_extra = 1;
      }
      const block: BuilderBlock = {
        id,
        type,
        sort_order: list.length + 1,
        payload,
        _new: true,
      };
      if (atIndex === null || atIndex < 0 || atIndex > list.length) {
        list.push(block);
      } else {
        list.splice(atIndex, 0, block);
      }
      if (opts.layoutHost) {
        opts.layoutHost.payload = {
          ...opts.layoutHost.payload,
          children: [...list],
        };
      }
      this.selectedId = id;
      this.insertAfterId = null;
      this.commitBlocks();
    },

    /**
     * Branded Vue chrome only (`/`, `/kontakt`, `/404`). Soft pages (galerie…)
     * must not show this. Debounce so a stale route `/` does not false-trigger.
     * Every builder open / refresh (no sessionStorage).
     */
    scheduleFixedLayoutNotice() {
      // Wait until boot overlay is gone so the notice isn't buried under it.
      if (this.previewLoading) return;
      const self = this as { _fixedLayoutNoticeTimer?: ReturnType<typeof setTimeout> };
      if (self._fixedLayoutNoticeTimer) clearTimeout(self._fixedLayoutNoticeTimer);
      self._fixedLayoutNoticeTimer = setTimeout(() => {
        self._fixedLayoutNoticeTimer = undefined;
        if (this.fixedLayout) void this.showFixedLayoutNotice();
      }, 80);
    },

    async showFixedLayoutNotice() {
      if (!this.fixedLayout) return;
      const self = this as {
        _fixedLayoutNoticeShown?: boolean;
        _fixedLayoutNoticeOpen?: boolean;
      };
      if (self._fixedLayoutNoticeShown || self._fixedLayoutNoticeOpen) return;
      self._fixedLayoutNoticeShown = true;
      self._fixedLayoutNoticeOpen = true;
      try {
        await askConfirm({
          title:
            this.boot.labels.fixedLayoutTitle ||
            "Pevný layout stránky",
          body:
            this.boot.labels.fixedLayoutBody ||
            "Obsah (texty, obrázky, ikony, odkazy) je plně editovatelný. Rozložení stránky je dané šablonou ve Vue/React a v builderu ho nelze měnit.",
          confirmLabel:
            this.boot.labels.fixedLayoutOk || "Rozumím",
          cancelLabel:
            this.boot.labels.fixedLayoutClose || "Zavřít",
          danger: false,
        });
      } finally {
        self._fixedLayoutNoticeOpen = false;
      }
    },

    get insertionPlacements(): string[] {
      const block = this.findBlock(this.insertAfterId || this.selectedId || "");
      return block && ["layout", "dynamic", "form"].includes(block.type) ? ["inside", "before", "after"] : ["after", "before"];
    },

    get effectiveInsertPlacement(): string {
      return this.insertionPlacements.includes(this.insertPlacement) ? this.insertPlacement : this.insertionPlacements[0];
    },

    setInsertPlacement(event: Event) {
      const value = (event.target as HTMLSelectElement).value;
      if (this.insertionPlacements.includes(value)) this.insertPlacement = value as "inside" | "before" | "after";
    },

    get insertionDescription(): string {
      const block = this.findBlock(this.insertAfterId || this.selectedId || "");
      const name = block ? String(block.payload.admin_label || this.typeLabel(block.type)) : "";
      return `${this.boot.labels[`insert.${this.effectiveInsertPlacement}`] || this.effectiveInsertPlacement}${name ? ` · ${name}` : ""}`;
    },

    addBlockAfterSelection(type: string) {
      const keepLibrary = this.createOpen;
      const afterId = this.insertAfterId || this.selectedId;
      if (afterId && this.effectiveInsertPlacement !== "inside") {
        const list = this.listOf(afterId);
        const index = list.findIndex((block) => block.id === afterId);
        this.addBlock(type, index < 0 ? null : index + (this.effectiveInsertPlacement === "after" ? 1 : 0), { list, extra: !!this.boot.storefrontUrl });
      } else {
        this.insertBlockNear(type, afterId, { extra: !!this.boot.storefrontUrl });
      }
      this.insertPlacement = "auto";
      if (keepLibrary) this.openLibraryTab("elements");
    },

    /**
     * Insert near `afterId`: into a layout’s children when the ref is a container,
     * otherwise as the next sibling in the same list (supports nested layouts).
     */
    insertBlockNear(
      type: string,
      afterId: string | null,
      opts: { extra?: boolean } = {},
    ) {
      if (afterId) {
        const ref = this.findBlock(afterId);
        if (ref?.type === "dynamic" && type !== "dynamic" && this.templateOwner?.id !== ref.id) {
          if (!DYNAMIC_CHILD_TYPES.has(type)) return;
          this.selectedId = ref.id;
          this.editDynamicTemplate();
        }
        if (ref && (["layout", "form"].includes(ref.type) || (ref.type === "dynamic" && type !== "dynamic" && this.templateOwner?.id === ref.id))) {
          const kids = this.ensureLayoutChildren(ref);
          this.addBlock(type, kids.length, {
            ...opts,
            list: kids,
            layoutHost: ref,
          });
          return;
        }
        const list = this.listOf(afterId);
        const idx = list.findIndex((b) => b.id === afterId);
        this.addBlock(type, idx >= 0 ? idx + 1 : null, { ...opts, list });
        return;
      }
      this.addBlock(type, null, opts);
    },

    /** Inserts complete landing one-page (hero → trust → listings → why → services → how → types → testimonials → insights → cta → contact → footer). */
    async insertOnePageTemplate() {
      if (this.blocks.length > 0) {
        const msg =
          this.boot.labels.replaceOnePage ||
          "Nahradit stávající bloky šablonou one-page?";
        const ok = await askConfirm({
          title: this.boot.labels.onePageInserted || "One-page",
          body: msg,
          confirmLabel: this.boot.labels.sandboxSave || undefined,
          danger: true,
        });
        if (!ok) return;
      }

      const defs: Array<{ type: string; payload: BlockPayload }> = [
        {
          type: "divider",
          payload: {
            style: "hero",
          },
        },
        {
          type: "text",
          payload: {
            body: "Realitní kancelář · Praha a Brno",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Najděte byt nebo dům, který sedne",
            level: 1,
          },
        },
        {
          type: "text",
          payload: {
            body: "U každé nabídky najdete cenu, metráž, energetickou třídu i kontakt na makléře, který ji vede.",
          },
        },
        {
          type: "image",
          payload: {
            media_id: "",
            url: "https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1920&q=80",
            alt: "Moderní bydlení",
            caption: "",
          },
        },
        {
          type: "divider",
          payload: {
            style: "trust",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Ověřené nabídky",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Každá nemovitost prochází kontrolou podkladů před zveřejněním.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Férová cena",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Transparentní ocenění bez skrytých příplatků a překvapení.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Odborná podpora",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Lokální makléři, kteří znají čtvrť i aktuální trh.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Bezpečné jednání",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Jasná smlouva, ověřené dokumenty a bezpečný převod.",
          },
        },
        {
          type: "divider",
          payload: {
            style: "listings",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Aktuální nabídky",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Vybrané nemovitosti z Prahy, Brna a okolí — aktualizujeme denně.",
          },
        },
        {
          type: "divider",
          payload: {
            style: "why",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Proč s námi",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Stavíme katalog i komunikaci tak, abyste měli jistotu dřív, než přijdete na prohlídku.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Zjistit více",
            url: "/stranka/o-nas",
            style: "primary",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Lokální znalost",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Makléři znají konkrétní ulice, developery i dostupnost v lokalitě.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Ověřené podklady",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Dokumenty k nemovitosti dostanete ještě před prohlídkou.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Rychlá domluva",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Termín potvrzujeme do jednoho pracovního dne.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Přímý kontakt",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Telefon a e-mail u každé nabídky — bez zbytečných formulářů.",
          },
        },
        {
          type: "divider",
          payload: {
            style: "services",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Služby",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Od prodeje přes pronájem až po konzultaci — vždy s jasným postupem.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Prodej nemovitosti",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Připravíme prezentaci, nastavíme cenu a dovedeme obchod do konce.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Prodej nemovitosti",
            url: "/nabidky?offer=sale",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Osobní konzultace",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Domluvte si schůzku a projdeme, co dává smysl právě vám.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Osobní konzultace",
            url: "/stranka/kontakt",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Pronájem",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Najdeme nájemce nebo byt k bydlení — férově a bez zmatků.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Pronájem",
            url: "/nabidky?offer=rent",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Investice",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Tipujeme byty a domy s reálným výnosem i rizikem.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Investice",
            url: "/nabidky?offer=sale",
            style: "ghost",
          },
        },
        {
          type: "divider",
          payload: {
            style: "how",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Jak to funguje",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Od prvního filtru k prohlídce ve čtyřech jasných krocích.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Vyfiltrujte nabídky",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Zadejte lokalitu, cenové rozpětí, dispozici a plochu. Filtry fungují bez registrace.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Kontaktujte makléře",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "U každé nabídky je uvedený makléř, který ji vede, včetně telefonu a e-mailu.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Domluvte si prohlídku",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Termín potvrzujeme do jednoho pracovního dne. Podklady k nemovitosti dostanete předem.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Dohodněte podmínky",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Pomůžeme s nabídkou, smlouvou i předáním klíčů — bez zbytečného stresu.",
          },
        },
        {
          type: "divider",
          payload: {
            style: "types",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Typy nemovitostí",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Vyberte kategorii a rovnou zobrazte aktuální nabídky.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Byty",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Od 3,5 mil.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Byty",
            url: "/nabidky?type=apartment",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Domy",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Od 6 mil.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Domy",
            url: "/nabidky?type=house",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Pozemky",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Od 1,2 mil.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Pozemky",
            url: "/nabidky?type=land",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Komerční",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Od 4 mil.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Komerční",
            url: "/nabidky?type=commercial",
            style: "ghost",
          },
        },
        {
          type: "divider",
          payload: {
            style: "testimonials",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Co říkají klienti",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Krátké zkušenosti lidí, kteří s námi našli bydlení nebo prodali.",
          },
        },
        {
          type: "quote",
          payload: {
            quote:
              "Makléř nám našel byt během týdne a všechno vysvětlil srozumitelně.",
            author: "Jana N.",
            role: "Klientka · Pronájem",
          },
        },
        {
          type: "quote",
          payload: {
            quote:
              "Prodej domu proběhl férově — cena i termíny seděly s tím, co jsme si domluvili.",
            author: "Petr K.",
            role: "Klient · Prodej",
          },
        },
        {
          type: "quote",
          payload: {
            quote:
              "Měli jsme jasný plán a konkrétní tipy, ne jen katalog. Doporučuji.",
            author: "Martina S.",
            role: "Klientka · Koupě",
          },
        },
        {
          type: "divider",
          payload: {
            style: "insights",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Tipy a novinky",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Praktické články k nákupu, prodeji i pronájmu.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Jak číst energetický štítek",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Průvodce",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Číst",
            url: "/stranka/o-nas",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Na co se ptát na prohlídce",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Tipy",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Číst",
            url: "/stranka/o-nas",
            style: "ghost",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Pronájem bez zbytečných rizik",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Pronájem",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Číst",
            url: "/nabidky?offer=rent",
            style: "ghost",
          },
        },
        {
          type: "divider",
          payload: {
            style: "cta",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Připraveni najít nové bydlení?",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Projděte aktuální katalog nebo nám napište, co hledáte.",
          },
        },
        {
          type: "cta",
          payload: {
            label: "Prohlédnout nabídky",
            url: "/nabidky",
            style: "primary",
          },
        },
        {
          type: "divider",
          payload: {
            style: "contact",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Zavolejte",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "+420 777 000 000",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Napište",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "info@realestates.cz",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Navštivte nás",
            level: 3,
          },
        },
        {
          type: "text",
          payload: {
            body: "Praha · Brno",
          },
        },
        {
          type: "divider",
          payload: {
            style: "footer",
          },
        },
        {
          type: "text",
          payload: {
            body: "RealEstates je realitní kancelář s nabídkami v Praze, Brně a okolí. Transparentní ceny, přímý kontakt na makléře.",
          },
        },
        {
          type: "heading",
          payload: {
            text: "Newsletter",
            level: 2,
          },
        },
        {
          type: "text",
          payload: {
            body: "Nové tipy a vybrané nabídky jednou týdně — bez spamu.",
          },
        },
        {
          type: "text",
          payload: {
            body: "Transparentní nabídky. Lidský přístup.",
          },
        },
      ];

      this.blocks = defs.map((def, i) => ({
        id: tmpBlockId(String(i)),
        type: def.type,
        sort_order: i + 1,
        payload: def.payload,
        _new: true,
      }));
      this.selectedId = this.blocks[0]?.id ?? null;
      this.reindex();
      this.markDirty();
      toast(
        this.boot.labels.onePageInserted || "Šablona one-page vložena",
        "success",
      );
    },

    get layoutPageTemplates(): Array<{
      id: LayoutPageTemplateId;
      label: string;
      desc: string;
      tag: string;
    }> {
      const L = this.boot.labels || {};
      return LAYOUT_PAGE_TEMPLATE_META.map((meta) => ({
        id: meta.id,
        label: String(L[meta.labelKey] || meta.id),
        desc: String(L[meta.descKey] || ""),
        tag: meta.tag,
      }));
    },

    /**
     * Insert a sophisticated demo page built from layout container variants.
     * Replaces current canvas after confirm (same as one-page).
     */
    async insertLayoutPageTemplate(id: LayoutPageTemplateId) {
      if (this.blocks.length > 0) {
        const msg =
          this.boot.labels.layoutPageReplace ||
          this.boot.labels.replaceOnePage ||
          "Nahradit stávající bloky touto šablonou?";
        const ok = await askConfirm({
          title: this.boot.labels.layoutPageTitle || "Šablona stránky",
          body: msg,
          confirmLabel: this.boot.labels.sandboxSave || undefined,
          danger: true,
        });
        if (!ok) return;
      }

      const tree = buildLayoutPageTemplate(id);
      this.blocks = tree.map((node, i) => hydrateTemplateNode(node, i));
      this.selectedId = this.blocks[0]?.id ?? null;
      this.hideTplPreview();
      this.reindex();
      this.markDirty();
      const meta = LAYOUT_PAGE_TEMPLATE_META.find((m) => m.id === id);
      const label = meta
        ? String(this.boot.labels?.[meta.labelKey] || id)
        : id;
      toast(
        (this.boot.labels.layoutPageInserted || "Šablona „%name%“ vložena — uložte změny.").replace(
          "%name%",
          label,
        ),
        "success",
      );
    },

    tplPreviewStyle(): Record<string, string> {
      const h = this.tplHover;
      if (!h) return { display: "none" };
      return {
        left: `${h.left}px`,
        top: `${h.top}px`,
      };
    },

    showTplPreview(
      event: Event,
      key: string,
      title: string,
      desc: string,
      wire: WireNode[],
    ) {
      if (this._tplHoverTimer) clearTimeout(this._tplHoverTimer as number);
      const el = event.currentTarget as HTMLElement | null;
      if (!el) return;
      const rect = el.getBoundingClientRect();
      const maxW = 512;
      const gap = 10;
      const vw = window.innerWidth;
      const vh = window.innerHeight;
      let left = rect.right + gap;
      if (left + Math.min(maxW, 320) > vw - 8) {
        left = Math.max(8, rect.left - Math.min(maxW, 320) - gap);
      }
      let top = rect.top;
      const estH = Math.min(420, vh - 24);
      if (top + estH > vh - 8) top = Math.max(8, vh - estH - 8);
      this.tplHover = { key, title, desc, wire: stampWireKeys(wire), left, top };
    },

    scheduleHideTplPreview() {
      if (this._tplHoverTimer) clearTimeout(this._tplHoverTimer as number);
      this._tplHoverTimer = setTimeout(() => {
        this.tplHover = null;
      }, 80);
    },

    keepTplPreview() {
      if (this._tplHoverTimer) clearTimeout(this._tplHoverTimer as number);
    },

    hideTplPreview() {
      if (this._tplHoverTimer) clearTimeout(this._tplHoverTimer as number);
      this.tplHover = null;
    },

    previewSection(event: Event, sec: { kind: string; label: string; desc: string }) {
      this.showTplPreview(event, `sec:${sec.kind}`, sec.label, sec.desc || "", sectionToWire(sec.kind));
    },

    previewLayoutPage(event: Event, tpl: { id: LayoutPageTemplateId; label: string; desc: string }) {
      this.showTplPreview(
        event,
        `page:${tpl.id}`,
        tpl.label,
        tpl.desc || "",
        layoutPageWire(tpl.id),
      );
    },

    previewOnePage(event: Event) {
      this.showTplPreview(
        event,
        "onepage",
        this.boot.labels.onePageInserted || "One-page",
        this.boot.labels.replaceOnePage || "",
        [
          ...sectionToWire("hero"),
          ...sectionToWire("trust"),
          ...sectionToWire("services"),
          ...sectionToWire("cta"),
          ...sectionToWire("footer"),
        ],
      );
    },

    wireClass(t: string): string {
      const safe = String(t || "block").replace(/[^a-z0-9-]/gi, "");
      return "cms-tpl-wire__n cms-tpl-wire__n--" + safe;
    },

    tplHoverTitle(): string {
      return this.tplHover?.title || "";
    },

    tplHoverDesc(): string {
      return this.tplHover?.desc || "";
    },

    tplHoverWire(): WireNode[] {
      return this.tplHover?.wire || [];
    },

    wireHasKids(node: WireNode | null | undefined): boolean {
      return Boolean(node?.kids && node.kids.length > 0);
    },

    wireKids(node: WireNode | null | undefined): WireNode[] {
      return node?.kids || [];
    },

    /**
     * Clear content but keep the block (branded storefront slots).
     * Hard-removing would shift siblings and collapse the template layout.
     */
    clearBlockContent(block: BuilderBlock) {
      const p: BlockPayload = { ...block.payload };
      switch (block.type) {
        case "heading":
          p.text = "";
          break;
        case "text":
          p.body = "";
          break;
        case "html":
          p.html = "";
          break;
        case "cta":
          p.label = "";
          break;
        case "quote":
          p.quote = "";
          p.author = "";
          p.role = "";
          break;
        case "image":
          p.url = "";
          p.media_id = "";
          p.caption = "";
          break;
        case "video":
          p.url = "";
          p.caption = "";
          break;
        case "icon":
          // Keep glyph; mark empty so chrome can show a placeholder slot.
          p.slot_empty = 1;
          break;
        case "form_field":
          p.label = "";
          p.placeholder = "";
          break;
        default:
          break;
      }
      if (block.type !== "icon") {
        delete p.slot_empty;
      }
      block.payload = p;
    },

    removeSelected() {
      if (!this.selectedId) return;
      const list = this.listOf(this.selectedId);
      const idx = list.findIndex((b) => b.id === this.selectedId);
      if (idx < 0) return;
      const block = list[idx];

      // Branded fixed layouts: clear in place so slots don’t shift.
      // Soft / freeform pages (e.g. galerie): hard-delete. chrome_extra always hard-deletes.
      const isExtra = Number(block.payload.chrome_extra ?? 0) === 1;
      if (this.fixedLayout && this.boot.storefrontUrl && !isExtra) {
        this.clearBlockContent(block);
        this.markDirty();
        this.queuePreviewState();
        return;
      }

      list.splice(idx, 1);
      this.selectedId = list[idx]?.id ?? list[idx - 1]?.id ?? null;
      this.reindex();
      this.markDirty();
    },

    /** Deep-clone nested layout children with fresh tmp ids (avoids edit collisions). */
    cloneBlockTree(source: BuilderBlock): BuilderBlock {
      const freshId = () => "tmp_" + Math.random().toString(36).slice(2, 10);
      const clonePayload = (payload: BlockPayload): BlockPayload => {
        const next: BlockPayload = { ...payload };
        for (const key of ["children", "item_blocks"] as const) {
          if (!Array.isArray(payload[key])) continue;
          next[key] = (payload[key] as BuilderBlock[]).map((kid) => {
            const child = kid as BuilderBlock;
            return {
              id: freshId(),
              type: child.type,
              sort_order: child.sort_order ?? 0,
              payload: clonePayload(child.payload || {}),
              _new: true,
            };
          });
        }
        return next;
      };
      return {
        id: freshId(),
        type: source.type,
        sort_order: source.sort_order,
        payload: clonePayload(source.payload || {}),
        _new: true,
      };
    },

    duplicateSelected() {
      if (!this.selectedId) return;
      const list = this.listOf(this.selectedId);
      const idx = list.findIndex((b) => b.id === this.selectedId);
      if (idx < 0) return;

      const copy = this.cloneBlockTree(list[idx]);
      copy.sort_order = idx + 2;
      list.splice(idx + 1, 0, copy);
      this.selectedId = copy.id;
      this.reindex();
      this.markDirty();
    },

    /** Reverse layout children — swaps image/text sides without a hard template. */
    swapLayoutChildren() {
      if (!this.selectedId) return;
      const block = this.findBlock(this.selectedId);
      if (!block || block.type !== "layout") return;
      const kids = block.payload.children;
      if (!Array.isArray(kids) || kids.length < 2) return;
      block.payload = {
        ...block.payload,
        children: [...kids].reverse(),
      };
      this.markDirty();
    },

    layoutVariantLabel(block: BuilderBlock): string {
      const variant = resolveLayoutVariantId(block.payload?.variant);
      const key = `layoutVariant_${variant.replace(/-/g, "_")}`;
      return String(this.boot?.labels?.[key] || variant);
    },

    onLayoutVariantChange(event: Event) {
      const t = event.target as HTMLSelectElement;
      this.applyLayoutVariant(t.value);
    },

    /**
     * Apply a container purpose preset (flex/grid props).
     * Seeds default children only when the container is still empty.
     */
    applyLayoutVariant(raw: string) {
      if (!this.selected || this.selected.type !== "layout") return;
      const variant = resolveLayoutVariantId(raw);
      const preset = LAYOUT_PRESETS[variant];
      const existing = Array.isArray(this.selected.payload.children)
        ? (this.selected.payload.children as BuilderBlock[])
        : [];
      let children = existing;
      if (preset.seed?.length && existing.length === 0) {
        children = preset.seed.map((seed, i) => {
          const payload = {
            ...defaultPayload(seed.type),
            ...(seed.item || {}),
          };
          return {
            id: "tmp_" + Math.random().toString(36).slice(2, 10),
            type: seed.type,
            sort_order: i + 1,
            payload,
            _new: true,
          } as BuilderBlock;
        });
      }
      this.selected.payload = {
        ...this.selected.payload,
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
        children,
      };
      this.markDirty();
    },

    /** Inserts named section (divider + typical content) at canvas end. */
    insertSection(kind: string) {
      const defs = this.sectionDefs(kind);
      if (defs.length === 0) return;
      const start = this.blocks.length;
      for (const def of defs) {
        this.blocks.push({
          id: "tmp_" + Math.random().toString(36).slice(2, 10),
          type: def.type,
          sort_order: this.blocks.length + 1,
          payload: { spacing: "normal", align: "start", ...def.payload },
          _new: true,
        });
      }
      this.selectedId = this.blocks[start]?.id ?? null;
      this.hideTplPreview();
      this.reindex();
      this.markDirty();
    },

    insertPartial(partial: BuilderPartial) {
      const defs = Array.isArray(partial?.blocks) ? partial.blocks : [];
      if (defs.length === 0) return;
      const start = this.blocks.length;
      for (const def of defs) {
        const type = String(def.type || "");
        if (!type) continue;
        this.blocks.push(this.cloneBlockTree({
          id: "", type, sort_order: this.blocks.length + 1,
          payload: normalizePayload(type, def.payload as Record<string, unknown>),
        }));
      }
      this.selectedId = this.blocks[start]?.id ?? null;
      this.hideTplPreview();
      this.reindex();
      this.markDirty();
      if (defs.length === 1 && this.selected?.type === "dynamic") this.editDynamicTemplate();
      toast(this.boot.labels.partialInserted || "Šablona vložena", "success");
    },

    async savePartial(onlyBlocks: BuilderBlock[] | null = null) {
      if ((this.templateOwner || this.savedTemplateSession) && !onlyBlocks) {
        await this.saveDynamicTemplate();
        return;
      }
      if (this.partialSaving) return;
      let name = this.partialName.trim();
      if (!name && this.boot.sandbox) {
        const prompted = await askPrompt({
          title: this.boot.labels.sandboxSave || "Uložit jako šablonu",
          body:
            this.boot.labels.partialNameRequired ||
            "Zadejte název šablony (max. 120 znaků).",
          inputLabel: this.boot.labels.partialNameRequired || "Název šablony",
          inputPlaceholder:
            this.boot.labels.partialNameRequired || "Název šablony",
          confirmLabel: this.boot.labels.sandboxSave || "Uložit",
        });
        name = (prompted || "").trim().slice(0, 120);
        if (name) {
          this.partialName = name;
        }
      }
      if (!name) {
        if (onlyBlocks) this.openLibraryTab("dynamic");
        else { this.createOpen = true; this.createTab = "templates"; }
        toast(this.boot.labels.partialNameRequired || "Zadejte název", "error");
        return;
      }
      if (this.blocks.length === 0) {
        toast(this.boot.labels.partialEmpty || "Canvas je prázdný", "error");
        return;
      }
      if (!this.boot.partialsUrl) return;
      let saved = false;
      this.partialSaving = true;
      this.status = this.boot.labels.saving || "Ukládám…";
      try {
        const session = onlyBlocks ? this.savedTemplateSession : null;
        const updateUrl = session && this.boot.partialUpdateUrl
          ? this.boot.partialUpdateUrl.replace("__ID__", encodeURIComponent(session.partial.id)) : null;
        if (session && !updateUrl) throw new Error("Missing template update URL");
        const res = await fetch(updateUrl || this.boot.partialsUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfToken(this.boot),
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
          body: JSON.stringify({
            name,
            ...(session ? { description: session.partial.description } : {}),
            blocks: (onlyBlocks || this.blocks).map((b) => ({
              type: b.type,
              payload: b.payload,
            })),
          }),
        });
        const json = (await res.json()) as {
          data?: BuilderPartial;
          error?: { message?: string };
          message?: string;
        };
        if (!res.ok) {
          throw new Error(
            json?.error?.message || json?.message || this.boot.labels.saveFailed || "",
          );
        }
        if (json.data) {
          this.partials = [
            json.data,
            ...this.partials.filter((p) => p.id !== json.data!.id),
          ];
        }
        saved = true;
        this.partialName = "";
        if (this.boot.sandbox && !onlyBlocks) {
          this.undoStack = [];
          this.redoStack = [];
          this.historyState = this.captureHistory();
          this.dirty = false;
        }
        this.status = this.boot.labels.partialSaved || "Šablona uložena";
        toast(this.boot.labels.partialSaved || "Šablona uložena", "success");
        this.createOpen = true;
        this.createTab = "templates";
      } catch (e) {
        this.status = e instanceof Error ? e.message : "Chyba uložení";
        toast(e instanceof Error ? e.message : "Chyba uložení", "error");
      } finally {
        this.partialSaving = false;
      }
      if (saved && this.savedTemplateSession && !this.templateOwner) this.closeSavedDynamicTemplate();
    },

    async deletePartial(partial: BuilderPartial) {
      if (!partial?.id) return;
      const ok = await askConfirm({
        title: this.boot.labels.partialDeleteConfirm || "Smazat šablonu?",
        body: partial.name
          ? `${this.boot.labels.partialDeleteConfirm || "Smazat tuto šablonu?"} (${partial.name})`
          : this.boot.labels.partialDeleteConfirm || "Smazat tuto šablonu?",
        danger: true,
      });
      if (!ok) return;
      const url = (this.boot.partialDeleteUrl || "").replace(
        "__ID__",
        encodeURIComponent(partial.id),
      );
      if (!url) return;
      try {
        const res = await fetch(url, {
          method: "POST",
          headers: {
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfToken(this.boot),
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
        });
        const json = (await res.json()) as {
          error?: { message?: string };
          message?: string;
        };
        if (!res.ok) {
          throw new Error(
            json?.error?.message || json?.message || "Delete failed",
          );
        }
        this.partials = this.partials.filter((p) => p.id !== partial.id);
        toast(this.boot.labels.partialDeleted || "Šablona smazána", "success");
      } catch (e) {
        toast(e instanceof Error ? e.message : "Chyba mazání", "error");
      }
    },

    sectionDefs(kind: string): Array<{ type: string; payload: BlockPayload }> {
      switch (kind) {
        case "hero":
          return [
            { type: "divider", payload: { style: "hero" } },
            {
              type: "text",
              payload: { body: "Realitní kancelář · Praha a Brno" },
            },
            {
              type: "heading",
              payload: { text: "Hlavní nadpis sekce", level: 1 },
            },
            {
              type: "text",
              payload: { body: "Krátký úvodní odstavec pod nadpisem." },
            },
            {
              type: "image",
              payload: {
                media_id: "",
                url: "https://images.unsplash.com/photo-1600607687939-ce8a6c25118c?w=1920&q=80",
                alt: "Hero",
                caption: "",
              },
            },
          ];
        case "trust":
          return [
            { type: "divider", payload: { style: "trust" } },
            { type: "heading", payload: { text: "Ověřené nabídky", level: 3 } },
            {
              type: "text",
              payload: {
                body: "Každá nemovitost prochází kontrolou podkladů.",
              },
            },
            { type: "heading", payload: { text: "Férová cena", level: 3 } },
            {
              type: "text",
              payload: {
                body: "Transparentní ocenění bez skrytých příplatků.",
              },
            },
            { type: "heading", payload: { text: "Odborná podpora", level: 3 } },
            {
              type: "text",
              payload: { body: "Lokální makléři, kteří znají lokalitu." },
            },
            {
              type: "heading",
              payload: { text: "Bezpečné jednání", level: 3 },
            },
            {
              type: "text",
              payload: { body: "Jasná smlouva a ověřené dokumenty." },
            },
          ];
        case "listings":
          return [
            { type: "divider", payload: { style: "listings" } },
            {
              type: "heading",
              payload: { text: "Aktuální nabídky", level: 2 },
            },
            {
              type: "text",
              payload: { body: "Vybrané nemovitosti z katalogu." },
            },
          ];
        case "why":
          return [
            { type: "divider", payload: { style: "why" } },
            { type: "heading", payload: { text: "Proč s námi", level: 2 } },
            {
              type: "text",
              payload: { body: "Stručný důvod, proč si vybrat právě nás." },
            },
            {
              type: "cta",
              payload: {
                label: "Zjistit více",
                url: "/stranka/o-nas",
                style: "primary",
              },
            },
            { type: "heading", payload: { text: "Bod jedna", level: 3 } },
            { type: "text", payload: { body: "Popis prvního bodu." } },
            { type: "heading", payload: { text: "Bod dva", level: 3 } },
            { type: "text", payload: { body: "Popis druhého bodu." } },
          ];
        case "services":
          return [
            { type: "divider", payload: { style: "services" } },
            { type: "heading", payload: { text: "Služby", level: 2 } },
            { type: "text", payload: { body: "Přehled služeb kanceláře." } },
            { type: "heading", payload: { text: "Prodej", level: 3 } },
            {
              type: "text",
              payload: {
                body: "Připravíme prezentaci a dovedeme obchod do konce.",
              },
            },
            {
              type: "cta",
              payload: {
                label: "Prodej",
                url: "/nabidky?offer=sale",
                style: "ghost",
              },
            },
            { type: "heading", payload: { text: "Pronájem", level: 3 } },
            {
              type: "text",
              payload: { body: "Najdeme nájemce nebo byt k bydlení." },
            },
            {
              type: "cta",
              payload: {
                label: "Pronájem",
                url: "/nabidky?offer=rent",
                style: "ghost",
              },
            },
          ];
        case "how":
          return [
            { type: "divider", payload: { style: "how" } },
            { type: "heading", payload: { text: "Jak to funguje", level: 2 } },
            { type: "text", payload: { body: "Stručný popis procesu." } },
            { type: "heading", payload: { text: "Krok jedna", level: 3 } },
            { type: "text", payload: { body: "Popis prvního kroku." } },
            { type: "heading", payload: { text: "Krok dva", level: 3 } },
            { type: "text", payload: { body: "Popis druhého kroku." } },
          ];
        case "types":
          return [
            { type: "divider", payload: { style: "types" } },
            {
              type: "heading",
              payload: { text: "Typy nemovitostí", level: 2 },
            },
            {
              type: "text",
              payload: { body: "Vyberte kategorii a zobrazte nabídky." },
            },
            { type: "heading", payload: { text: "Byty", level: 3 } },
            { type: "text", payload: { body: "Od 3,5 mil." } },
            {
              type: "cta",
              payload: {
                label: "Byty",
                url: "/nabidky?type=apartment",
                style: "ghost",
              },
            },
            { type: "heading", payload: { text: "Domy", level: 3 } },
            { type: "text", payload: { body: "Od 6 mil." } },
            {
              type: "cta",
              payload: {
                label: "Domy",
                url: "/nabidky?type=house",
                style: "ghost",
              },
            },
          ];
        case "testimonials":
          return [
            { type: "divider", payload: { style: "testimonials" } },
            {
              type: "heading",
              payload: { text: "Co říkají klienti", level: 2 },
            },
            { type: "text", payload: { body: "Krátké zkušenosti klientů." } },
            {
              type: "quote",
              payload: {
                quote:
                  "Makléř nám našel byt během týdne a všechno vysvětlil srozumitelně.",
                author: "Jana N.",
                role: "Klientka · Pronájem",
              },
            },
          ];
        case "insights":
          return [
            { type: "divider", payload: { style: "insights" } },
            { type: "heading", payload: { text: "Tipy a novinky", level: 2 } },
            { type: "text", payload: { body: "Praktické články z trhu." } },
            {
              type: "heading",
              payload: { text: "Jak číst energetický štítek", level: 3 },
            },
            { type: "text", payload: { body: "Průvodce" } },
            {
              type: "cta",
              payload: { label: "Číst", url: "/stranka/o-nas", style: "ghost" },
            },
          ];
        case "faq":
          return [
            { type: "divider", payload: { style: "faq" } },
            { type: "heading", payload: { text: "Časté otázky", level: 2 } },
            {
              type: "heading",
              payload: { text: "Jak rychle se ozvete?", level: 3 },
            },
            {
              type: "text",
              payload: { body: "Obvykle do jednoho pracovního dne." },
            },
            {
              type: "heading",
              payload: { text: "Je prohlídka závazná?", level: 3 },
            },
            {
              type: "text",
              payload: { body: "Ne — prohlídka vás k ničemu nezavazuje." },
            },
          ];
        case "about":
          return [
            { type: "divider", payload: { style: "about" } },
            { type: "heading", payload: { text: "O nás", level: 2 } },
            {
              type: "text",
              payload: { body: "Krátký text o kanceláři a způsobu práce." },
            },
            {
              type: "image",
              payload: {
                media_id: "",
                url: "https://images.unsplash.com/photo-1560518883-ce09059eeffa?w=1600&q=80",
                alt: "Tým",
                caption: "",
              },
            },
          ];
        case "cta":
          return [
            { type: "divider", payload: { style: "cta" } },
            {
              type: "heading",
              payload: { text: "Připraveni najít nové bydlení?", level: 2 },
            },
            {
              type: "text",
              payload: {
                body: "Projděte katalog nebo nám napište, co hledáte.",
              },
            },
            {
              type: "cta",
              payload: {
                label: "Prohlédnout nabídky",
                url: "/nabidky",
                style: "primary",
              },
            },
          ];
        case "contact":
          return [
            { type: "divider", payload: { style: "contact" } },
            { type: "heading", payload: { text: "Zavolejte", level: 3 } },
            { type: "text", payload: { body: "+420 777 000 000" } },
            { type: "heading", payload: { text: "Napište", level: 3 } },
            { type: "text", payload: { body: "info@realestates.cz" } },
            { type: "heading", payload: { text: "Navštivte nás", level: 3 } },
            { type: "text", payload: { body: "Praha · Brno" } },
          ];
        case "footer":
          return [
            { type: "divider", payload: { style: "footer" } },
            {
              type: "text",
              payload: {
                body: "RealEstates je realitní kancelář s nabídkami v Praze, Brně a okolí.",
              },
            },
            { type: "heading", payload: { text: "Newsletter", level: 2 } },
            {
              type: "text",
              payload: { body: "Nové tipy a vybrané nabídky jednou týdně." },
            },
            {
              type: "text",
              payload: { body: "Transparentní nabídky. Lidský přístup." },
            },
          ];
        case "quote":
          return [
            {
              type: "quote",
              payload: {
                quote:
                  "Makléř nám našel byt během týdne a všechno vysvětlil srozumitelně.",
                author: "Jana N.",
                role: "Klientka · Pronájem",
              },
            },
          ];
        default:
          return [];
      }
    },

    galleryThumbs(block: BuilderBlock): MediaItem[] {
      const ids = this.galleryIds(block);
      if (ids.length === 0) return [];
      const byId = new Map((this.boot.media || []).map((m) => [m.id, m]));
      return ids
        .map((id) => byId.get(id))
        .filter((m): m is MediaItem => Boolean(m));
    },

    galleryVariantLabel(block: BuilderBlock): string {
      const variant = String(block.payload.variant || "grid").trim() || "grid";
      const key = `galleryVariant_${variant.replace(/-/g, "_")}`;
      return (this.boot.labels || {})[key] || variant;
    },

    galleryIds(block: BuilderBlock | null = null): string[] {
      const target = block || this.selected;
      if (!target) return [];
      return String(target.payload.media_ids || "")
        .split(/[\s,;]+/)
        .map((s) => s.trim())
        .filter(Boolean);
    },

    isInGallery(mediaId: string): boolean {
      return this.galleryIds().includes(mediaId);
    },

    toggleGalleryMedia(media: MediaItem) {
      if (!this.selected || this.selected.type !== "gallery") return;
      const current = this.galleryIds();
      const idx = current.indexOf(media.id);
      if (idx >= 0) current.splice(idx, 1);
      else current.push(media.id);
      this.setPayload("media_ids", current.join(","));
    },

    removeGalleryMedia(mediaId: string) {
      if (!this.selected || this.selected.type !== "gallery") return;
      const next = this.galleryIds().filter((id) => id !== mediaId);
      this.setPayload("media_ids", next.join(","));
    },

    moveGalleryMedia(mediaId: string, dir: number) {
      if (!this.selected || this.selected.type !== "gallery") return;
      const current = this.galleryIds();
      const idx = current.indexOf(mediaId);
      const next = idx + dir;
      if (idx < 0 || next < 0 || next >= current.length) return;
      const tmp = current[idx];
      current[idx] = current[next];
      current[next] = tmp;
      this.setPayload("media_ids", current.join(","));
    },

    syncInspectorMediaBox(root: HTMLElement) {
      const block = this.selected;
      if (!block || !["image", "avatar"].includes(block.type)) return;
      const value = String(block.payload.url || "");
      const selectedId = block.type === "image" ? String(block.payload.media_id || "")
        : (this.boot.media || []).find((media) => media.url === value)?.id || "";
      const source = this.boot.dynamicSources?.find((entry) => entry.id === this.selectedDynamicOwner?.payload.source);
      const resolved = bindTemplateVariables(value, source?.items?.[0] || {}, this.boot.varValues || {});
      const preview = resolved.includes("{{") ? "" : resolved || (selectedId ? this.imageSrc(block) : "");
      const library = (this.boot.media || []).map((media) => ({
        value: media.id, label: media.title, thumb: media.url, mime: media.mime,
        width: media.width, height: media.height, created: media.created,
        ext: media.mime.split("/")[1]?.replace("jpeg", "jpg") || "",
      }));
      const state = { context: block.id, value, preview, selectedId, library };
      this.$nextTick(() => {
        if (root.isConnected && this.selectedId === state.context) {
          root.querySelector(".media-box")?.dispatchEvent(new CustomEvent("media-box-sync", { detail: state }));
        }
      });
    },

    onInspectorMediaChange(event: CustomEvent<{ value: string; kind: string; context: string; selectedId: string; file?: File }>) {
      const detail = event.detail;
      const block = detail && this.findBlock(detail.context);
      if (!block || !["image", "avatar"].includes(block.type)) return;
      if (detail.kind === "file" && detail.file) {
        void this.uploadMediaFiles([detail.file], block.id);
        return;
      }
      const media = (this.boot.media || []).find((item) => item.id === detail.selectedId);
      this.applyInspectorMediaSelection(block.id, media || null, detail.value);
    },

    applyInspectorMediaSelection(blockId: string, media: MediaItem | null, value: string) {
      const block = this.findBlock(blockId);
      if (!block || !["image", "avatar"].includes(block.type)) return;
      block.payload = {
        ...block.payload,
        url: media?.url || value,
        ...(block.type === "image" ? {
          media_id: media?.id || "", media_url: "",
          ...(!block.payload.alt && media ? { alt: media.alt || media.title } : {}),
        } : {}),
      };
      this.markDirty();
      if (this.boot.storefrontUrl) this.queuePreviewState(true);
    },

    pickImageMedia(media: MediaItem) {
      if (!this.selected || this.selected.type !== "image") return;
      this.setPayload("media_id", media.id);
      this.setPayload("url", media.url);
      if (!this.selected.payload.alt) {
        this.setPayload("alt", media.alt || media.title);
      }
    },

    canUploadMedia(): boolean {
      return Boolean(this.boot.mediaUploadUrl) && !this.mediaUploading;
    },

    mediaUploadClass(): string {
      const parts: string[] = [];
      if (this.mediaDragOver) parts.push("is-drag");
      if (this.mediaUploading) parts.push("is-busy");
      return parts.join(" ");
    },

    pickMediaFiles() {
      if (!this.canUploadMedia()) return;
      const input = document.createElement("input");
      input.type = "file";
      input.accept = "image/jpeg,image/png,image/webp,image/avif,image/gif";
      input.multiple = true;
      input.addEventListener("change", () => {
        void this.uploadMediaFiles(input.files);
      });
      input.click();
    },

    onMediaDragOver(event: DragEvent) {
      if (!this.boot.mediaUploadUrl || this.mediaUploading) return;
      event.preventDefault();
      if (event.dataTransfer) {
        event.dataTransfer.dropEffect = "copy";
      }
      this.mediaDragOver = true;
    },

    onMediaDragLeave(event: DragEvent) {
      event.preventDefault();
      this.mediaDragOver = false;
    },

    onMediaDrop(event: DragEvent) {
      if (!this.boot.mediaUploadUrl || this.mediaUploading) return;
      event.preventDefault();
      this.mediaDragOver = false;
      void this.uploadMediaFiles(event.dataTransfer?.files ?? null);
    },

    async uploadMediaFiles(files: FileList | File[] | null, targetBlockId: string | null = null) {
      const uploadUrl = this.boot.mediaUploadUrl || "";
      if (!uploadUrl || this.mediaUploading || !files) return;

      const list = Array.from(files).filter((file) =>
        file.type.startsWith("image/"),
      );
      if (list.length === 0) {
        this.mediaUploadError =
          this.boot.labels.mediaUploadImageOnly ||
          "Nahrajte prosím obrázek (JPG, PNG, WebP, AVIF…).";
        return;
      }

      const originalUrl = targetBlockId ? this.findBlock(targetBlockId)?.payload.url : undefined;
      this.mediaUploading = true;
      this.mediaUploadError = "";
      let lastItem: MediaItem | null = null;
      let failed = 0;

      for (const file of list) {
        const body = new FormData();
        body.set("_csrf", csrfToken(this.boot));
        body.set("file", file);
        try {
          const res = await fetch(uploadUrl, {
            method: "POST",
            headers: {
              "X-CSRF-TOKEN": csrfToken(this.boot),
              "X-Requested-With": "XMLHttpRequest",
              Accept: "application/json",
            },
            body,
            credentials: "same-origin",
          });
          const payload = (await res.json().catch(() => null)) as MediaUploadResponse | null;
          const data: MediaUploadPayload | null = payload?.data
            ? payload.data
            : payload?.id
              ? payload
              : null;
          if (!res.ok || !data?.id) {
            failed += 1;
            this.mediaUploadError =
              payload?.error?.message ||
              this.boot.labels.mediaUploadFailed ||
              "Nahrání se nezdařilo.";
            continue;
          }
          const item: MediaItem = {
            id: String(data.id),
            title: String(data.title || file.name),
            url: String(data.url || `/media/${data.id}/file`),
            mime: String(data.mime || "image/avif"),
            alt: String(data.alt || ""),
            width: data.width, height: data.height, created: Date.now() / 1000,
          };
          this.boot.media = [
            item,
            ...(this.boot.media || []).filter((m) => m.id !== item.id),
          ];
          lastItem = item;
        } catch {
          failed += 1;
          this.mediaUploadError =
            this.boot.labels.mediaUploadFailed || "Nahrání se nezdařilo.";
        }
      }

      this.mediaUploading = false;
      if (lastItem) {
        if (targetBlockId) {
          if (this.findBlock(targetBlockId)?.payload.url === originalUrl) {
            this.applyInspectorMediaSelection(targetBlockId, lastItem, lastItem.url);
          }
        } else this.applyUploadedMedia(lastItem);
        if (failed > 0) {
          this.status =
            this.boot.labels.mediaUploadPartial ||
            "Část souborů se nepodařilo nahrát.";
        } else {
          this.status =
            this.boot.labels.mediaUploaded || "Fotka nahrána (AVIF).";
        }
      }
    },

    applyUploadedMedia(media: MediaItem) {
      if (this.selected?.type === "image") {
        this.pickImageMedia(media);
        return;
      }
      if (
        this.selected?.type === "gallery" ||
        (this.modalTab === "media" && this.mediaMode === "gallery")
      ) {
        if (!this.isInGallery(media.id)) {
          this.toggleGalleryMedia(media);
        }
        return;
      }
      this.applyMedia(media);
    },

    clearImageMedia() {
      if (!this.selected || this.selected.type !== "image") return;
      this.setPayload("media_id", "");
      this.setPayload("url", "");
    },

    blockSpacingClass(block: BuilderBlock): string {
      const spacing = String(block.payload.spacing || "normal");
      return "is-spacing-" + spacing;
    },

    blockAlignClass(block: BuilderBlock): string {
      const align = String(block.payload.align || "start");
      return "is-align-" + align;
    },

    spacerClass(block: BuilderBlock): string {
      return "cms-builder-spacer--" + String(block.payload.size || "md");
    },

    iconPreviewClass(block: BuilderBlock): string {
      const style = String(block.payload.icon_style || "outlined");
      const family =
        style === "rounded"
          ? "material-symbols-rounded"
          : style === "sharp"
            ? "material-symbols-sharp"
            : "material-symbols-outlined";
      return "cms-builder-icon " + family;
    },
    iconSizeValue(block: BuilderBlock): string {
      const legacy: Record<string, string> = { sm: "16", md: "24", lg: "48", xl: "56" };
      const allowed = ["8", "16", "24", "32", "40", "48", "56", "64"];
      const raw = String(block.payload.size || "24");
      if (legacy[raw]) return legacy[raw];
      return allowed.includes(raw) ? raw : "24";
    },
    iconPreviewStyle(block: BuilderBlock): Record<string, string> {
      const size = this.iconSizeValue(block);
      const allowedW = ["100", "200", "300", "400", "500", "600", "700"];
      const weight = allowedW.includes(String(block.payload.weight || "400"))
        ? String(block.payload.weight || "400")
        : "400";
      const fill = String(block.payload.fill || "0") === "1" ? "1" : "0";
      const gradeRaw = String(block.payload.grade || "0");
      const grade = ["-25", "0", "200"].includes(gradeRaw) ? gradeRaw : "0";
      const opszRaw = String(block.payload.opsz || "");
      const opsz = opszRaw
        ? String(Math.min(48, Math.max(20, Number(opszRaw) || 24)))
        : String(Math.min(48, Math.max(20, Number(size))));
      return {
        fontSize: size + "px",
        // Match Material Symbols: font-weight must track wght axis.
        fontWeight: weight,
        fontVariationSettings:
          "'FILL' " + fill + ", 'wght' " + weight + ", 'GRAD' " + grade + ", 'opsz' " + opsz,
      };
    },

    iconGlyph(block: BuilderBlock): string {
      const raw = String(block.payload.name || "circle").trim();
      return /^[a-z0-9_]+$/i.test(raw) ? raw : "circle";
    },

    filteredIcons(): Array<{ name: string; glyph: string }> {
      const catalog = this.boot.icons || [];
      const q = this.iconQuery.trim().toLowerCase();
      if (!q) return catalog;
      return catalog.filter(
        (icon) =>
          icon.name.toLowerCase().includes(q) ||
          icon.glyph.toLowerCase().includes(q),
      );
    },

    pickIcon(name: string) {
      if (!this.selected || this.selected.type !== "icon") return;
      this.setPayload("name", name);
    },

    isIconSelected(name: string): boolean {
      return String(this.selected?.payload.name || "") === name;
    },

    videoEmbedUrl(block: BuilderBlock): string {
      const url = String(block.payload.url || "").trim();
      if (!url) return "";
      const yt = url.match(
        /(?:youtu\.be\/|youtube\.com\/(?:watch\?v=|embed\/))([A-Za-z0-9_-]{6,})/,
      );
      if (yt) return "https://www.youtube.com/embed/" + yt[1];
      const vm = url.match(/vimeo\.com\/(?:video\/)?(\d+)/);
      if (vm) return "https://player.vimeo.com/video/" + vm[1];
      return "";
    },

    setDevice(d: string) {
      this.device = d as "desktop" | "tablet" | "mobile";
      this.deviceMenu = false;
    },

    moveSelected(dir: number) {
      if (!this.selectedId) return;
      const list = this.listOf(this.selectedId);
      const idx = list.findIndex((b) => b.id === this.selectedId);
      const next = idx + dir;
      if (idx < 0 || next < 0 || next >= list.length) return;
      const tmp = list[idx];
      list[idx] = list[next];
      list[next] = tmp;
      this.reindex();
      this.markDirty();
    },

    reindex() {
      const walk = (list: BuilderBlock[]) => {
        list.forEach((b, i) => {
          b.sort_order = i + 1;
          const kids = b.payload.children;
          if (Array.isArray(kids)) walk(kids as BuilderBlock[]);
        });
      };
      walk(this.blocks);
    },

    setPayload(key: string, value: BlockPayload[string]) {
      const block = this.selected;
      if (!block) return;
      const next: BlockPayload = { ...block.payload, [key]: value };
      if (key === "flex_wrap") {
        next.wrap = value === "nowrap" || value === "" ? 0 : 1;
      }
      if (key === "wrap") {
        next.flex_wrap = Number(value) === 1 ? "wrap" : "nowrap";
      }
      if (key === "display" && value === "grid" && !next.grid_cols) {
        next.grid_cols = "2";
      }
      block.payload = next;
      this.markDirty();
      if (this.boot.storefrontUrl) this.queuePreviewState(true);
    },

    onTextInput(key: string, event: Event) {
      const t = event.target as
        HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement;
      this.setPayload(key, t.value);
    },

    focusField(key: string) {
      this.insertTarget = { key };
    },

    blocksOfType(type: string): BuilderBlock[] {
      return this.selected?.type === type ? [this.selected] : [];
    },

    payloadStr(key: string): string {
      return String(this.selected?.payload[key] ?? "");
    },

    filmSpeedValue(): string {
      const raw = Number(this.selected?.payload.film_speed);
      if (raw === 20 || raw === 40 || raw === 70 || raw === 110) return String(raw);
      return "40";
    },

    /** CSP-safe: selected.payload[key] with numeric fallback (no ?? in templates). */
    payloadNum(key: string, fallback: number): number {
      const raw = this.selected?.payload[key];
      if (raw === null || raw === undefined || raw === "") return fallback;
      const n = Number(raw);
      return Number.isFinite(n) ? n : fallback;
    },

    /** CSP-safe empty-or-number for optional gap fields. */
    payloadOptionalNum(key: string): string | number {
      const raw = this.selected?.payload[key];
      if (raw === null || raw === undefined || raw === "") return "";
      const n = Number(raw);
      return Number.isFinite(n) ? n : "";
    },

    onOptionalNumberPayload(
      key: string,
      event: Event,
      min: number,
      max: number,
    ) {
      const t = event.target as HTMLInputElement;
      if (t.value === "") {
        this.setPayload(key, "");
        return;
      }
      this.setPayload(
        key,
        Math.max(min, Math.min(max, Number(t.value) || 0)),
      );
    },

    layoutFlexWrapValue(): string {
      const p = this.selected?.payload;
      if (!p) return "nowrap";
      const explicit = String(p.flex_wrap || "").trim();
      if (explicit === "wrap" || explicit === "nowrap" || explicit === "wrap-reverse") {
        return explicit;
      }
      return Number(p.wrap) === 1 ? "wrap" : "nowrap";
    },

    gridFlowDirection(): string {
      return String(this.selected?.payload.grid_auto_flow || "row").includes("column") ? "column" : "row";
    },

    gridFillGapsValue(): string {
      return String(this.selected?.payload.grid_auto_flow || "row").includes("dense") ? "1" : "0";
    },

    onGridFlowDirection(event: Event) {
      const direction = (event.target as HTMLSelectElement).value;
      if (direction !== "row" && direction !== "column") return;
      if (!this.selected) return;
      const payload = { ...this.selected.payload };
      payload.grid_auto_flow = direction + (this.gridFillGapsValue() === "1" ? " dense" : "");
      if (direction === "column" && ["", "none"].includes(String(payload.grid_rows || "").trim())) {
        const track = String(payload.grid_auto_rows || "auto");
        payload.grid_rows = `repeat(2, ${track})`;
      }
      this.selected.payload = payload;
      this.markDirty();
      if (this.boot.storefrontUrl) this.queuePreviewState(true);
    },

    gridFlowRowCount(): string {
      const rows = String(this.selected?.payload.grid_rows || "").trim();
      return rows.match(/^repeat\((\d+),\s*.+\)$/)?.[1] || "";
    },

    onGridFlowRowCount(event: Event) {
      const count = Number((event.target as HTMLInputElement).value);
      if (!Number.isInteger(count) || count < 1 || count > 12) return;
      const track = String(this.selected?.payload.grid_auto_rows || "auto");
      this.setPayload("grid_rows", `repeat(${count}, ${track})`);
    },

    onGridFillGaps(event: Event) {
      const dense = (event.target as HTMLSelectElement).value === "1";
      this.setPayload("grid_auto_flow", this.gridFlowDirection() + (dense ? " dense" : ""));
    },

    customItemValues: {} as Record<string, boolean>,

    itemPresetValue(key: string): string {
      const block = this.selected;
      if (!block) return "";
      if (this.customItemValues[`${block.id}:${key}`]) return "custom";
      const raw = String(block.payload[key] ?? "").trim();
      const allowed = key === "flex_basis"
        ? ["", "auto", "25%", "50%", "75%", "100%"]
        : ["", ...Array.from({ length: 12 }, (_, i) => `span ${i + 1}`)];
      return allowed.includes(raw) ? raw : "custom";
    },

    isCustomItemValue(key: string): boolean { return this.itemPresetValue(key) === "custom"; },

    onItemPresetChange(key: string, event: Event) {
      if (!this.selected || !["flex_basis", "grid_column", "grid_row"].includes(key)) return;
      const id = `${this.selected.id}:${key}`;
      const value = (event.target as HTMLSelectElement).value;
      if (value === "custom") {
        this.customItemValues[id] = true;
        return;
      }
      delete this.customItemValues[id];
      this.setPayload(key, value);
    },

    customRowSizingIds: {} as Record<string, boolean>,

    layoutRowSizing(): string {
      const block = this.selected;
      if (!block) return "fit";
      if (this.customRowSizingIds[block.id]) return "custom";
      const rows = String(block.payload.grid_rows || "").trim();
      const auto = String(block.payload.grid_auto_rows || "").trim();
      if (rows && rows !== "none" && rows !== `repeat(${this.gridFlowRowCount()}, ${auto || "auto"})`) return "custom";
      if (!auto || auto === "auto") return "fit";
      if (auto === "1fr") return "equal";
      if (/^[1-9]\d*px$/.test(auto)) return "fixed";
      return "custom";
    },

    isCustomRowSizing(): boolean { return this.layoutRowSizing() === "custom"; },
    isFixedRowSizing(): boolean { return this.layoutRowSizing() === "fixed"; },

    layoutRowHeight(): number {
      const raw = String(this.selected?.payload.grid_auto_rows || "").trim();
      return /^[1-9]\d*px$/.test(raw) ? parseInt(raw, 10) : 120;
    },

    onRowSizingChange(event: Event) {
      const block = this.selected;
      if (!block || block.type !== "layout") return;
      const mode = (event.target as HTMLSelectElement).value;
      if (!["fit", "equal", "fixed", "custom"].includes(mode)) return;
      if (mode === "custom") {
        this.customRowSizingIds[block.id] = true;
        return;
      }
      delete this.customRowSizingIds[block.id];
      const auto = mode === "equal" ? "1fr" : mode === "fixed" ? `${this.layoutRowHeight()}px` : "auto";
      const count = this.gridFlowDirection() === "column" ? this.gridFlowRowCount() : "";
      block.payload = { ...block.payload, grid_rows: count ? `repeat(${count}, ${auto})` : "", grid_auto_rows: auto };
      this.markDirty();
      if (this.boot.storefrontUrl) this.queuePreviewState(true);
    },

    onRowHeightChange(event: Event) {
      const value = Number((event.target as HTMLInputElement).value);
      if (!Number.isFinite(value) || value < 1) return;
      const block = this.selected;
      if (!block) return;
      const count = this.gridFlowRowCount();
      const track = `${Math.round(value)}px`;
      block.payload = { ...block.payload, grid_auto_rows: track };
      if (count) block.payload.grid_rows = `repeat(${count}, ${track})`;
      this.markDirty();
      if (this.boot.storefrontUrl) this.queuePreviewState(true);
    },

    layoutGridColsSelect(): string {
      const raw = String(this.selected?.payload.grid_cols || "2");
      if (["1", "2", "3", "4", "6", "12"].includes(raw)) return raw;
      return "custom";
    },

    isCustomGridCols(): boolean {
      return this.layoutGridColsSelect() === "custom";
    },

    onGridColsSelect(event: Event) {
      const t = event.target as HTMLSelectElement;
      if (t.value === "custom") {
        const current = String(this.selected?.payload.grid_cols || "");
        if (["1", "2", "3", "4", "6", "12", ""].includes(current)) {
          this.setPayload("grid_cols", "1fr 1fr");
        }
        return;
      }
      this.onTextInput("grid_cols", event);
    },

    layoutChildCount(): number {
      const kids = this.selected?.payload.children;
      return Array.isArray(kids) ? kids.length : 0;
    },

    hasLayoutChildren(): boolean {
      return this.layoutChildCount() >= 2;
    },

    isLayoutFlex(): boolean {
      return (String(this.selected?.payload.display || "flex")) !== "grid";
    },

    isLayoutGrid(): boolean {
      return String(this.selected?.payload.display || "") === "grid";
    },

    onEditorPayload(key: string, event: Event) {
      const t = event.target as HTMLElement | null;
      if (!t) return;
      if (!(t instanceof HTMLTextAreaElement)) return;
      if (
        !t.classList.contains("g-editor__input") &&
        !t.classList.contains("g-editor__html")
      )
        return;
      this.setPayload(key, t.value);
    },

    insertVariable(path: string) {
      const meta = (this.boot.variables || []).find((v) => v.path === path);
      const token = meta?.token || `{{${path}}}`;
      if ((this.selected?.type === "layout" || (this.selected?.type === "dynamic" && this.templateOwner?.id === this.selected.id)) && !this.insertTarget) {
        const host = this.selected;
        this.addBlock("text", null, { list: this.ensureLayoutChildren(host), layoutHost: host });
        this.setPayload("body", token);
        return;
      }
      const defaultKeys: Record<string, string> = {
        heading: "text", text: "body", html: "html", quote: "quote", cta: "label", rating: "value", avatar: "name", badge: "label",
      };
      const key = this.insertTarget?.key || (this.selected ? defaultKeys[this.selected.type] : undefined);
      if (!this.selected || !key) {
        toast(
          this.boot.labels.pickField || "Nejdřív klikni do pole v inspectoru.",
          "info",
        );
        this.modalTab = "vars";
        return;
      }
      const wrap = (this.$el as HTMLElement).querySelector(
        `.cms-builder__wysiwyg[data-editor-key="${key}"] .g-editor, .cms-builder-canvas-editor[data-editor-key="${key}"] .g-editor`,
      ) as HTMLElement | null;
      if (wrap) {
        wrap.dispatchEvent(
          new CustomEvent("gereq-editor-insert", {
            detail: { text: token },
            bubbles: true,
          }),
        );
        this.markDirty();
        return;
      }
      const current = String(this.selected.payload[key] ?? "");
      const el = document.activeElement as
        HTMLInputElement | HTMLTextAreaElement | null;
      if (
        el &&
        (el.tagName === "INPUT" || el.tagName === "TEXTAREA") &&
        typeof el.selectionStart === "number"
      ) {
        const start = el.selectionStart ?? current.length;
        const end = el.selectionEnd ?? start;
        const next = current.slice(0, start) + token + current.slice(end);
        this.setPayload(key, next);
        this.$nextTick(() => {
          el.focus();
          const pos = start + token.length;
          el.setSelectionRange(pos, pos);
        });
        return;
      }
      this.setPayload(key, current + token);
    },

    applyMedia(media: MediaItem) {
      if (this.selected?.type === "image") {
        this.setPayload("media_id", media.id);
        this.setPayload("url", media.url);
        if (!this.selected.payload.alt) {
          this.setPayload("alt", media.alt || media.title);
        }
        return;
      }
      if (this.selected?.type === "gallery") {
        if (this.mediaMode === "gallery") this.toggleGalleryMedia(media);
        return;
      }
      this.addBlock("image");
      this.setPayload("media_id", media.id);
      this.setPayload("url", media.url);
      this.setPayload("alt", media.alt || media.title);
    },

    openMediaPicker(mode: "apply" | "gallery") {
      this.mediaMode = mode;
      this.createOpen = false;
      this.modalTab = "media";
    },

    saveLibraryPreference() {
      const tab = this.createOpen ? this.createTab : this.modalTab;
      try {
        localStorage.setItem("gereq.cms.builder.library", JSON.stringify({ tab }));
      } catch {
        // Storage may be unavailable in private or restricted browsing.
      }
    },

    restoreLibraryPreference() {
      try {
        const raw = localStorage.getItem("gereq.cms.builder.library");
        if (!raw) return;
        const state = JSON.parse(raw);
        if (!state || typeof state !== "object") return;
        if (state.tab === null) {
          this.closeLibrary();
        } else if (["elements", "templates", "vars", "media", "docs", "dynamic"].includes(state.tab)) {
          this.openLibraryTab(state.tab);
        }
      } catch {
        // Ignore invalid preferences and retain the default state.
      }
    },

    openLibraryTab(tab: "elements" | "templates" | "vars" | "media" | "docs" | "dynamic") {
      if (tab === "dynamic" && this.formInsertionContext) tab = "elements";
      if (tab === "dynamic") this.dynamicLibrarySourceId = "";
      this.createOpen = tab === "elements" || tab === "templates";
      if (tab === "elements" || tab === "templates") {
        this.createTab = tab;
        this.modalTab = null;
      } else {
        this.modalTab = tab;
      }
    },

    closeLibrary() {
      this.createOpen = false;
      this.modalTab = null;
    },

    toggleLibrary() {
      if (this.modalTab || this.createOpen) {
        this.closeLibrary();
        return;
      }
      this.mediaMode = "apply";
      if (this.selectedDynamicOwner) {
        if (this.selected?.type === "dynamic" && this.templateOwner?.id !== this.selected.id) this.editDynamicTemplate();
        this.insertAfterId = this.selectedId;
        this.openLibraryTab("elements");
        return;
      }
      this.openLibraryTab("vars");
    },

    applyDocument(doc: DocItem) {
      this.addBlock("cta");
      this.setPayload("label", doc.title);
      this.setPayload("url", doc.url);
      this.setPayload("style", "primary");
    },

    imageSrc(block: BuilderBlock): string {
      const mediaId = String(block.payload.media_id || "").trim();
      if (mediaId) return `/media/${encodeURIComponent(mediaId)}/file`;
      const url = this.expandPreview(String(block.payload.url || ""));
      // Imported storefront assets live on the preview origin, not on the admin host.
      if (/^\/?assets\//.test(url) && this.boot.storefrontUrl) {
        try { return new URL(url, this.boot.storefrontUrl).href; } catch { return url; }
      }
      return url;
    },

    headingLevel(block: BuilderBlock): number {
      const raw = Number(block.payload.level);
      if (!Number.isFinite(raw) || raw < 1) return 2;
      return Math.min(3, Math.floor(raw));
    },

    isHeadingLevel(block: BuilderBlock, level: number): boolean {
      return this.headingLevel(block) === level;
    },

    isH1(block: BuilderBlock): boolean {
      return this.headingLevel(block) === 1;
    },

    isH2(block: BuilderBlock): boolean {
      return this.headingLevel(block) === 2;
    },

    isH3(block: BuilderBlock): boolean {
      return this.headingLevel(block) >= 3;
    },

    headingEditClass(block: BuilderBlock): string {
      return "cms-builder-heading--" + this.headingLevel(block);
    },

    blurTarget(event: Event) {
      (event.target as HTMLElement | null)?.blur();
    },

    headingText(block: BuilderBlock): string {
      const text = block.payload.text;
      const value =
        text === null || text === undefined || text === "" ? "—" : String(text);
      return this.expandPreview(value);
    },

    quoteText(block: BuilderBlock): string {
      const text = block.payload.quote;
      const value =
        text === null || text === undefined || text === "" ? "—" : String(text);
      return this.expandPreview(value);
    },

    captionText(block: BuilderBlock): string {
      const text = block.payload.caption;
      if (text === null || text === undefined || text === "") return "";
      return stripHtml(this.expandPreview(String(text)));
    },

    captionVisibleOn(block: BuilderBlock): boolean {
      return (
        String(block.payload.caption ?? "") !== "" &&
        String(block.payload.caption_visible ?? "1") !== "0"
      );
    },

    setCaptionVisible(on: boolean) {
      const block = this.selected;
      if (!block) return;
      if (on && String(block.payload.caption ?? "") === "") {
        this.setPayload("caption", "<p><br></p>");
      }
      this.setPayload("caption_visible", on ? "1" : "0");
    },

    flagOn(block: BuilderBlock, key: string): boolean {
      return String(block.payload[key] ?? "1") !== "0";
    },

    setFlag(key: string, on: boolean) {
      this.setPayload(key, on ? "1" : "0");
    },

    ctaLabel(block: BuilderBlock): string {
      const label = block.payload.label;
      const value =
        label === null || label === undefined || label === ""
          ? "Odkaz"
          : String(label);
      return this.expandPreview(value);
    },

    ctaClass(block: BuilderBlock): string {
      return block.payload.style === "primary" ? "btn--primary" : "btn--ghost";
    },

    plainPreview(html: unknown): string {
      return stripHtml(this.expandPreview(String(html || ""))).slice(0, 220);
    },

    blockStyleClass(block: BuilderBlock): string {
      const p = block.payload;
      const parts: string[] = [];
      if (Number(p.visible ?? 1) === 0) parts.push("is-invisible");
      const bg = String(p.bg_color || "none");
      if (bg !== "none" && !bg.startsWith("#")) parts.push("is-bg-" + bg);
      const tc = String(p.text_color || "inherit");
      if (tc !== "inherit" && !tc.startsWith("#")) parts.push("is-text-" + tc);
      const ff = String(p.font_family || "inherit");
      if (ff !== "inherit") parts.push("is-font-" + ff);
      const fs = String(p.font_size || "inherit");
      if (fs !== "inherit") parts.push("is-fsize-" + fs);
      const fw = String(p.font_weight || "inherit");
      if (fw !== "inherit") parts.push("is-fw-" + fw);
      const sh = String(p.shadow || "none");
      if (sh !== "none") parts.push("is-box-shadow-" + sh);
      return parts.join(" ");
    },

    blockInlineStyle(block: BuilderBlock): string {
      const p = block.payload;
      const styles: string[] = [];
      const opacity = Number(p.opacity ?? 100);
      if (opacity < 100)
        styles.push(`opacity:${Math.max(0, Math.min(100, opacity)) / 100}`);
      const radius = Number(p.radius || 0);
      if (radius > 0) styles.push(`border-radius:${radius}px`);
      const padding = Number(p.padding || 0);
      if (padding > 0) styles.push(`padding:${padding}px`);
      const borderW = Number(p.border_w || 0);
      if (borderW > 0) {
        const bs = String(p.border_style || "solid");
        const bc = this.cssColor(String(p.border_color || "ink"));
        styles.push(`border:${borderW}px ${bs} ${bc}`);
      }
      const bg = String(p.bg_color || "none");
      if (bg.startsWith("#")) styles.push(`background-color:${bg}`);
      else if (["background", "header", "brand", "secondary", "accent", "light-background", "light-surface", "light-header", "dark-background", "dark-surface", "dark-header"].includes(bg)) styles.push(`background-color:${this.cssColor(bg)}`);
      const tc = String(p.text_color || "inherit");
      if (tc.startsWith("#")) styles.push(`color:${tc}`);
      else if (["background", "surface", "header", "brand", "secondary", "accent", "light-background", "light-surface", "light-header", "dark-background", "dark-surface", "dark-header"].includes(tc)) styles.push(`color:${this.cssColor(tc)}`);
      return styles.join(";");
    },

    cssColor(tokenOrHex: string): string {
      if (tokenOrHex.startsWith("#")) return tokenOrHex;
      const previewToken = tokenOrHex === "paper" ? "background" : tokenOrHex;
      if (this.previewColors[previewToken]) return this.previewColors[previewToken];
      const brand = this.boot.varValues || {};
      const map: Record<string, string> = {
        ink: brand["brand.text"] || brand["brand.ink"] || "#1b202b",
        muted: brand["brand.muted"] || "#6f798c",
        brand: brand["brand.primary"] || brand["brand.signal"] || "#17375a",
        secondary: brand["brand.secondary"] || brand["brand.primary"] || "#17375a",
        accent: brand["brand.accent"] || brand["brand.primary"] || "#17375a",
        "brand-soft": "#eef3f9",
        paper: brand["brand.background"] || brand["brand.cream"] || "#f5f6f8",
        background: brand["brand.background"] || brand["brand.cream"] || "#f5f6f8",
        surface: brand["brand.surface"] || "#ffffff",
        header: brand["brand.header"] || brand["brand.surface"] || "#ffffff",
        "light-background": brand["brand.light_background"] || "#ffffff",
        "light-surface": brand["brand.light_surface"] || "#ffffff",
        "light-header": brand["brand.light_header"] || "#ffffff",
        "dark-background": brand["brand.dark_background"] || "#111111",
        "dark-surface": brand["brand.dark_surface"] || "#111111",
        "dark-header": brand["brand.dark_header"] || "#111111",
      };
      return map[tokenOrHex] || brand["brand.ink"] || "#1b202b";
    },

    isHexColor(value: string): boolean {
      return /^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(String(value || ""));
    },

    colorSwatch(value: string, fallback: string): string {
      const v = String(value || "");
      if (this.isHexColor(v)) {
        return v.length === 4
          ? `#${v[1]}${v[1]}${v[2]}${v[2]}${v[3]}${v[3]}`
          : v.toLowerCase();
      }
      return this.cssColor(!v || v === "none" || v === "inherit" ? fallback : v);
    },

    setAlign(value: string) {
      this.setPayload("align", value);
    },

    htmlEditorMode(): "preview" | "source" {
      return this.selectedId ? this.htmlEditorModes[this.selectedId] || "source" : "source";
    },

    setHtmlEditorMode(mode: "preview" | "source") {
      if (this.selected?.type !== "html" || !this.boot.storefrontUrl) return;
      this.htmlEditorModes[this.selected.id] = mode;
      this.postPreview({ t: "html-editor-mode", id: this.selected.id, mode });
    },

    setVisible(on: boolean) {
      this.setPayload("visible", on ? 1 : 0);
    },

    onOpacityInput(event: Event) {
      const t = event.target as HTMLInputElement;
      this.setPayload(
        "opacity",
        Math.max(0, Math.min(100, Number(t.value) || 0)),
      );
    },

    onNumberPayload(key: string, event: Event, min: number, max: number) {
      const t = event.target as HTMLInputElement;
      this.setPayload(key, Math.max(min, Math.min(max, Number(t.value) || 0)));
    },

    onOptionalSpacing(key: string, event: Event, max: number) {
      const value = (event.target as HTMLInputElement).value;
      this.setPayload(key, value === "" ? "" : Math.max(0, Math.min(max, Math.trunc(Number(value) || 0))));
    },

    isNumberDefault(key: string, value: number | string, scope: "payload" | "dynamic" | "textLimit"): boolean {
      const current = scope === "dynamic" ? this.dynamicItemDesign[key]
        : scope === "textLimit" ? this.textLimitValue(this.activeTextLimitField, "max")
        : this.selected?.payload[key];
      if (current === undefined || current === null || current === "") return true;
      return value === "" ? false : Number(current) === Number(value);
    },

    resetNumberInput(key: string, value: number | string, scope: "payload" | "dynamic" | "textLimit", event: Event) {
      const button = event.currentTarget as HTMLElement;
      const input = button.parentElement?.querySelector<HTMLInputElement>('input[type="number"]');
      if (!input) return;
      input.value = String(value);
      if (scope === "dynamic") {
        this.setDynamicItemStyle(key, { target: input } as unknown as Event);
      } else if (scope === "textLimit") {
        this.onTextLimitInput(this.activeTextLimitField, "max", { target: input } as unknown as Event);
      } else {
        this.setPayload(key, value);
      }
    },

    resetSpacing(key: string, event: Event) {
      const button = event.currentTarget as HTMLElement;
      const input = button.parentElement?.querySelector<HTMLInputElement>('input[type="number"]');
      if (input) input.value = "";
      this.setPayload(key, "");
    },

    setColorToken(key: string, event: Event) {
      const t = event.target as HTMLSelectElement;
      if (t.value === "custom") {
        const current = String(this.selected?.payload[key] ?? "");
        if (!this.isHexColor(current)) {
          const fallback = key === "bg_color" ? "paper" : "ink";
          this.setPayload(key, this.colorSwatch(current || fallback, fallback));
        }
        return;
      }
      this.setPayload(key, t.value);
    },

    colorTokenSelect(value: string, fallback: string): string {
      const v = String(value || fallback);
      return this.isHexColor(v) ? "custom" : v;
    },

    isVisibleOn(block: BuilderBlock | null): boolean {
      return Number(block?.payload.visible ?? 1) !== 0;
    },

    hasShadow(block: BuilderBlock | null): boolean {
      return String(block?.payload.shadow || "none") !== "none";
    },

    setColorHex(key: string, event: Event) {
      const t = event.target as HTMLInputElement;
      let v = t.value.trim();
      if (!v.startsWith("#")) v = "#" + v;
      if (this.isHexColor(v)) this.setPayload(key, v.toLowerCase());
    },

    addShadow() {
      this.setPayload("shadow", "soft");
    },

    clearShadow() {
      this.setPayload("shadow", "none");
    },

    hasTypography(block: BuilderBlock | null): boolean {
      if (!block) return false;
      return ["heading", "text", "html", "quote", "cta", "form_field"].includes(block.type);
    },

    formFieldOptions(block: BuilderBlock): string[] {
      return String(block.payload.options || "")
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter(Boolean);
    },

    imageFigureClass(block: BuilderBlock): string {
      const w = String(block.payload.img_w || "100");
      const h = String(block.payload.img_h || "auto");
      const fit = String(block.payload.img_fit || "cover");
      return [
        "cms-builder-figure",
        "is-w-" + w,
        "is-h-" + h,
        "is-fit-" + fit,
      ].join(" ");
    },

    async openPreview() {
      if (this.boot.sandbox || !this.boot.previewUrl) return;
      if (this.dirty) {
        const ok = await askConfirm({
          title:
            this.boot.labels.previewUnsavedTitle ||
            "Uložit před náhledem?",
          body:
            this.boot.labels.previewUnsavedBody ||
            "Náhled ukazuje uloženou pracovní kopii. Neuložené změny se v něm neobjeví.",
          confirmLabel:
            this.boot.labels.previewUnsavedConfirm || "Uložit a náhled",
        });
        if (!ok) return;
        await this.save();
        if (this.dirty) return;
      }
      window.open(this.boot.previewUrl, "_blank", "noopener");
    },

    async save() {
      if (this.savedTemplateSession) {
        await this.saveDynamicTemplate();
        return;
      }
      const editingOwnerId = this.templateOwner?.id ?? null;
      if (this.boot.sandbox) {
        await this.savePartial();
        return;
      }
      if (this.saving) return;
      if (!this.boot.saveUrl) return;
      this.saving = true;
      this.status = this.boot.labels.saving || "";
      let failureMessage = this.boot.labels.saveFailed || "";
      try {
        const res = await fetch(this.boot.saveUrl, {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            Accept: "application/json",
            "X-CSRF-TOKEN": csrfToken(this.boot),
            "X-Requested-With": "XMLHttpRequest",
          },
          credentials: "same-origin",
          body: JSON.stringify({
            blocks: this.persistedBlocks().map((b, i) => ({
              id: b._new || b.id.startsWith("tmp_") ? null : b.id,
              client_id: b.id,
              type: b.type,
              sort_order: i + 1,
              payload: b.payload,
            })),
          }),
        });
        const json = (await res.json()) as {
          data?: { blocks?: BuilderBlock[]; id_map?: Record<string, string> };
          error?: { message?: string; fields?: Record<string, string | string[]> };
          message?: string;
        };
        if (!res.ok) {
          failureMessage = Object.values(json?.error?.fields || {}).flat().join(" ") || json?.error?.message || json?.message || failureMessage;
          throw new Error(failureMessage);
        }
        const saved = json.data?.blocks || [];
        const map = json.data?.id_map || {};
        this.blocks = saved.map(normalizeBlock);
        this.templateOwner = editingOwnerId ? this.findBlock(map[editingOwnerId] || editingOwnerId) : null;
        if (this.templateOwner) {
          this.templateOwner.payload.children = JSON.parse(JSON.stringify(this.templateOwner.payload.item_blocks || []));
        }
        if (this.selectedId && map[this.selectedId]) {
          this.selectedId = map[this.selectedId];
        } else if (
          this.selectedId &&
          !this.findBlock(this.selectedId)
        ) {
          this.selectedId = this.blocks[0]?.id ?? null;
        }
        this.undoStack = [];
        this.redoStack = [];
        this.historyState = this.captureHistory();
        this.dirty = false;
        this.staged = true;
        void window.gereq?.staging?.refresh?.();
        this.status = this.boot.labels.saved || "";
        this.queuePreviewState();
      } catch {
        this.status = failureMessage;
        toast(this.status, "error");
      } finally {
        this.saving = false;
      }
    },
  }));
}
