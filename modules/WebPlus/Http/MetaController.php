<?php

declare(strict_types=1);

namespace Modules\WebPlus\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Setting\Settings;
use Modules\Cms\Domain\Page;
use Gereq\Media\Domain\MediaAsset;
use Modules\WebPlus\Application\RouteMappingCatalog;
use Modules\WebPlus\Application\WebPlusProvider;
use Modules\WebPlus\Domain\WebMeta;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class MetaController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly WebPlusProvider $webplus,
        private readonly RouteMappingCatalog $mappings,
        private readonly CapabilityRegistry $capabilities,
        private readonly FileManager $files,
        private readonly Settings $settings,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $qb = $this->em->getRepository(WebMeta::class)->createQueryBuilder('m')
            ->orderBy('m.path', 'ASC');
        self_repair_search($qb, 'm', ['path', 'seoTitle', 'ogTitle'], $search);

        /** @var list<WebMeta> $items */
        $items = $qb->getQuery()->getResult();
        $coverage = [];
        foreach ($items as $item) {
            $coverage[$item->id()] = $this->mappings->cover($item->path());
        }

        return $this->page('@webplus/metas/index.html.twig', [
            'pageTitle' => $this->translator->trans('webplus.metas.title'),
            'items' => $items,
            'search' => $search,
            'coverage' => $coverage,
        ]);
    }

    public function mapping(): Response
    {
        return $this->page('@webplus/metas/mapping.html.twig', [
            'pageTitle' => $this->translator->trans('webplus.mapping.title'),
            'mappings' => $this->mappings->all(),
            'discovered' => $this->mappings->discoveredFromUrls(),
        ]);
    }

    public function ensureTemplates(): Response
    {
        $created = $this->mappings->ensureTemplates();
        $this->flash(
            'success',
            $this->translator->trans('webplus.flash.templates_ensured', ['%count%' => $created]),
        );

        return $this->redirect('admin.webplus.metas.mapping');
    }

    public function autoMap(): Response
    {
        $result = $this->mappings->autoMapFromUrls();
        $this->auditor->log('webplus.mapping.auto', 'webplus_meta', null, null, $result);
        $this->flash(
            'success',
            $this->translator->trans('webplus.flash.auto_mapped', [
                '%created%' => $result['created'],
                '%skipped%' => $result['skipped'],
            ]),
        );

        return $this->redirect('admin.webplus.metas.mapping');
    }

    public function create(Request $request): Response
    {
        $path = trim((string) $request->query->get('path', ''));
        $pageId = trim((string) $request->query->get('cms_page_id', ''));

        return $this->formPage(null, $path, $pageId);
    }

    public function forPage(string $pageId): Response
    {
        $existing = $this->webplus->findByCmsPageId($pageId);
        if ($existing !== null) {
            return $this->redirect('admin.webplus.metas.edit', ['id' => $existing->id()]);
        }

        $path = '';
        if (class_exists(Page::class)) {
            $page = $this->em->find(Page::class, $pageId);
            if ($page instanceof Page) {
                $path = '/' . $page->slug();
            }
        }

        return $this->redirect('admin.webplus.metas.create', [
            'cms_page_id' => $pageId,
            'path' => $path,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($this->webplus->findByPath($data['path']) instanceof WebMeta) {
            $this->abort(422, $this->translator->trans('webplus.error.path_taken'));
        }

        $ogImage = $this->resolveImageUrl(
            $request,
            'og_image_file',
            'og_media_asset_id',
            'clear_og_image',
            $data['og_image_url'],
            null,
            'admin.webplus.metas.create',
        );
        if ($ogImage instanceof Response) {
            return $ogImage;
        }

        $twitterImage = $this->resolveImageUrl(
            $request,
            'twitter_image_file',
            'twitter_media_asset_id',
            'clear_twitter_image',
            $data['twitter_image_url'],
            null,
            'admin.webplus.metas.create',
        );
        if ($twitterImage instanceof Response) {
            return $twitterImage;
        }

        $meta = new WebMeta($data['path']);
        $data['og_image_url'] = $ogImage;
        $data['twitter_image_url'] = $twitterImage;
        $this->applyData($meta, $data);
        $this->em->persist($meta);
        $this->em->flush();

        $payload = $this->webplus->serialize($meta);
        $this->events->emit('webplus.meta.created', ['type' => 'webplus_meta', 'id' => $meta->id()], $payload);
        $this->auditor->log('webplus.meta.created', 'webplus_meta', $meta->id(), null, [
            'path' => $meta->path(),
        ]);
        $this->forgetForm('webplus.meta.save');
        $this->flash('success', $this->translator->trans('webplus.flash.meta_created', ['%path%' => $meta->path()]));

        return $this->redirect('admin.webplus.metas.index');
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('webplus.error.not_found'));
        }

        return $this->formPage($item);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('webplus.error.not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->webplus->findByPath($data['path']);
        if ($dup instanceof WebMeta && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('webplus.error.path_taken'));
        }

        $ogImage = $this->resolveImageUrl(
            $request,
            'og_image_file',
            'og_media_asset_id',
            'clear_og_image',
            $data['og_image_url'],
            $item->ogImageUrl(),
            'admin.webplus.metas.edit',
            ['id' => $item->id()],
        );
        if ($ogImage instanceof Response) {
            return $ogImage;
        }

        $twitterImage = $this->resolveImageUrl(
            $request,
            'twitter_image_file',
            'twitter_media_asset_id',
            'clear_twitter_image',
            $data['twitter_image_url'],
            $item->twitterImageUrl(),
            'admin.webplus.metas.edit',
            ['id' => $item->id()],
        );
        if ($twitterImage instanceof Response) {
            return $twitterImage;
        }

        $old = ['path' => $item->path()];
        $item->setPath($data['path']);
        $data['og_image_url'] = $ogImage;
        $data['twitter_image_url'] = $twitterImage;
        $this->applyData($item, $data);
        $this->em->flush();

        $payload = $this->webplus->serialize($item);
        $this->events->emit('webplus.meta.updated', ['type' => 'webplus_meta', 'id' => $item->id()], $payload);
        $this->auditor->log('webplus.meta.updated', 'webplus_meta', $item->id(), $old, [
            'path' => $item->path(),
        ]);
        $this->forgetForm('webplus.meta.save');
        $this->flash('success', $this->translator->trans('webplus.flash.meta_saved'));

        return $this->redirect('admin.webplus.metas.index');
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('webplus.error.not_found'));
        }

        $path = $item->path();
        $payload = $this->webplus->serialize($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('webplus.meta.deleted', ['type' => 'webplus_meta', 'id' => $id], $payload);
        $this->auditor->log('webplus.meta.deleted', 'webplus_meta', $id);
        $this->flash('success', $this->translator->trans('webplus.flash.meta_deleted', ['%path%' => $path]));

        return $this->redirect('admin.webplus.metas.index');
    }

    private function find(string $id): ?WebMeta
    {
        return $this->webplus->find($id);
    }

    private function formPage(?WebMeta $item, string $presetPath = '', string $presetPageId = ''): Response
    {
        $samplePath = '';
        if ($item !== null && $item->isPathTemplate()) {
            $samplePath = str_replace(
                ['{slug}', '{page}', '{x}', '{y}', '{id}'],
                ['demo-slug', 'stranka', 'x', 'y', '1'],
                $item->path(),
            );
        } elseif ($presetPath !== '' && str_contains($presetPath, '{')) {
            $samplePath = str_replace(
                ['{slug}', '{page}', '{x}', '{y}', '{id}'],
                ['demo-slug', 'stranka', 'x', 'y', '1'],
                $presetPath,
            );
        }

        return $this->page('@webplus/metas/form.html.twig', [
            'pageTitle' => $item
                ? $this->translator->trans('webplus.metas.edit')
                : $this->translator->trans('webplus.metas.new'),
            'item' => $item,
            'presetPath' => $presetPath,
            'presetPageId' => $presetPageId,
            'jsonLdJson' => $item !== null && $item->jsonLd() !== null
                ? (json_encode($item->jsonLd(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '')
                : '',
            'cmsPages' => $this->cmsPages(),
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryImages(),
            'siteName' => (string) $this->settings->get('webplus.site_name', ''),
            'defaultOgImage' => (string) $this->settings->get('webplus.default_og_image', ''),
            'samplePath' => $samplePath,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'webplus.meta.save');
        $jsonLd = null;
        $rawJson = trim((string) ($data['json_ld_json'] ?? $request->request->get('json_ld_json', '')));
        if ($rawJson !== '') {
            try {
                $decoded = json_decode($rawJson, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($decoded)) {
                    $this->abort(422, $this->translator->trans('webplus.error.json_ld_invalid'));
                }
                $jsonLd = $decoded;
            } catch (\JsonException) {
                $this->abort(422, $this->translator->trans('webplus.error.json_ld_invalid'));
            }
        }

        return [
            'path' => WebMeta::normalizePath((string) $data['path']),
            'cms_page_id' => isset($data['cms_page_id']) ? trim((string) $data['cms_page_id']) : '',
            'active' => $this->boolish($data['active'] ?? $request->request->get('active'), true),
            'seo_title' => $this->nullableString($data['seo_title'] ?? null),
            'seo_description' => $this->nullableString($data['seo_description'] ?? null),
            'canonical_url' => $this->nullableString($data['canonical_url'] ?? null),
            'robots' => (string) ($data['robots'] ?? 'index,follow'),
            'keywords' => $this->nullableString($data['keywords'] ?? null),
            'geo_region' => $this->nullableString($data['geo_region'] ?? null),
            'geo_placename' => $this->nullableString($data['geo_placename'] ?? null),
            'geo_position' => $this->nullableString($data['geo_position'] ?? null),
            'ai_summary' => $this->nullableString($data['ai_summary'] ?? null),
            'json_ld' => $jsonLd,
            'og_title' => $this->nullableString($data['og_title'] ?? null),
            'og_description' => $this->nullableString($data['og_description'] ?? null),
            'og_image_url' => $this->nullableString($data['og_image_url'] ?? null),
            'og_type' => (string) ($data['og_type'] ?? 'website'),
            'og_url' => $this->nullableString($data['og_url'] ?? null),
            'og_locale' => $this->nullableString($data['og_locale'] ?? null),
            'twitter_card' => (string) ($data['twitter_card'] ?? 'summary_large_image'),
            'twitter_title' => $this->nullableString($data['twitter_title'] ?? null),
            'twitter_description' => $this->nullableString($data['twitter_description'] ?? null),
            'twitter_image_url' => $this->nullableString($data['twitter_image_url'] ?? null),
            'sitemap_include' => $this->boolish($data['sitemap_include'] ?? $request->request->get('sitemap_include'), true),
            'sitemap_priority' => $this->nullableString($data['sitemap_priority'] ?? null) ?? '0.5',
            'sitemap_changefreq' => $this->nullableString($data['sitemap_changefreq'] ?? null) ?? 'weekly',
        ];
    }

    /** @param array<string, mixed> $data */
    private function applyData(WebMeta $meta, array $data): void
    {
        $meta->setCmsPageId($data['cms_page_id'] !== '' ? $data['cms_page_id'] : null);
        $meta->setActive((bool) $data['active']);
        $meta->setSeoTitle($data['seo_title']);
        $meta->setSeoDescription($data['seo_description']);
        $meta->setCanonicalUrl($data['canonical_url']);
        $meta->setRobots($data['robots']);
        $meta->setKeywords($data['keywords']);
        $meta->setGeoRegion($data['geo_region']);
        $meta->setGeoPlacename($data['geo_placename']);
        $meta->setGeoPosition($data['geo_position']);
        $meta->setAiSummary($data['ai_summary']);
        $meta->setJsonLd($data['json_ld']);
        $meta->setOgTitle($data['og_title']);
        $meta->setOgDescription($data['og_description']);
        $meta->setOgImageUrl($data['og_image_url']);
        $meta->setOgType($data['og_type']);
        $meta->setOgUrl($data['og_url']);
        $meta->setOgLocale($data['og_locale']);
        $meta->setTwitterCard($data['twitter_card']);
        $meta->setTwitterTitle($data['twitter_title']);
        $meta->setTwitterDescription($data['twitter_description']);
        $meta->setTwitterImageUrl($data['twitter_image_url']);
        $meta->setSitemapInclude((bool) $data['sitemap_include']);
        $meta->setSitemapPriority($data['sitemap_priority']);
        $meta->setSitemapChangefreq($data['sitemap_changefreq']);
    }

    /**
     * @param array<string, string> $redirectParams
     */
    private function resolveImageUrl(
        Request $request,
        string $fileField,
        string $libraryField,
        string $clearField,
        ?string $postedUrl,
        ?string $current,
        string $redirectRoute,
        array $redirectParams = [],
    ): Response|string|null {
        if ($request->request->get($clearField) === '1' || $request->request->get($clearField) === 'on') {
            return null;
        }

        $uploaded = $request->files->get($fileField);
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('webplus.error.media_required'));

                return $this->redirect($redirectRoute, $redirectParams);
            }

            $mime = (string) ($uploaded->getMimeType() ?: '');
            if (!str_starts_with($mime, 'image/')) {
                $this->flash('error', $this->translator->trans('webplus.error.not_image'));

                return $this->redirect($redirectRoute, $redirectParams);
            }

            try {
                $stored = $this->files->store($uploaded, $this->auth->user());
            } catch (\Throwable $e) {
                $this->flash(
                    'error',
                    $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('webplus.error.file_upload'),
                );

                return $this->redirect($redirectRoute, $redirectParams);
            }

            $title = $stored->originalName();
            [$width, $height] = $this->imageDimensions($uploaded, $stored);
            $asset = new MediaAsset(
                $title,
                $stored->path(),
                $stored->mime(),
                $stored->size(),
                $this->auth->user()?->id(),
                null,
                $width,
                $height,
            );
            $this->em->persist($asset);
            $this->em->flush();

            return $this->publicMediaUrl($asset->id());
        }

        $mediaId = trim((string) $request->request->get($libraryField, ''));
        if ($mediaId !== '') {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('webplus.error.media_required'));

                return $this->redirect($redirectRoute, $redirectParams);
            }

            $asset = $this->em->find(MediaAsset::class, $mediaId);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $this->flash('error', $this->translator->trans('webplus.error.media_not_found'));

                return $this->redirect($redirectRoute, $redirectParams);
            }

            return $this->publicMediaUrl($asset->id());
        }

        if ($postedUrl !== null) {
            return $postedUrl;
        }

        return $current;
    }

    private function publicMediaUrl(string $assetId): string
    {
        return '/media/' . $assetId . '/file';
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    /** @return list<array{value: string, label: string}> */
    private function libraryImages(): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }

        $provider = $this->capabilities->get('media');
        if (!is_object($provider) || !method_exists($provider, 'images')) {
            return [];
        }

        $options = [];
        foreach ($provider->images(100) as $asset) {
            if (!$asset instanceof MediaAsset) {
                continue;
            }
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title() . ' (' . $asset->mime() . ')',
            ];
        }

        return $options;
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function imageDimensions(UploadedFile $uploaded, \Gereq\File\StoredFile $stored): array
    {
        if (!str_starts_with($stored->mime(), 'image/')) {
            return [null, null];
        }

        $meta = $stored->metadata();
        if (is_array($meta) && isset($meta['width'], $meta['height'])) {
            return [(int) $meta['width'], (int) $meta['height']];
        }

        $info = @getimagesize($uploaded->getPathname());
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }

    /** @return list<array{id: string, label: string}> */
    private function cmsPages(): array
    {
        if (!$this->capabilities->has('cms') && !class_exists(Page::class)) {
            return [];
        }
        try {
            /** @var list<Page> $pages */
            $pages = $this->em->getRepository(Page::class)->findBy([], ['title' => 'ASC'], 500);
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($pages as $page) {
            $out[] = [
                'id' => $page->id(),
                'label' => $page->title() . ' (/' . $page->slug() . ')',
            ];
        }

        return $out;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function boolish(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
