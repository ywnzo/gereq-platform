import type Alpine from '@alpinejs/csp';
import {
    Chart,
    LineController,
    BarController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Filler,
    Tooltip,
    Legend,
    type ChartConfiguration,
    type ChartType,
} from 'chart.js';

Chart.register(
    LineController,
    BarController,
    DoughnutController,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    BarElement,
    ArcElement,
    Filler,
    Tooltip,
    Legend,
);

function cssVar(name: string, fallback: string, element: HTMLElement = document.documentElement): string {
    const value = getComputedStyle(element).getPropertyValue(name).trim();

    return value || fallback;
}

export function registerChart(alpine: typeof Alpine): void {
    alpine.data('gereqChart', () => ({
        chart: null as Chart | null,
        $el: null as unknown as HTMLElement,
        $refs: {} as { canvas?: HTMLCanvasElement },

        init() {
            const root = this.$el;
            const canvas = this.$refs.canvas;
            if (!canvas) {
                return;
            }

            let config: Record<string, unknown> = {};
            try {
                config = JSON.parse(root.dataset.config || '{}') as Record<string, unknown>;
            } catch {
                config = {};
            }

            const type = String(config.type || 'line') as ChartType;
            const labels = (config.labels as string[]) || [];
            const datasets = (config.datasets as Array<Record<string, unknown>>) || [];
            const primary = cssVar('--primary', '#47c2ff');
            const text = cssVar('--text-muted', '#64748b', root);
            const border = cssVar('--border', '#e2e8f0');
            const surface = cssVar('--surface', '#ffffff');

            const painted = datasets.map((ds, index) => {
                const color = String(ds.color || primary);
                const kind = String(ds.kind || type);
                if (kind === 'doughnut' || type === 'doughnut') {
                    const palette = (ds.colors as string[]) || [
                        primary,
                        '#7dd3fc',
                        '#94a3b8',
                        '#34d399',
                        '#fbbf24',
                        '#f87171',
                    ];

                    return {
                        label: String(ds.label ?? ''),
                        data: (Array.isArray(ds.data) ? ds.data : []) as number[],
                        backgroundColor: palette,
                        borderColor: surface,
                        borderWidth: 2,
                        hoverOffset: 6,
                    };
                }

                return {
                    label: String(ds.label ?? ''),
                    data: (Array.isArray(ds.data) ? ds.data : []) as number[],
                    borderColor: color,
                    backgroundColor: kind === 'bar' ? color : `${color}33`,
                    borderWidth: 2,
                    tension: 0.35,
                    fill: kind === 'line' ? Boolean(ds.fill ?? true) : false,
                    pointRadius: kind === 'line' ? 3 : 0,
                    pointHoverRadius: 5,
                    borderRadius: kind === 'bar' ? 6 : 0,
                    order: index,
                };
            });

            const chartConfig = {
                type,
                data: { labels, datasets: painted },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: false,
                    interaction: { mode: 'index' as const, intersect: false },
                    plugins: {
                        legend: {
                            display: type === 'doughnut' || datasets.length > 1,
                            position: (type === 'doughnut' ? 'right' : 'top') as 'right' | 'top',
                            labels: {
                                color: text,
                                boxWidth: 12,
                                usePointStyle: true,
                                font: { size: 11 },
                            },
                        },
                        tooltip: {
                            backgroundColor: cssVar('--ink', '#0f172a'),
                            titleFont: { size: 12 },
                            bodyFont: { size: 12 },
                            padding: 10,
                            cornerRadius: 8,
                        },
                    },
                    scales: type === 'doughnut' ? {} : {
                        x: {
                            grid: { color: border },
                            ticks: { color: text, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 },
                            border: { display: false },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: border },
                            ticks: { color: text, precision: 0 },
                            border: { display: false },
                        },
                    },
                },
            } satisfies ChartConfiguration<ChartType, number[], string>;

            this.chart = new Chart(canvas, chartConfig);

            root.addEventListener('alpine:destroy', () => {
                this.chart?.destroy();
                this.chart = null;
            });
        },
    }));
}
