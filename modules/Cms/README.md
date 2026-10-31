# CMS

GEREQ module `cms` — v2.50.3

## Integration widget

The `widget` block embeds Google Maps, OpenStreetMap, Calendly or Tally using
a provider-specific HTTPS iframe URL, accessible title and height (160–1600 px).
No script snippets are accepted. Unknown hosts and executable URLs are rejected
in both public Vue rendering and SSG. Widgets remain inactive in the editor;
use Preview to interact. No provider account or event is created automatically.

## Avatar and badge blocks

Avatar supports a photo URL, a name and optional explicit initials, size 16–256 px,
and circle or rounded-square shape. Missing or failed photos fall back to initials.
All content fields support dynamic entity variables. Badge supports soft, outline
and solid appearances plus the shared text display limits and design controls.
Both types are available in Add, dynamic templates, the block API and SSG.

## Text display limits

Content fields except headings and HTML blocks expose **Maximum characters** and **Text after truncation**
under the inspector's Content tab. Set the maximum to zero to disable truncation.
Rules are stored per field in `payload.text_limits`; source content stays intact.
The storefront and SSG snapshot apply limits after resolving dynamic values, preserve
HTML tags, and append the suffix only when content exceeds the limit. Inline editing
keeps the full source visible; the dynamic block's eye shows the limited live result.

## Dynamic entity elements

Dynamic blocks are repeating containers connected to an entity source. Choose a source
in **Dynamic templates**, then start with an empty container or a preset. Add supported
blocks directly to the item, including nested layouts, headings, text, images, avatars,
badges and ratings. The Add menu is scoped to supported types; products, form fields
and nested dynamic blocks cannot be inserted into an item. Drag supported blocks into
or out of the container using the canvas or Layers.

The inspector identifies the current entity and lets each child connect its content,
image URL, caption or other supported field to an entity field. Choosing a binding
replaces that block field with `{{ item.property }}`. Ordinary editing remains available
for mixed static text and variables. Source, record limit and grid/list/carousel settings
belong to the outer container. Changing the source preserves the design; update bindings
to match the new entity fields.

Item style controls set padding, background and border colors, border width,
corner radius, shadow and maximum height on every entry independently of the outer container.
Maximum height is in pixels; zero is unlimited. Taller content scrolls automatically using themed scrollbars. Each text-capable block has its own Content setting to wrap normally, clip to one line,
or show a multi-line ellipsis on the last line fitting within the item height. Item scrolling remains a container setting. These choices apply in the editor, preview and public output.
The Content tab groups data/layout and item styling into accordions.
In edit mode all current records remain visible: only the first item is editable,
and the remaining entries are dimmed, read-only copies that update as you edit.
The eye previews the repeated result; the pencil returns to the item design and restores
the selected child. An empty item remains visible and editable even with no source data.
Save the page normally to persist its independent copy through staging/publishing.
Saved library templates insert editable copies; library editing uses its isolated canvas
and updates the existing entry. Saving a library template does not save the page.
Legacy HTML item templates open as editable HTML children.

Modules register sources in `boot()` without a hard dependency on CMS:

```php
if ($context->cms()->isEnabled()) {
    $context->cms()->register(
        'my_entities',
        'my_module.cms.source',
        ['title', 'body'],
        static fn (int $limit): array => $provider->publicItems($limit),
        '<h3>{{ item.title }}</h3><p>{{ item.body }}</p>',
    );
}
```

Loaders must return public serialized arrays and enforce their own publication
visibility. Only declared fields leave the registry. Values are escaped before
interpolation and the resulting HTML is sanitized; Twig expressions, PHP and raw
filters are never executed. Templates support nested array paths. Structured templates are stored as
`item_blocks`; resolved public copies are emitted as `resolved_items`. Page changes
use the existing CMS staging/publishing flow. Entity data is resolved on each
page API read; generated static snapshots need regeneration to reflect new data.
The Reviews module registers approved site reviews as the first source.

Versions: CMS 2.33.22, Reviews 2.1.3. Apply each module's
admin Update before using the new functionality; no schema migration is needed.

## HTML source editor

HTML blocks open in Source editor mode. The block action bar uses the same eye/pencil
toggle as dynamic templates: the eye opens live preview and the pencil returns to the editor. The integrated editor provides undo, redo, find/replace,
HTML completion and go-to-line tools. Its compact controls use the storefront UI
adapter and branding theme; block appearance applies only in Preview and public
rendering. Edits use the normal builder history, save and publish pipeline.
