import type Alpine from '@alpinejs/csp';

type OpenImageOpts = {
    alt?: string;
};

type ImageViewerApi = {
    openImage: (src: string, opts?: OpenImageOpts) => void;
    closeImage: () => void;
};

const IMAGE_EXT = /\.(avif|bmp|gif|jpe?g|png|svg|webp)(?:[?#]|$)/i;
const MEDIA_FILE = /\/(?:admin\/)?media\/file(?:\/|$)/i;
const MIN_SCALE = 0.25;
const MAX_SCALE = 8;
const ZOOM_STEP = 1.25;

type OpenFn = (src: string, opts?: OpenImageOpts) => void;
type CloseFn = () => void;

let openImpl: OpenFn | null = null;
let closeImpl: CloseFn | null = null;
let keyImpl: ((event: KeyboardEvent) => void) | null = null;

function isImageUrl(url: string): boolean {
    if (!url || url === '#' || url.startsWith('javascript:')) {
        return false;
    }
    try {
        const parsed = new URL(url, window.location.origin);
        if (IMAGE_EXT.test(parsed.pathname) || IMAGE_EXT.test(parsed.href)) {
            return true;
        }
        if (MEDIA_FILE.test(parsed.pathname)) {
            return true;
        }
        return false;
    } catch {
        return IMAGE_EXT.test(url) || MEDIA_FILE.test(url);
    }
}

function sameResource(a: string, b: string): boolean {
    try {
        const ua = new URL(a, window.location.origin);
        const ub = new URL(b, window.location.origin);
        return ua.origin === ub.origin && ua.pathname === ub.pathname;
    } catch {
        return a === b;
    }
}

function resolveFromClick(target: EventTarget | null): { src: string; alt: string } | null {
    if (!(target instanceof Element)) {
        return null;
    }
    if (target.closest('[data-gereq-no-image-viewer], #gereq-image-viewer')) {
        return null;
    }

    const explicit = target.closest<HTMLElement>('[data-gereq-image], [data-pc-lightbox-open]');
    if (explicit) {
        const src =
            explicit.getAttribute('data-gereq-image')
            || explicit.getAttribute('data-pc-src')
            || explicit.querySelector('img')?.currentSrc
            || explicit.querySelector('img')?.src
            || '';
        if (src) {
            const img = explicit.querySelector('img');
            return { src, alt: img?.alt || explicit.getAttribute('aria-label') || '' };
        }
    }

    const anchor = target.closest<HTMLAnchorElement>('a[href]');
    if (!anchor || anchor.hasAttribute('download')) {
        return null;
    }
    if (anchor.closest('[data-gereq-no-image-viewer]')) {
        return null;
    }

    const href = anchor.href;
    const img = anchor.querySelector('img');
    if (img && (sameResource(href, img.currentSrc || img.src) || isImageUrl(href))) {
        return { src: href || img.currentSrc || img.src, alt: img.alt || '' };
    }
    if (isImageUrl(href) && (anchor.target === '_blank' || IMAGE_EXT.test(href))) {
        return { src: href, alt: anchor.getAttribute('aria-label') || anchor.title || '' };
    }

    return null;
}

export function registerImageViewer(alpine: typeof Alpine): void {
    alpine.data('gereqImageViewer', () => ({
        open: false,
        src: '',
        alt: '',
        scale: 1,
        rotation: 0,
        tx: 0,
        ty: 0,
        dragging: false,
        dragX: 0,
        dragY: 0,
        originTx: 0,
        originTy: 0,
        labels: {
            close: 'Zavřít',
            zoomIn: 'Přiblížit',
            zoomOut: 'Oddálit',
            rotateLeft: 'Otočit doleva',
            rotateRight: 'Otočit doprava',
            reset: 'Obnovit',
        },

        init() {
            const host = this.$el as HTMLElement;
            this.labels.close = host.dataset.i18nClose || this.labels.close;
            this.labels.zoomIn = host.dataset.i18nZoomIn || this.labels.zoomIn;
            this.labels.zoomOut = host.dataset.i18nZoomOut || this.labels.zoomOut;
            this.labels.rotateLeft = host.dataset.i18nRotateLeft || this.labels.rotateLeft;
            this.labels.rotateRight = host.dataset.i18nRotateRight || this.labels.rotateRight;
            this.labels.reset = host.dataset.i18nReset || this.labels.reset;

            openImpl = (src, opts) => this.show(src, opts);
            closeImpl = () => this.close();
            keyImpl = (event) => this.onKeydown(event);
        },

        destroy() {
            openImpl = null;
            closeImpl = null;
            keyImpl = null;
            document.body.classList.remove('has-image-viewer');
        },

        get zoomLabel(): string {
            return `${Math.round(this.scale * 100)} %`;
        },

        get stageClass(): string {
            return [
                this.scale > 1.01 ? 'is-zoomed' : '',
                this.dragging ? 'is-dragging' : '',
            ]
                .filter(Boolean)
                .join(' ');
        },

        get imgClass(): string {
            const angle = ((this.rotation % 360) + 360) % 360;
            return angle === 90 || angle === 270 ? 'is-sideways' : '';
        },

        get imgStyle(): string {
            return `transform: translate(${this.tx}px, ${this.ty}px) scale(${this.scale}) rotate(${this.rotation}deg)`;
        },

        show(src: string, opts: OpenImageOpts = {}) {
            const next = src.trim();
            if (!next) {
                return;
            }
            this.src = next;
            this.alt = (opts.alt || '').trim();
            this.resetView();
            this.open = true;
            document.body.classList.add('has-image-viewer');
            this.$nextTick(() => {
                (this.$refs.closeBtn as HTMLButtonElement | undefined)?.focus();
            });
        },

        close() {
            if (!this.open) {
                return;
            }
            this.open = false;
            this.dragging = false;
            document.body.classList.remove('has-image-viewer');
            this.src = '';
        },

        resetView() {
            this.scale = 1;
            this.rotation = 0;
            this.tx = 0;
            this.ty = 0;
        },

        onImgLoad() {
            // keep current transform; fresh open already reset
        },

        clampScale(value: number): number {
            return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));
        },

        zoomIn() {
            this.scale = this.clampScale(this.scale * ZOOM_STEP);
        },

        zoomOut() {
            this.scale = this.clampScale(this.scale / ZOOM_STEP);
            if (this.scale <= 1.01) {
                this.tx = 0;
                this.ty = 0;
            }
        },

        rotateLeft() {
            this.rotation = (this.rotation - 90) % 360;
        },

        rotateRight() {
            this.rotation = (this.rotation + 90) % 360;
        },

        onWheel(event: WheelEvent) {
            const factor = event.deltaY < 0 ? ZOOM_STEP : 1 / ZOOM_STEP;
            const next = this.clampScale(this.scale * factor);
            if (next === this.scale) {
                return;
            }
            const stage = this.$refs.stage as HTMLElement | undefined;
            if (stage) {
                const rect = stage.getBoundingClientRect();
                const cx = event.clientX - rect.left - rect.width / 2;
                const cy = event.clientY - rect.top - rect.height / 2;
                const ratio = next / this.scale;
                this.tx = cx - (cx - this.tx) * ratio;
                this.ty = cy - (cy - this.ty) * ratio;
            }
            this.scale = next;
            if (this.scale <= 1.01) {
                this.tx = 0;
                this.ty = 0;
            }
        },

        onDblClick() {
            if (this.scale > 1.05) {
                this.resetView();
                return;
            }
            this.scale = 2;
        },

        onPointerDown(event: PointerEvent) {
            if (event.button !== 0 || this.scale <= 1.01) {
                return;
            }
            const stage = this.$refs.stage as HTMLElement | undefined;
            stage?.setPointerCapture(event.pointerId);
            this.dragging = true;
            this.dragX = event.clientX;
            this.dragY = event.clientY;
            this.originTx = this.tx;
            this.originTy = this.ty;
        },

        onPointerMove(event: PointerEvent) {
            if (!this.dragging) {
                return;
            }
            this.tx = this.originTx + (event.clientX - this.dragX);
            this.ty = this.originTy + (event.clientY - this.dragY);
        },

        onPointerUp(event: PointerEvent) {
            if (!this.dragging) {
                return;
            }
            const stage = this.$refs.stage as HTMLElement | undefined;
            try {
                stage?.releasePointerCapture(event.pointerId);
            } catch {
                // ignore
            }
            this.dragging = false;
        },

        onKeydown(event: KeyboardEvent) {
            if (!this.open) {
                return;
            }
            const tag = (event.target as HTMLElement | null)?.tagName;
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
                return;
            }
            if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                this.zoomIn();
                return;
            }
            if (event.key === '-' || event.key === '_') {
                event.preventDefault();
                this.zoomOut();
                return;
            }
            if (event.key === '0') {
                event.preventDefault();
                this.resetView();
                return;
            }
            if (event.key.toLowerCase() === 'r' && event.shiftKey) {
                event.preventDefault();
                this.rotateLeft();
                return;
            }
            if (event.key.toLowerCase() === 'r') {
                event.preventDefault();
                this.rotateRight();
                return;
            }
            if (event.key.toLowerCase() === 'l') {
                event.preventDefault();
                this.rotateLeft();
            }
        },
    }));

    document.addEventListener('keydown', (event: KeyboardEvent) => {
        keyImpl?.(event);
    });

    const api: ImageViewerApi = {
        openImage(src, opts) {
            if (openImpl) {
                openImpl(src, opts);
                return;
            }
            window.open(src, '_blank', 'noopener');
        },
        closeImage() {
            closeImpl?.();
        },
    };

    window.gereq = { ...(window.gereq || {}), ...api };

    document.addEventListener(
        'click',
        (event) => {
            if (event.defaultPrevented || event.button !== 0) {
                return;
            }
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
                return;
            }
            const hit = resolveFromClick(event.target);
            if (!hit) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            api.openImage(hit.src, { alt: hit.alt });
        },
        true,
    );
}
