<?php

declare(strict_types=1);

namespace Modules\Events\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Media\Domain\MediaAsset;
use Gereq\Media\Domain\MediaFolder;
use Gereq\Module\CapabilityRegistry;
use Modules\Events\Application\EventMediaLibrary;
use Modules\Events\Application\EventRegionImages;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventRegionController extends Controller
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
        private readonly TranslatorInterface $translator,
        private readonly EventRegionImages $regions,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $this->regions->seedMissing();
        $urls = $this->regions->urlMap();
        $assets = $this->regions->assetMap();
        $rows = [];
        foreach (CatalogEvent::REGIONS as $name) {
            $assetId = $assets[$name] ?? null;
            $rows[] = [
                'name' => $name,
                'key' => self::fieldKey($name),
                'media_asset_id' => $assetId,
                'image_url' => $urls[$name] ?? ($assetId ? EventMediaLibrary::publicUrl($assetId) : null),
            ];
        }

        return $this->page('@events/regions/index.html.twig', [
            'pageTitle' => $this->translator->trans('events.regions.title'),
            'rows' => $rows,
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryAssets(),
        ]);
    }

    public function save(Request $request): Response
    {
        $clear = $request->request->all('clear');
        if (!is_array($clear)) {
            $clear = [];
        }
        $picked = $request->request->all('media_asset_id');
        if (!is_array($picked)) {
            $picked = [];
        }
        $files = $request->files->all('file');
        if (!is_array($files)) {
            $files = [];
        }

        $updated = 0;
        $errors = 0;
        foreach (CatalogEvent::REGIONS as $region) {
            $key = self::fieldKey($region);
            if (!empty($clear[$key])) {
                $this->regions->setAsset($region, null);
                $updated++;
                continue;
            }

            $uploaded = $files[$key] ?? null;
            if ($uploaded instanceof UploadedFile) {
                if (!$uploaded->isValid()) {
                    $errors++;
                    continue;
                }
                try {
                    $this->regions->storeUpload($region, $uploaded, $this->auth->user()?->id());
                    $updated++;
                } catch (\Throwable) {
                    $errors++;
                }
                continue;
            }

            $mediaId = trim((string) ($picked[$key] ?? ''));
            if ($mediaId === '') {
                continue;
            }
            $asset = $this->em->find(MediaAsset::class, $mediaId);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $errors++;
                continue;
            }
            $current = $this->regions->assetIdFor($region);
            if ($current === $asset->id()) {
                continue;
            }
            $this->regions->setAsset($region, $asset->id());
            $updated++;
        }

        if ($updated > 0) {
            $this->auditor->log('events.regions.updated', 'module', 'events', [], [
                'updated' => $updated,
            ]);
            $this->flash('success', $this->translator->trans('events.regions.flash.saved'));
        } elseif ($errors > 0) {
            $this->flash('error', $this->translator->trans('events.error.upload'));
        } else {
            $this->flash('success', $this->translator->trans('events.regions.flash.noop'));
        }

        return $this->redirect('admin.events.regions');
    }

    /** ASCII-safe form key for a district name (Plzeň-město → plzen-mesto). */
    public static function fieldKey(string $region): string
    {
        $map = [
            'á' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e',
            'í' => 'i', 'ň' => 'n', 'ó' => 'o', 'ř' => 'r', 'š' => 's',
            'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ý' => 'y', 'ž' => 'z',
            'Á' => 'a', 'Č' => 'c', 'Ď' => 'd', 'É' => 'e', 'Ě' => 'e',
            'Í' => 'i', 'Ň' => 'n', 'Ó' => 'o', 'Ř' => 'r', 'Š' => 's',
            'Ť' => 't', 'Ú' => 'u', 'Ů' => 'u', 'Ý' => 'y', 'Ž' => 'z',
        ];
        $ascii = strtr($region, $map);
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $ascii));

        return trim($slug, '-') ?: 'region';
    }

    private function mediaAvailable(): bool
    {
        return $this->capabilities->has('media') && class_exists(MediaAsset::class);
    }

    /** @return list<array<string, mixed>> */
    private function libraryAssets(): array
    {
        if (!$this->mediaAvailable()) {
            return [];
        }
        $provider = $this->capabilities->get('media');
        if (!is_object($provider) || !method_exists($provider, 'search')) {
            return [];
        }
        $folderNames = [];
        if (class_exists(MediaFolder::class)) {
            foreach ($this->em->getRepository(MediaFolder::class)->findAll() as $folder) {
                if ($folder instanceof MediaFolder) {
                    $folderNames[$folder->id()] = $folder->name();
                }
            }
        }
        $options = [];
        foreach ($provider->search('', 400, 'image/') as $asset) {
            if (!$asset instanceof MediaAsset) {
                continue;
            }
            $folderId = $asset->folderId() ?? '';
            $url = EventMediaLibrary::publicUrl($asset->id()) ?? '';
            $options[] = [
                'value' => $asset->id(),
                'label' => $asset->title(),
                'thumb' => EventMediaLibrary::publicUrl($asset->id(), 320) ?? $url,
                'url' => $url,
                'mime' => $asset->mime(),
                'ext' => pathinfo($asset->path(), PATHINFO_EXTENSION),
                'width' => $asset->width(),
                'height' => $asset->height(),
                'created' => $asset->createdAt()->getTimestamp(),
                'folder_id' => $folderId,
                'folder' => $folderId !== '' ? ($folderNames[$folderId] ?? '') : '',
            ];
        }

        return $options;
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
