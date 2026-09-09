import DOMPurify from 'dompurify';
import { marked } from 'marked';

marked.setOptions({
    gfm: true,
    breaks: true,
});

const ALLOWED_TAGS = [
    'p',
    'br',
    'strong',
    'em',
    'b',
    'i',
    'ul',
    'ol',
    'li',
    'code',
    'pre',
    'h1',
    'h2',
    'h3',
    'h4',
    'a',
    'blockquote',
    'hr',
    'table',
    'thead',
    'tbody',
    'tr',
    'th',
    'td',
    'del',
];

let hooksReady = false;

function ensureHooks(): void {
    if (hooksReady || typeof window === 'undefined') {
        return;
    }
    hooksReady = true;
    DOMPurify.addHook('afterSanitizeAttributes', (node) => {
        if (!(node instanceof Element) || node.tagName !== 'A') {
            return;
        }
        const href = node.getAttribute('href') || '';
        if (!/^(https?:|mailto:)/i.test(href)) {
            node.removeAttribute('href');
        }
        node.setAttribute('target', '_blank');
        node.setAttribute('rel', 'noopener noreferrer');
    });
}

/** Safe Markdown → HTML for assistant chat bubbles. */
export function renderAiMarkdown(source: string): string {
    if (!source) {
        return '';
    }
    ensureHooks();
    const html = marked.parse(source, { async: false }) as string;
    return DOMPurify.sanitize(html, {
        ALLOWED_TAGS,
        ALLOWED_ATTR: ['href', 'title', 'target', 'rel'],
        ALLOW_DATA_ATTR: false,
    });
}
