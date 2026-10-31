/** Resolve page and entity variables together without expanding substituted data again. */
export function bindTemplateVariables(template: string, item: Record<string, unknown>, variables: Record<string, string>, html = false): string {
  return template.replace(/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/g, (match, path: string) => {
    let value: unknown;
    if (path.startsWith('item.')) {
      value = item;
      for (const key of path.slice(5).split('.')) value = value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : null;
    } else if (Object.prototype.hasOwnProperty.call(variables, path)) value = variables[path];
    else return match;
    const text = typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? String(value) : '';
    return html ? text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;') : text;
  });
}
