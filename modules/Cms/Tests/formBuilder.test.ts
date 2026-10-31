import { expect, test } from 'bun:test';
import { registerCmsBuilder } from '../Resources/assets/js/cmsBuilder';

function builder(): any {
  let factory: (() => unknown) | undefined;
  registerCmsBuilder({ data(_name: string, callback: () => unknown) { factory = callback; } } as never);
  const state = factory!() as any;
  state.queuePreviewState = () => {};
  state.boot.formActions = [{ id: 'inquiry', label: 'Inquiry', fields: { name: 'text', email: 'email', phone: 'tel', details: 'textarea' } }];
  return state;
}

test('builds a complete configured inquiry form using admin operations only', () => {
  const state = builder();
  state.addBlock('form');
  const form = state.blocks[0];
  state.onFormActionChange({ target: { value: 'inquiry' } });
  expect(form.payload.action).toBe('inquiry');
  expect(form.payload.children.map((child: any) => child.payload.name)).toEqual(['name', 'email', 'phone', 'details']);
  expect(form.payload.children.every((child: any) => child.payload.required === 1)).toBe(true);
  expect(form.payload.children[3].payload.full).toBe(1);
  state.addFormField();
  expect(form.payload.children).toHaveLength(5);
  const firstName = form.payload.children[4].payload.name;
  state.selectedId = form.id;
  state.addFormField();
  expect(form.payload.children[5].payload.name).not.toBe(firstName);
  expect(state.layerRows()).toHaveLength(7);
});

test('changing action preserves existing editor fields and submitted configuration', () => {
  const state = builder();
  state.addBlock('form');
  state.onFormActionChange({ target: { value: 'inquiry' } });
  const form = state.blocks[0];
  form.payload.children[0].payload.label = 'Your name';
  state.onFormActionChange({ target: { value: 'inquiry' } });
  expect(form.payload.children[0].payload.label).toBe('Your name');
  expect(form.payload.children).toHaveLength(4);
});

test('form containers reject nested forms and unsupported runtime blocks', () => {
  const state = builder();
  state.boot.blockTypes = ['form', 'form_field', 'layout', 'widget', 'text'].map(value => ({ value }));
  state.addBlock('form');
  const form = state.blocks[0];
  expect(state.availableBlockTypes.map((entry: any) => entry.value)).toEqual(['form_field', 'layout', 'text']);
  state.insertBlockNear('form', form.id);
  state.insertBlockNear('widget', form.id);
  expect(form.payload.children).toHaveLength(0);
  state.insertBlockNear('layout', form.id);
  state.insertBlockNear('form_field', form.payload.children[0].id);
  expect(form.payload.children[0].payload.children).toHaveLength(1);
});

test('failed saves show field validation messages and preserve the unfinished form', async () => {
  const state = builder();
  state.boot.saveUrl = '/save';
  state.boot.csrf = 'token';
  state.addBlock('form');
  const form = state.blocks[0];
  const oldFetch = globalThis.fetch;
  const oldWindow = globalThis.window;
  const notices: string[] = [];
  (globalThis as any).window = { gereq: { toast: (message: string) => notices.push(message) } };
  globalThis.fetch = (async () => Response.json({ error: { message: 'Validation failed', fields: { form: 'Nested forms are not supported.' } } }, { status: 422 })) as any;
  try {
    await state.save();
    expect(state.status).toBe('Nested forms are not supported.');
    expect(notices).toEqual(['Nested forms are not supported.']);
    expect(state.blocks[0]).toBe(form);
    expect(state.dirty).toBe(true);
  } finally {
    globalThis.fetch = oldFetch;
    (globalThis as any).window = oldWindow;
  }
});

test('required-field injection preserves existing fields and repairs nested matches without duplication', () => {
  const state = builder();
  state.addBlock('form');
  const form = state.blocks[0];
  form.payload.action = 'inquiry';
  const email = { id: 'email', type: 'form_field', payload: { name: 'email', label: 'Your email', control: 'text', required: 0, full: 1, text_color: 'brand' } };
  const extra = { id: 'custom', type: 'form_field', payload: { name: 'custom_note', label: 'Keep me', control: 'textarea', required: 0 } };
  form.payload.children = [{ id: 'layout', type: 'layout', payload: { children: [email] } }, extra];
  expect(state.formNeedsRequiredFields).toBe(true);
  state.injectRequiredFormFields();
  expect(email.payload).toEqual({ name: 'email', label: 'Your email', control: 'email', required: 1, full: 1, text_color: 'brand' });
  expect(form.payload.children[1]).toBe(extra);
  expect(form.payload.children.slice(2).map((field: any) => field.payload.name)).toEqual(['name', 'phone', 'details']);
  expect(state.formRequiredFields.every((field: any) => field.ready)).toBe(true);
  const snapshot = JSON.stringify(form.payload);
  state.injectRequiredFormFields();
  expect(JSON.stringify(form.payload)).toBe(snapshot);
});

test('injection adds required fields to a populated form with generated names', () => {
  const state = builder();
  state.addBlock('form');
  const form = state.blocks[0];
  form.payload.children = [{ id: 'custom', type: 'form_field', payload: { name: 'field_custom', control: 'email', label: 'Existing email', required: 1 } }];
  state.onFormActionChange({ target: { value: 'inquiry' } });
  expect(form.payload.children).toHaveLength(1);
  state.injectRequiredFormFields();
  expect(form.payload.children.map((field: any) => field.payload.name)).toEqual(['field_custom', 'name', 'email', 'phone', 'details']);
  expect(form.payload.children[0].payload.label).toBe('Existing email');
});

test('duplicate required names are flagged and preserved for an editor decision', () => {
  const state = builder();
  state.addBlock('form');
  state.onFormActionChange({ target: { value: 'inquiry' } });
  const form = state.blocks[0];
  form.payload.children.push({ ...form.payload.children[0], id: 'duplicate' });
  expect(state.formRequiredFields[0].duplicate).toBe(true);
  const before = JSON.stringify(form.payload);
  state.injectRequiredFormFields();
  expect(JSON.stringify(form.payload)).toBe(before);
  expect(state.formNeedsRequiredFields).toBe(true);
});

test('reading the required-field checklist does not mutate builder state', () => {
  const state = builder();
  state.addBlock('form');
  state.onFormActionChange({ target: { value: 'inquiry' } });
  const payload = state.blocks[0].payload;
  const children = payload.children;
  expect(state.formRequiredFields).toHaveLength(4);
  expect(state.formNeedsRequiredFields).toBe(false);
  expect(state.blocks[0].payload).toBe(payload);
  expect(state.blocks[0].payload.children).toBe(children);
});

test('mapping reuses an existing field during required-field injection', () => {
  const state = builder(); state.addBlock('form');
  const form = state.selected;
  form.payload.action = 'inquiry';
  form.payload.children = [{ id: 'existing', type: 'form_field', payload: { name: 'contact', label: 'Contact', control: 'email', required: 1 } }];
  state.setFormMapping('email', { target: { value: 'contact' } });
  state.injectRequiredFormFields();
  expect(state.formRequiredFields.every((field: any) => field.ready)).toBe(true);
  expect(form.payload.children).toHaveLength(4);
  expect(form.payload.children.filter((field: any) => field.payload.name === 'email')).toHaveLength(0);
  expect(form.payload.children[0].payload.label).toBe('Contact');
});

test('form fields can be reordered without changing form selection', () => {
  const state = builder(); state.addBlock('form'); state.onFormActionChange({ target: { value: 'inquiry' } });
  const form = state.selected; const email = state.formFields[1];
  state.moveFormField(email.id, -1);
  expect(state.formFields[0].id).toBe(email.id);
  expect(state.selectedId).toBe(form.id);
});

test('structured options preserve editable rows and support removal', () => {
  const state = builder(); state.addBlock('form'); state.addFormField();
  state.selected.payload.control = 'select'; state.selected.payload.options = 'First';
  state.addFormOption(); state.updateFormOption(1, { target: { value: 'Second' } });
  expect(state.formOptionRows.map((row: any) => row.value)).toEqual(['First', 'Second']);
  state.removeFormOption(0);
  expect(state.selected.payload.options).toBe('Second');
});

test('backend test highlights affected fields and shows one general message', () => {
  const state = builder(); state.addBlock('form'); state.onFormActionChange({ target: { value: 'inquiry' } });
  state.boot.labels.formTestInvalid = 'Please check the highlighted fields.';
  state.applyFormTestResult({ error: { message: 'Check the form fields.', fields: { name: 'Invalid', email: 'Invalid' } } });
  expect(state.formTestErrors).toEqual({ name: true, email: true });
  expect(state.formTestResult).toBe('Please check the highlighted fields.');
  state.setFormTestValue('name', { target: { value: 'Name' } });
  expect(state.formTestErrors).toEqual({ email: true });
  expect(state.formTestResult).toBe('Please check the highlighted fields.');
  state.applyFormTestResult({ data: { message: 'Passed' } });
  expect(state.formTestErrors).toEqual({});
  expect(state.formTestResult).toBe('Passed');
});

test('backend test keeps security errors as a single message without field highlights', () => {
  const state = builder(); state.addBlock('form');
  state.applyFormTestResult({ error: { message: 'Security check failed.' } });
  expect(state.formTestErrors).toEqual({});
  expect(state.formTestResult).toBe('Security check failed.');
});

test('submission links scope saved forms to their page and hide unsaved forms', () => {
  const state = builder(); state.addBlock('form');
  state.boot.formAttemptsUrl = '/admin/cms/forms/attempts'; state.boot.slug = 'contact us';
  expect(state.formSubmissionsUrl).toBe('');
  state.selected.id = 'saved-form'; state.selectedId = 'saved-form';
  const url = new URL(state.formSubmissionsUrl, 'http://localhost');
  expect(url.searchParams.get('page')).toBe('contact us');
  expect(url.searchParams.get('form')).toBe('saved-form');
});

test('required fields use readable translated labels and avoid duplicate mapping choices', () => {
  const state = builder(); state.addBlock('form'); state.onFormActionChange({ target: { value: 'inquiry' } });
  state.boot.labels['formField.phone'] = 'Phone number';
  expect(state.formRequirementLabel('phone')).toBe('Phone number');
  expect(state.formMappingCandidates('phone').some((field: any) => field.payload.name === 'phone')).toBe(false);
  expect(state.formRequirementLabel('company_name')).toBe('Company name');
});

test('one overflow editor switches targets without overwriting other limits', () => {
  const state = builder(); state.addBlock('form'); state.addFormField();
  expect(state.textLimitTargets.map((target: any) => target.value)).toEqual(['label', 'placeholder']);
  expect(state.activeTextLimitField).toBe('label');
  state.onTextLimitInput(state.activeTextLimitField, 'max', { target: { value: '20' } });
  state.changeTextLimitTarget({ target: { value: 'placeholder' } });
  state.onTextLimitInput(state.activeTextLimitField, 'max', { target: { value: '40' } });
  expect(state.textLimitValue('label', 'max')).toBe(20);
  expect(state.textLimitValue('placeholder', 'max')).toBe(40);
  state.changeTextLimitTarget({ target: { value: 'label' } });
  expect(state.textLimitValue(state.activeTextLimitField, 'max')).toBe(20);
});

test('dynamic library is unavailable for forms and nested form children', () => {
  const state = builder(); state.addBlock('form');
  expect(state.formInsertionContext).toBe(true);
  expect(state.canInsertDynamic).toBe(false);
  state.openLibraryTab('dynamic');
  expect(state.createTab).toBe('elements');
  expect(state.modalTab).toBe(null);
  state.addFormField();
  expect(state.canInsertDynamic).toBe(false);
  state.newDynamicTemplate('custom', 'test');
  expect(state.selected.type).toBe('form_field');
  state.selectedId = null; state.insertAfterId = null;
  expect(state.canInsertDynamic).toBe(true);
});

test('diagnoses invalid action, duplicate field names and missing radio options inline', () => {
  const state = builder();
  state.addBlock('form');
  expect(state.formActionMissing).toBe(true);
  state.onFormActionChange({ target: { value: 'inquiry' } });
  expect(state.formActionMissing).toBe(false);
  const fields = state.blocks[0].payload.children;
  state.selectedId = fields[0].id;
  expect(state.formFieldNameInvalid).toBe(false);
  fields[0].payload.name = 'website_url';
  expect(state.formFieldNameInvalid).toBe(true);
  fields[0].payload.name = fields[1].payload.name;
  expect(state.formFieldNameDuplicate).toBe(true);
  fields[0].payload.control = 'radio';
  fields[0].payload.options = '  \n';
  expect(state.formFieldOptionsMissing).toBe(true);
  fields[0].payload.options = 'One\nTwo';
  expect(state.formFieldOptionsMissing).toBe(false);
});

test('configuration failures give a general message and mark affected field settings', () => {
  const state = builder();
  state.boot.labels.formTestConfiguration = 'Review the form action and fields marked as needing setup.';
  state.addBlock('form');
  state.onFormActionChange({ target: { value: 'inquiry' } });
  const field = state.blocks[0].payload.children[0];
  expect(state.formFieldHasIssue(field)).toBe(false);
  field.payload.control = 'radio';
  expect(state.formFieldHasIssue(field)).toBe(true);
  state.applyFormTestResult({ error: { message: 'Configure name, email, phone, details', fields: { form: 'Internal schema details' } } });
  expect(state.formTestResult).toBe(state.boot.labels.formTestConfiguration);
});

test('choice fields hide placeholder settings and its overflow target', () => {
  const state = builder();
  state.addBlock('form_field');
  for (const control of ['radio', 'checkbox', 'consent', 'file']) {
    state.selected.payload.control = control;
    expect(state.formFieldHasPlaceholder).toBe(false);
    expect(state.textLimitTargets.map((target: any) => target.value)).toEqual(['label']);
  }
  state.selected.payload.control = 'text';
  expect(state.formFieldHasPlaceholder).toBe(true);
  expect(state.textLimitTargets.map((target: any) => target.value)).toEqual(['label', 'placeholder']);
});

test('switching forms clears inspector test values and private file selections', () => {
  const state = builder();
  state.addBlock('form');
  const first = state.selectedId;
  state.addBlock('form');
  const second = state.selectedId;
  state.select(first);
  state.formTestValues = { email: 'private@example.test' };
  state.formTestFiles = { attachment: new File(['private'], 'private.pdf') };
  state.formTestErrors = { email: true };
  state.formTestResult = 'Old result';
  state.select(second);
  expect(state.formTestValues).toEqual({});
  expect(state.formTestFiles).toEqual({});
  expect(state.formTestErrors).toEqual({});
  expect(state.formTestResult).toBe('');
});

test('save failures use translated copy without exposing browser error text', async () => {
  const state = builder();
  state.boot.saveUrl = '/save';
  state.boot.csrf = 'token';
  state.boot.labels.saveFailed = 'Změny se nepodařilo uložit.';
  state.addBlock('form');
  const oldFetch = globalThis.fetch;
  const oldWindow = globalThis.window;
  (globalThis as any).window = { gereq: { toast() {} } };
  try {
    for (const fail of [async () => { throw new TypeError('Failed to fetch'); }, async () => Response.json({}, { status: 500 })]) {
      globalThis.fetch = fail as any;
      await state.save();
      expect(state.status).toBe(state.boot.labels.saveFailed);
      expect(state.dirty).toBe(true);
    }
  } finally {
    globalThis.fetch = oldFetch;
    (globalThis as any).window = oldWindow;
  }
});


test('optional block spacing displays zero separately from inherited values', () => {
  const state = builder();
  state.addBlock('quote');
  expect(state.payloadOptionalNum('margin_top')).toBe('');
  state.onOptionalSpacing('margin_top', { target: { value: '0' } }, 256);
  expect(state.payloadOptionalNum('margin_top')).toBe(0);
  state.onOptionalSpacing('margin_top', { target: { value: '24' } }, 256);
  expect(state.payloadOptionalNum('margin_top')).toBe(24);
  state.onOptionalSpacing('margin_top', { target: { value: '' } }, 256);
  expect(state.payloadOptionalNum('margin_top')).toBe('');
});

test('quotes expose one shared text limit for all parts', () => {
  const state = builder();
  state.addBlock('quote');
  expect(state.supportsTextOverflow).toBe(true);
  expect(state.textLimitTargets.map((target: any) => target.value)).toEqual(['all']);
  state.onTextLimitInput('all', 'max', { target: { value: '20' } });
  expect(state.selected.payload.text_limits).toEqual({ all: { max: 20, suffix: '…' } });
  state.setBlockTextOverflow({ target: { value: 'clip' } });
  expect(state.selected.payload.text_overflow).toBe('clip');
});
