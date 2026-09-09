import type Alpine from '@alpinejs/csp';
import { renderAiMarkdown } from './aiMarkdown';

type Step = {
    kind: string;
    name: string;
    ok?: boolean;
    duration_ms?: number;
    args?: unknown;
    content?: string;
};

type TimelineItem =
    | { type: 'user'; text: string; time?: string }
    | {
          type: 'assistant';
          text: string;
          streaming?: boolean;
          time?: string;
          messageId?: string | null;
          feedback?: string | null;
      }
    | { type: 'reasoning'; open: boolean; steps: Step[] };

type Boot = {
    streamUrl: string;
    chatUrl: string;
    feedbackUrl?: string;
    conversationId: string | null;
    timeline: TimelineItem[];
    suggestions: string[];
    labels: Record<string, string>;
};

function csrf(): string {
    return (window as Window & { __GEREQ_CSRF__?: string }).__GEREQ_CSRF__ || '';
}

function nowTime(): string {
    return new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function readBoot(el: HTMLElement): Boot {
    const raw = el.querySelector('#ai-chat-boot')?.textContent || '{}';
    try {
        return JSON.parse(raw) as Boot;
    } catch {
        return {
            streamUrl: '',
            chatUrl: '',
            feedbackUrl: '',
            conversationId: null,
            timeline: [],
            suggestions: [],
            labels: {},
        };
    }
}

export function registerAiChat(alpine: typeof Alpine): void {
    alpine.directive('md', (el, { expression }, { effect, evaluateLater }) => {
        const evaluate = evaluateLater(expression);
        effect(() => {
            evaluate((value: unknown) => {
                el.innerHTML = renderAiMarkdown(typeof value === 'string' ? value : String(value ?? ''));
            });
        });
    });

    alpine.data('aiChat', () => ({
        sending: false,
        draft: '',
        status: '',
        conversationId: null as string | null,
        timeline: [] as TimelineItem[],
        suggestions: [] as string[],
        labels: {} as Record<string, string>,
        streamUrl: '',
        chatUrl: '',
        feedbackUrl: '',

        init() {
            const boot = readBoot(this.$el as HTMLElement);
            this.streamUrl = boot.streamUrl;
            this.chatUrl = boot.chatUrl;
            this.feedbackUrl = boot.feedbackUrl || '';
            this.conversationId = boot.conversationId;
            this.timeline = boot.timeline || [];
            this.suggestions = boot.suggestions || [];
            this.labels = boot.labels || {};
            this.scroll();
        },

        useSuggestion(event: Event) {
            const btn = event.currentTarget as HTMLButtonElement | null;
            const text = btn?.dataset.text || '';
            if (!text) {
                return;
            }
            this.draft = text;
            this.$nextTick(() => (this.$refs.input as HTMLTextAreaElement | undefined)?.focus());
        },

        scroll() {
            this.$nextTick(() => {
                const el = this.$refs.thread as HTMLElement | undefined;
                if (el) {
                    el.scrollTop = el.scrollHeight;
                }
            });
        },

        async submit(event: Event) {
            event.preventDefault();
            const text = this.draft.trim();
            if (!text || this.sending || !this.streamUrl) {
                return;
            }

            this.sending = true;
            this.status = this.labels.thinking || '';
            this.draft = '';
            this.timeline.push({ type: 'user', text, time: nowTime() });
            this.timeline.push({ type: 'reasoning', open: true, steps: [] });
            const reasonIdx = this.timeline.length - 1;
            this.timeline.push({
                type: 'assistant',
                text: '',
                streaming: true,
                time: nowTime(),
                messageId: null,
                feedback: null,
            });
            const assistantIdx = this.timeline.length - 1;
            this.scroll();

            const body = new URLSearchParams();
            body.set('_csrf', csrf());
            body.set('message', text);
            if (this.conversationId) {
                body.set('conversation_id', this.conversationId);
            }

            try {
                const res = await fetch(this.streamUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'text/event-stream',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf(),
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: body.toString(),
                });

                if (!res.ok || !res.body) {
                    throw new Error(this.labels.error || 'Stream failed');
                }

                const reader = res.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();
                    if (done) {
                        break;
                    }
                    buffer += decoder.decode(value, { stream: true });
                    const chunks = buffer.split('\n\n');
                    buffer = chunks.pop() || '';
                    for (const chunk of chunks) {
                        const line = chunk.split('\n').find((l) => l.startsWith('data:'));
                        if (!line) {
                            continue;
                        }
                        const raw = line.slice(5).trim();
                        if (!raw) {
                            continue;
                        }
                        let event: Record<string, unknown>;
                        try {
                            event = JSON.parse(raw) as Record<string, unknown>;
                        } catch {
                            continue;
                        }
                        this.onEvent(event, reasonIdx, assistantIdx);
                        this.scroll();
                    }
                }
            } catch (err) {
                const msg = err instanceof Error ? err.message : String(err);
                const item = this.timeline[assistantIdx];
                if (item && item.type === 'assistant' && !item.text) {
                    item.text = msg;
                }
                window.gereq?.toast?.(msg, 'error');
            } finally {
                const item = this.timeline[assistantIdx];
                if (item && item.type === 'assistant') {
                    item.streaming = false;
                }
                const reason = this.timeline[reasonIdx];
                if (reason && reason.type === 'reasoning' && reason.steps.length === 0) {
                    // reasoning block empty — drop it (indexes after splice shift assistant)
                    const a = this.timeline[assistantIdx];
                    this.timeline.splice(reasonIdx, 1);
                    // assistant may have moved left by 1
                    const still = this.timeline.find((t) => t === a);
                    if (still && still.type === 'assistant') {
                        still.streaming = false;
                    }
                }
                this.sending = false;
                this.status = '';
                this.scroll();
            }
        },

        onEvent(event: Record<string, unknown>, reasonIdx: number, assistantIdx: number) {
            const type = String(event.type || '');
            if (type === 'status') {
                const key = String(event.message || '');
                this.status =
                    key === 'rag'
                        ? this.labels.rag || ''
                        : key === 'generating'
                          ? this.labels.generating || ''
                          : this.labels.thinking || '';
                return;
            }
            if (type === 'tool') {
                let reason = this.timeline[reasonIdx];
                if (!reason || reason.type !== 'reasoning') {
                    // find last reasoning
                    for (let i = this.timeline.length - 1; i >= 0; i--) {
                        if (this.timeline[i].type === 'reasoning') {
                            reason = this.timeline[i];
                            break;
                        }
                    }
                }
                if (reason && reason.type === 'reasoning') {
                    reason.steps.push({
                        kind: 'tool_call',
                        name: String(event.name || 'tool'),
                        ok: Boolean(event.ok),
                        duration_ms: Number(event.duration_ms || 0),
                        args: event.args,
                    });
                    reason.open = true;
                }
                this.status = this.labels.tool || '';
                return;
            }
            if (type === 'delta') {
                const item = this.timeline[assistantIdx] || this.timeline[this.timeline.length - 1];
                if (item && item.type === 'assistant') {
                    item.text += String(event.text || '');
                    item.streaming = true;
                }
                this.status = '';
                return;
            }
            if (type === 'error') {
                const item = this.timeline[assistantIdx];
                if (item && item.type === 'assistant' && !item.text) {
                    item.text = String(event.message || this.labels.error || 'Error');
                }
                window.gereq?.toast?.(String(event.message || 'Error'), 'error');
                return;
            }
            if (type === 'done') {
                if (typeof event.conversation_id === 'string' && event.conversation_id) {
                    this.conversationId = event.conversation_id;
                    try {
                        const url = new URL(this.chatUrl || window.location.href, window.location.origin);
                        url.searchParams.set('conversation_id', event.conversation_id);
                        window.history.replaceState({}, '', url.toString());
                    } catch {
                        /* ignore */
                    }
                }
                const item = this.timeline[assistantIdx];
                if (item && item.type === 'assistant') {
                    item.streaming = false;
                    if (!item.text && typeof event.message === 'string') {
                        item.text = event.message;
                    }
                    if (typeof event.message_id === 'string' && event.message_id) {
                        item.messageId = event.message_id;
                    }
                }
            }
        },

        async sendFeedback(event: Event) {
            const btn = event.currentTarget as HTMLButtonElement | null;
            const rating = btn?.dataset.rating || '';
            const idx = Number(btn?.dataset.idx ?? -1);
            if (!this.feedbackUrl || !this.conversationId || (rating !== 'positive' && rating !== 'negative')) {
                return;
            }
            const item = this.timeline[idx];
            if (!item || item.type !== 'assistant' || !item.messageId || item.streaming) {
                return;
            }
            if (item.feedback === rating) {
                return;
            }

            const body = new URLSearchParams();
            body.set('_csrf', csrf());
            body.set('conversation_id', this.conversationId);
            body.set('message_id', item.messageId);
            body.set('rating', rating);

            try {
                const res = await fetch(this.feedbackUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN': csrf(),
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    },
                    body: body.toString(),
                });
                const data = (await res.json()) as { ok?: boolean; learned?: boolean; error?: string };
                if (!res.ok || !data.ok) {
                    throw new Error(data.error || this.labels.feedbackError || 'Feedback failed');
                }
                item.feedback = rating;
                const msg =
                    rating === 'positive' && data.learned
                        ? this.labels.feedbackLearned || this.labels.feedbackThanks || ''
                        : this.labels.feedbackThanks || '';
                if (msg) {
                    window.gereq?.toast?.(msg, 'success');
                }
            } catch (err) {
                const msg = err instanceof Error ? err.message : String(err);
                window.gereq?.toast?.(msg, 'error');
            }
        },
    }));
}
