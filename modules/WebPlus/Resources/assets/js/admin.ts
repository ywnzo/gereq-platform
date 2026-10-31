import type Alpine from '@alpinejs/csp';
import '../css/admin.css';

import { registerWebplusOgPreview } from './ogPreview';

export default function register(alpine: typeof Alpine): void {
    registerWebplusOgPreview(alpine);
}
