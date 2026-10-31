import { expect, test } from 'bun:test';
import { createDomainDraft, domainFromName } from './onboarding/domain.js';
test('derives valid address labels from Czech business names', () => {
  expect(domainFromName('Žluťoučký kůň & spol.')).toBe('zlutoucky-kun-spol');
  expect(domainFromName('123 Studio')).toBe('web-123-studio');
  expect(domainFromName('AI')).toBe('ai-web');
  expect(domainFromName('')).toBe('');
  expect(domainFromName('A'.repeat(100)).length).toBe(39);
});
test('follows name edits until the domain is manually changed, and can return to automatic mode', () => {
  const draft = createDomainDraft();
  expect(draft.fromName('Studio Lípa')).toBe('studio-lipa');
  expect(draft.fromName('Studio Nádech')).toBe('studio-nadech');
  draft.edit();
  expect(draft.fromName('Another company')).toBeNull();
  expect(draft.reset('Studio Lípa')).toBe('studio-lipa');
  expect(draft.fromName('Studio Voda')).toBe('studio-voda');
});
