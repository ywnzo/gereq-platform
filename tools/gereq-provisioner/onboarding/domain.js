export function domainFromName(name) {
  let slug = name.trim().toLowerCase().replaceAll('ß', 'ss').replaceAll('ł', 'l').replaceAll('ø', 'o')
    .normalize('NFD').replace(/\p{M}/gu, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  if (!slug) return '';
  if (/^[0-9]/.test(slug)) slug = `web-${slug}`;
  if (slug.length < 3) slug += '-web';
  return slug.slice(0, 39).replace(/-+$/, '');
}
export function createDomainDraft() {
  let edited = false;
  return {
    fromName(name) { return edited ? null : domainFromName(name); },
    edit() { edited = true; },
    reset(name) { edited = false; return domainFromName(name); },
  };
}
