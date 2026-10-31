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
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Media\Domain\MediaAsset;
use Modules\WebPlus\Application\WebPlusProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SettingsController extends Controller
{
    /** @var list<string> */
    private const KEYS = [
        'webplus.site_name',
        'webplus.base_url',
        'webplus.frontend_url',
        'webplus.default_og_image',
        'webplus.twitter_site',
        'webplus.default_robots',
        'webplus.default_og_locale',
        'webplus.organization_json_ld',
        'webplus.llms_txt',
        'webplus.robots_txt',
        'webplus.google_feed_enabled',
        'webplus.google_feed_sources',
        'webplus.google_feed_title',
        'webplus.google_feed_description',
        'webplus.google_feed_currency',
        'webplus.google_feed_brand',
        'webplus.google_feed_product_path',
        'webplus.google_feed_listings_path',
        'webplus.cms_pages_path',
        'webplus.google_feed_extra_paths',
        'webplus.google_feed_limit',
        'webplus.legal_controller_name',
        'webplus.legal_controller_address',
        'webplus.legal_controller_ico',
        'webplus.legal_controller_dic',
        'webplus.legal_controller_email',
        'webplus.legal_controller_phone',
        'webplus.privacy_path',
    ];

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Settings $settings,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly WebPlusProvider $webplus,
        private readonly EntityManagerInterface $em,
        private readonly FileManager $files,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function edit(): Response
    {
        $definitions = $this->settings->definitions();
        $feedSources = $this->webplus->googleFeedSources();
        $sourceIds = array_map(static fn (array $s): string => $s['id'], $feedSources);
        $hasProducts = in_array('products', $sourceIds, true);
        $hasListings = in_array('listings', $sourceIds, true);

        $fields = [];
        foreach (self::KEYS as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            $definition = $definitions[$key];
            $value = $this->settings->get($key, $definition['default'] ?? null);
            $field = [
                'key' => $key,
                'field' => str_replace('.', '__', $key),
                'definition' => $definition,
                'value' => $value,
                'textarea' => in_array($key, [
                    'webplus.organization_json_ld',
                    'webplus.llms_txt',
                    'webplus.robots_txt',
                    'webplus.google_feed_description',
                    'webplus.google_feed_extra_paths',
                ], true),
                'wysiwyg' => false,
            ];

            if ($key === 'webplus.google_feed_sources') {
                $field['select'] = $this->feedSourceSelectOptions($feedSources);
                $field['value'] = $this->normalizeFeedSourcesValue((string) $value, $sourceIds);
            }

            $fields[$key] = $field;
        }

        $googleKeys = [
            'webplus.google_feed_enabled',
            'webplus.google_feed_sources',
            'webplus.google_feed_title',
            'webplus.google_feed_description',
            'webplus.google_feed_currency',
            'webplus.google_feed_brand',
        ];
        if ($hasProducts) {
            $googleKeys[] = 'webplus.google_feed_product_path';
        }
        if ($hasListings) {
            $googleKeys[] = 'webplus.google_feed_listings_path';
        }
        $googleKeys[] = 'webplus.google_feed_extra_paths';
        $googleKeys[] = 'webplus.google_feed_limit';

        return $this->page('@webplus/settings/edit.html.twig', [
            'pageTitle' => $this->translator->trans('webplus.settings.title'),
            'fields' => $fields,
            'feedSources' => $feedSources,
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryImages(),
            'sections' => [
                'site' => [
                    'webplus.site_name',
                    'webplus.base_url',
                    'webplus.frontend_url',
                    // default_og_image rendered via media_box
                    'webplus.twitter_site',
                    'webplus.default_robots',
                    'webplus.default_og_locale',
                    'webplus.organization_json_ld',
                    'webplus.cms_pages_path',
                ],
                'discovery' => [
                    'webplus.llms_txt',
                    'webplus.robots_txt',
                ],
                'google' => $googleKeys,
                'legal' => [
                    'webplus.legal_controller_name',
                    'webplus.legal_controller_address',
                    'webplus.legal_controller_ico',
                    'webplus.legal_controller_dic',
                    'webplus.legal_controller_email',
                    'webplus.legal_controller_phone',
                    'webplus.privacy_path',
                ],
            ],
            'publicUrls' => [
                'meta' => '/api/public/webplus/meta?path=/',
                'html' => '/api/public/webplus/html?path=/nabidky/{slug}',
                'sitemap' => '/api/public/webplus/sitemap.xml',
                'robots' => '/api/public/webplus/robots.txt',
                'llms' => '/api/public/webplus/llms.txt',
                'google' => '/api/public/webplus/google-products.xml',
            ],
        ]);
    }

    public function update(Request $request): Response
    {
        $definitions = $this->settings->definitions();

        foreach (self::KEYS as $key) {
            if (!isset($definitions[$key])) {
                continue;
            }
            if ($key === 'webplus.default_og_image') {
                continue;
            }
            $definition = $definitions[$key];
            $posted = $request->request->get(str_replace('.', '__', $key));

            if ($definition['type'] === SettingRecord::TYPE_BOOLEAN) {
                $this->settings->set($key, $posted === '1', $definition['type'], $definition['module'] ?? 'webplus');
                continue;
            }
            if ($definition['type'] === SettingRecord::TYPE_INTEGER) {
                $this->settings->set($key, (int) $posted, $definition['type'], $definition['module'] ?? 'webplus');
                continue;
            }

            $value = is_scalar($posted) ? (string) $posted : '';
            if ($key === 'webplus.google_feed_sources') {
                $sourceIds = array_map(
                    static fn (array $s): string => $s['id'],
                    $this->webplus->googleFeedSources(),
                );
                $value = $this->normalizeFeedSourcesValue($value, $sourceIds);
            }
            $this->settings->set($key, $value, $definition['type'], $definition['module'] ?? 'webplus');
        }

        $currentOg = (string) $this->settings->get('webplus.default_og_image', '');
        $postedOg = trim((string) $request->request->get('webplus__default_og_image', ''));
        $resolvedOg = $this->resolveImageUrl(
            $request,
            'default_og_image_file',
            'default_og_media_asset_id',
            'clear_default_og_image',
            $postedOg !== '' ? $postedOg : null,
            $currentOg !== '' ? $currentOg : null,
        );
        if ($resolvedOg instanceof Response) {
            return $resolvedOg;
        }
        $this->settings->set(
            'webplus.default_og_image',
            $this->storeOgImageUrl($resolvedOg ?? ''),
            SettingRecord::TYPE_STRING,
            'webplus',
        );

        $this->events->emit('webplus.settings.updated', ['type' => 'settings', 'id' => 'webplus'], [
            'keys' => self::KEYS,
        ]);
        $this->auditor->log('webplus.settings.updated', 'settings', 'webplus');
        $this->flash('success', $this->translator->trans('webplus.settings.saved'));

        return $this->redirect('admin.webplus.settings');
    }

    public function googleFeedPreview(): Response
    {
        $generated = $this->webplus->generateGoogleProductsFeed();
        if ($generated === null) {
            $this->abort(422, $this->translator->trans('webplus.error.google_feed_disabled'));
        }

        return $this->page('@webplus/settings/google_feed.html.twig', [
            'pageTitle' => $this->translator->trans('webplus.google_feed.preview'),
            'contentType' => $generated['content_type'],
            'body' => $generated['body'],
            'publicUrl' => '/api/public/webplus/google-products.xml',
        ]);
    }

    private function resolveImageUrl(
        Request $request,
        string $fileField,
        string $libraryField,
        string $clearField,
        ?string $postedUrl,
        ?string $current,
    ): Response|string|null {
        if ($request->request->get($clearField) === '1' || $request->request->get($clearField) === 'on') {
            return null;
        }

        $uploaded = $request->files->get($fileField);
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('webplus.error.media_required'));

                return $this->redirect('admin.webplus.settings');
            }

            $mime = (string) ($uploaded->getMimeType() ?: '');
            if (!str_starts_with($mime, 'image/')) {
                $this->flash('error', $this->translator->trans('webplus.error.not_image'));

                return $this->redirect('admin.webplus.settings');
            }

            try {
                $stored = $this->files->store($uploaded, $this->auth->user());
            } catch (\Throwable $e) {
                $this->flash(
                    'error',
                    $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('webplus.error.file_upload'),
                );

                return $this->redirect('admin.webplus.settings');
            }

            [$width, $height] = $this->imageDimensions($uploaded, $stored);
            $asset = new MediaAsset(
                $stored->originalName(),
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

            return '/media/' . $asset->id() . '/file';
        }

        $mediaId = trim((string) $request->request->get($libraryField, ''));
        if ($mediaId !== '') {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('webplus.error.media_required'));

                return $this->redirect('admin.webplus.settings');
            }

            $asset = $this->em->find(MediaAsset::class, $mediaId);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $this->flash('error', $this->translator->trans('webplus.error.media_not_found'));

                return $this->redirect('admin.webplus.settings');
            }

            return '/media/' . $asset->id() . '/file';
        }

        if ($postedUrl !== null) {
            return $postedUrl;
        }

        return $current;
    }

    /** Keep OG on the backend (/media, /og.png) — never rewrite onto the frontend origin. */
    private function storeOgImageUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (is_string($path) && (str_starts_with($path, '/media/') || $path === '/og.png' || str_starts_with($path, '/brand/'))) {
            return $path;
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return str_starts_with($url, '/') ? $url : '/' . $url;
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    /**
     * @param list<array{id: string, label: string}> $feedSources
     * @return list<array{value: string, label: string}>
     */
    private function feedSourceSelectOptions(array $feedSources): array
    {
        $options = [
            [
                'value' => '*',
                'label' => $this->translator->trans('webplus.settings.google_feed_sources_all'),
            ],
        ];
        foreach ($feedSources as $source) {
            $options[] = [
                'value' => $source['id'],
                'label' => $source['label'],
            ];
        }

        return $options;
    }

    /**
     * @param list<string> $availableIds
     */
    private function normalizeFeedSourcesValue(string $raw, array $availableIds): string
    {
        $raw = strtolower(trim($raw));
        if ($raw === '' || $raw === '*') {
            return '*';
        }

        $aliases = [
            'listing' => 'listings',
            'product' => 'products',
            'reality' => 'listings',
        ];

        $parts = array_values(array_filter(array_map(
            static function (string $part) use ($aliases): string {
                $part = strtolower(trim($part));

                return $aliases[$part] ?? $part;
            },
            explode(',', $raw),
        )));

        $parts = array_values(array_unique(array_filter(
            $parts,
            static fn (string $id): bool => $id === '*' || in_array($id, $availableIds, true),
        )));

        if ($parts === [] || in_array('*', $parts, true)) {
            return '*';
        }

        if (count($parts) > 1) {
            $sorted = $parts;
            $available = $availableIds;
            sort($sorted);
            sort($available);
            if ($sorted === $available) {
                return '*';
            }

            // Select UI is single-source; prefer * over an arbitrary subset.
            return '*';
        }

        return $parts[0];
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
