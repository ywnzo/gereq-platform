import { expect, test } from 'bun:test';
import { bindTemplateVariables } from '../Resources/assets/js/cmsTemplateVariables';

test('mixed page and entity variables resolve in one pass', () => {
  expect(bindTemplateVariables('{{page.slug}}: {{ item.body }}', { body: 'Review' }, { 'page.slug': 'test' }, true)).toBe('test: Review');
  expect(bindTemplateVariables('{{ page.slug }} {{ item.body }}', { body: '{{page.slug}} <b>data</b>' }, { 'page.slug': '<script>x</script>' }, true)).toBe('&lt;script&gt;x&lt;/script&gt; {{page.slug}} &lt;b&gt;data&lt;/b&gt;');
  expect(bindTemplateVariables('{{ page.unknown }} {{ item.author.name }}', { author: { name: 'Alice' } }, {})).toBe('{{ page.unknown }} Alice');
});
