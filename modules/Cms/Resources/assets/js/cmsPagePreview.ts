import type Alpine from "@alpinejs/csp";

type PreviewBlock = {
  id: string;
  type: string;
  sort_order: number;
  payload: Record<string, unknown>;
};

type PreviewBoot = {
  pageId: string;
  blocks: PreviewBlock[];
  storefrontUrl: string;
  previewPalette?: Record<string, unknown>;
};

function previewOrigin(url: string): string {
  try {
    return new URL(url).origin;
  } catch {
    return "";
  }
}

export function registerCmsPagePreview(alpine: typeof Alpine): void {
  alpine.data("cmsPagePreview", () => ({
    device: "desktop" as "desktop" | "tablet" | "mobile",
    boot: {
      pageId: "",
      blocks: [],
      storefrontUrl: "",
    } as PreviewBoot,

    init() {
      const root = this.$el as HTMLElement;
      try {
        this.boot = JSON.parse(root.dataset.boot || "{}") as PreviewBoot;
      } catch {
        /* keep defaults */
      }
      if (this.boot.storefrontUrl) {
        window.addEventListener("message", (e) => this.onFrameMessage(e));
      }
    },

    postFrame(msg: unknown) {
      const frame = document.getElementById("cmsPreviewFrame");
      if (!(frame instanceof HTMLIFrameElement) || !frame.contentWindow) return;
      const target = previewOrigin(this.boot.storefrontUrl) || "*";
      frame.contentWindow.postMessage(JSON.parse(JSON.stringify(msg)), target);
    },

    onFrameMessage(event: MessageEvent) {
      const msg = event.data as { t?: string } | undefined;
      if (!msg || typeof msg !== "object" || !this.boot.storefrontUrl) return;
      if (event.origin !== previewOrigin(this.boot.storefrontUrl)) return;
      const frame = document.getElementById("cmsPreviewFrame");
      if (!(frame instanceof HTMLIFrameElement) || event.source !== frame.contentWindow) return;
      if (msg.t === "ready") {
        this.postFrame({
          t: "boot",
          pageId: this.boot.pageId,
          blocks: this.boot.blocks,
          palette: this.boot.previewPalette,
        });
      }
    },
  }));
}
