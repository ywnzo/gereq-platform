import { createApp, h, onUnmounted, ref } from 'vue';
import DatePicker from 'ant-design-vue/es/date-picker';
import ConfigProvider from 'ant-design-vue/es/config-provider';
import csCZ from 'ant-design-vue/es/locale/cs_CZ';
import enGB from 'ant-design-vue/es/locale/en_GB';
import darkAlgorithm from 'ant-design-vue/es/theme/themes/dark';
import defaultAlgorithm from 'ant-design-vue/es/theme/themes/default';
import 'dayjs/locale/cs';
import type { Dayjs } from 'dayjs';

/** Enhance only submission date filters; the server still receives ISO calendar dates. */
export function mountFormSubmissionDates(): void {
    document.querySelectorAll<HTMLElement>('[data-cms-date-picker]').forEach((host) => {
        const input = host.parentElement?.querySelector<HTMLInputElement>('input[data-cms-date-value]');
        if (!input || host.dataset.mounted === '1') return;
        host.dataset.mounted = '1';
        const locale = document.documentElement.lang.startsWith('cs') ? csCZ : enGB;
        const styles = getComputedStyle(document.documentElement);
        const nonce = document.documentElement.dataset.cspNonce ?? '';
        createApp({
            setup() {
                const value = ref(input.value || undefined);
                const dark = ref(document.documentElement.classList.contains('dark'));
                const observer = new MutationObserver(() => {
                    dark.value = document.documentElement.classList.contains('dark');
                });
                observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
                onUnmounted(() => observer.disconnect());
                return () => h(ConfigProvider, {
                    locale,
                    csp: { nonce },
                    theme: {
                        algorithm: dark.value ? darkAlgorithm : defaultAlgorithm,
                        token: {
                            colorPrimary: styles.getPropertyValue('--accent').trim() || '#a3bd24',
                            fontFamily: styles.fontFamily,
                            controlHeight: 36,
                            borderRadius: 4,
                        },
                    },
                }, {
                    default: () => h(DatePicker, {
                        id: host.dataset.inputId,
                        value: value.value,
                        valueFormat: 'YYYY-MM-DD',
                        format: 'YYYY-MM-DD',
                        placeholder: host.dataset.placeholder,
                        allowClear: true,
                        showToday: false,
                        class: 'cms-form-filters__date-picker',
                        onChange: (_date: string | Dayjs | null, dateString: string | string[]) => {
                            const next = typeof dateString === 'string' ? dateString : '';
                            value.value = next || undefined;
                            input.value = next;
                            input.dispatchEvent(new Event('change', { bubbles: true }));
                        },
                    }),
                });
            },
        }).mount(host);
    });
}
