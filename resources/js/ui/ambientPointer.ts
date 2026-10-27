/**
 * Subtle pointer parallax for the admin ambient background.
 * Sets --ambient-px / --ambient-py on .app-ambient (−1…1 from viewport center).
 */
export function registerAmbientPointer(): void {
    const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (reduced.matches) {
        return;
    }

    let root: HTMLElement | null = null;
    let targetX = 0;
    let targetY = 0;
    let curX = 0;
    let curY = 0;
    let raf = 0;
    let running = false;

    const ensureRoot = (): HTMLElement | null => {
        if (root?.isConnected) {
            return root;
        }
        root = document.querySelector<HTMLElement>('.app-ambient');

        return root;
    };

    const tick = (): void => {
        const el = ensureRoot();
        if (!el || reduced.matches || document.documentElement.dataset.ambientMotion === '0') {
            running = false;
            raf = 0;

            return;
        }

        curX += (targetX - curX) * 0.07;
        curY += (targetY - curY) * 0.07;
        if (Math.abs(targetX - curX) < 0.001 && Math.abs(targetY - curY) < 0.001) {
            curX = targetX;
            curY = targetY;
        }

        el.style.setProperty('--ambient-px', curX.toFixed(4));
        el.style.setProperty('--ambient-py', curY.toFixed(4));

        if (curX !== targetX || curY !== targetY) {
            raf = window.requestAnimationFrame(tick);
        } else {
            running = false;
            raf = 0;
        }
    };

    const kick = (): void => {
        if (running || reduced.matches || document.documentElement.dataset.ambientMotion === '0') {
            return;
        }
        running = true;
        raf = window.requestAnimationFrame(tick);
    };

    const onPointer = (event: PointerEvent): void => {
        if (reduced.matches || document.documentElement.dataset.ambientMotion === '0' || event.pointerType === 'touch') {
            return;
        }
        const w = window.innerWidth || 1;
        const h = window.innerHeight || 1;
        targetX = (event.clientX / w) * 2 - 1;
        targetY = (event.clientY / h) * 2 - 1;
        kick();
    };

    const onLeave = (): void => {
        targetX = 0;
        targetY = 0;
        kick();
    };

    const onReducedChange = (): void => {
        if (reduced.matches) {
            targetX = 0;
            targetY = 0;
            curX = 0;
            curY = 0;
            const el = ensureRoot();
            el?.style.setProperty('--ambient-px', '0');
            el?.style.setProperty('--ambient-py', '0');
            if (raf) {
                window.cancelAnimationFrame(raf);
                raf = 0;
            }
            running = false;
        }
    };

    document.addEventListener('pointermove', onPointer, { passive: true });
    document.addEventListener('pointerleave', onLeave);
    window.addEventListener('blur', onLeave);
    reduced.addEventListener('change', onReducedChange);

    const boot = (): void => {
        ensureRoot()?.style.setProperty('--ambient-px', '0');
        ensureRoot()?.style.setProperty('--ambient-py', '0');
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}
