import type Alpine from '@alpinejs/csp';
import { registerPassword, registerSwitch, registerTextarea } from './controls';
import { registerEditor } from './editor';
import { registerForm } from './form';
import { registerModal, registerPopover } from './overlay';
import { registerSelect } from './select';
import { registerTabs } from './tabs';

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
}
