import { mountFormSubmissionDates } from './formSubmissionDates';
import type Alpine from '@alpinejs/csp';
import '../css/admin.css';

import { registerCmsBlockForm } from './cmsBlockForm';
import { registerCmsBuilder } from './cmsBuilder';
import { registerCmsCssSettings } from './cmsCssSettings';
import { registerCmsHomePage } from './cmsHomePage';
import { registerCmsPageForm } from './cmsPageForm';
import { registerCmsPagePreview } from './cmsPagePreview';

export default function register(alpine: typeof Alpine): void {
    mountFormSubmissionDates();
    registerCmsBlockForm(alpine);
    registerCmsBuilder(alpine);
    registerCmsCssSettings(alpine);
    registerCmsHomePage(alpine);
    registerCmsPageForm(alpine);
    registerCmsPagePreview(alpine);
}
