import type Alpine from '@alpinejs/csp';
import { Replayer } from '@rrweb/replay';
import type { eventWithTime } from '@rrweb/types';
import '@rrweb/replay/dist/style.css';
import '../css/admin.css';

export default function register(alpine: typeof Alpine): void {
    alpine.data('analyticsReplay', () => {
        let player: Replayer | undefined;
        let recordings: eventWithTime[][] = [];
        let index = 0;
        let offset = 0;
        let observer: ResizeObserver | undefined;
        return {
            loading: true,
            empty: false,
            failed: false,
            unavailable: true,
            position: '',
            async init() {
                try {
                    const url = (this.$el as HTMLElement).dataset.replayUrl;
                    if (!url) throw new Error('Missing replay URL');
                    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store' });
                    if (!response.ok) throw new Error('Replay unavailable');
                    const data = await response.json() as { recordings?: Record<string, eventWithTime[]> };
                    recordings = Object.values(data.recordings ?? {}).filter(events => events.length >= 2);
                    this.empty = recordings.length === 0;
                    this.unavailable = this.empty;
                    if (!this.empty) this.mount();
                } catch {
                    this.failed = true;
                } finally { this.loading = false; }
            },
            mount() {
                observer?.disconnect();
                player?.destroy();
                const stage = this.$refs.stage as HTMLElement;
                stage.replaceChildren();
                offset = 0;
                player = new Replayer(recordings[index], {
                    root: stage,
                    skipInactive: true,
                    showWarning: false,
                    showDebug: false,
                    UNSAFE_replayCanvas: false,
                    mouseTail: true,
                    insertStyleRules: ['html { pointer-events: none !important; }'],
                });
                // Recorded links/forms must stay inert in the sandboxed replay.
                player.iframe.style.pointerEvents = 'none';
                const resize = () => {
                    if (!player) return;
                    const width = Number(player.iframe.getAttribute('width')) || 1440;
                    const height = Number(player.iframe.getAttribute('height')) || 900;
                    const scale = Math.min(1, stage.clientWidth / width);
                    player.wrapper.style.transformOrigin = 'top left';
                    player.wrapper.style.transform = `scale(${scale})`;
                    stage.style.height = `${height * scale}px`;
                };
                observer = new ResizeObserver(resize);
                observer.observe(stage);
                player.on('resize', resize);
                this.position = `${index + 1} / ${recordings.length}`;
                resize();
            },
            play() { player?.play(offset); },
            pause() {
                if (player) offset = player.getCurrentTime();
                player?.pause();
            },
            restart() { offset = 0; player?.play(0); },
            next() {
                if (!recordings.length) return;
                index = (index + 1) % recordings.length;
                this.mount();
            },
            destroy() { observer?.disconnect(); player?.destroy(); },
        };
    });
}
