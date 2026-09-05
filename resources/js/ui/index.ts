import type Alpine from '@alpinejs/csp';
import { registerAres } from './ares';
import { registerCmsBlockForm } from './cmsBlockForm';
import { registerPassword, registerSwitch, registerTextarea } from './controls';
import { registerEditor } from './editor';
import { registerForm } from './form';
import { registerModuleTable } from './moduleTable';
import { registerModal, registerPopover } from './overlay';
import { registerSelect } from './select';
import { registerTabs } from './tabs';
import { registerSettingsBrandPreview } from './settingsBrandPreview';
import { registerColorField } from './colorField';
import { registerBrandAsset } from './brandAsset';
import { registerCssEditor } from './cssEditor';
import { registerDashboardSettings } from './dashboardSettings';
import { registerChart } from './chart';

export function registerUi(alpine: typeof Alpine): void {
    registerSelect(alpine);
    registerModal(alpine);
    registerPopover(alpine);
    registerPassword(alpine);
    registerSwitch(alpine);
    registerTextarea(alpine);
    registerForm(alpine);
    registerEditor(alpine);
    registerTabs(alpine);
    registerSettingsBrandPreview(alpine);
    registerColorField(alpine);
    registerBrandAsset(alpine);
    registerCssEditor(alpine);
    registerDashboardSettings(alpine);
    registerChart(alpine);
    registerAres(alpine);
    registerCmsBlockForm(alpine);
    registerModuleTable(alpine);
}
