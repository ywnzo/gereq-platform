<?php

declare(strict_types=1);

namespace Modules\Events\Http\Api;

use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\PublicCors;
use Gereq\Security\HtmlSanitizer;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;
use Modules\Authorization\Application\OAuthProviderRegistry;
use Modules\Events\Application\EventAdminNotifier;
use Modules\Events\Application\EventCatalog;
use Modules\Events\Application\EventCustomerAuth;
use Modules\Events\Application\EventInbox;
use Modules\Events\Application\EventMediaLibrary;
use Modules\Events\Application\EventUiMeta;
use Modules\Events\Domain\CatalogEvent;
use Modules\Gereq\Application\GereqFormGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final class PublicEventApiController
{
    public function __construct(
        private readonly EventCatalog $catalog,
        private readonly EventCustomerAuth $customers,
        private readonly EventInbox $inbox,
        private readonly EventBus $events,
        private readonly EventUiMeta $meta,
        private readonly OAuthProviderRegistry $oauth,
        private readonly FileManager $files,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface $translator,
        private readonly HtmlSanitizer $html,
        private readonly EventAdminNotifier $adminNotifier,
    ) {
    }

    public function options(Request $request): Response
    {
        return $this->cors(new Response('', 204), $request);
    }

    public function index(Request $request): Response
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['startsAt', 'createdAt', 'title'],
            filterable: ['category', 'region', 'spotlight', 'week', 'month', 'date'],
            defaultPerPage: 24,
        );
        $result = $this->catalog->search(
            $query->search,
            $query->filters,
            $query->page,
            $query->perPage,
            $query->sort ?? 'startsAt',
            $query->direction,
            true,
        );
        $account = $this->customers->accountFromRequest($request);
        $items = $this->catalog->serializeMany($result['items'], $account?->customerId());

        return $this->cors(ApiResponse::collection($items, $query->meta($result['total'])), $request);
    }

    public function show(Request $request, string $slug): Response
    {
        $event = $this->catalog->findBySlug($slug);
        if ($event === null || !$this->catalog->isLiveOnPublic($event)) {
            return $this->cors(ApiResponse::notFound('event_not_found', 'Akce neexistuje.'), $request);
        }
        $account = $this->customers->accountFromRequest($request);

        return $this->cors(ApiResponse::data($this->catalog->serialize($event, $account?->customerId())), $request);
    }

    public function store(Request $request): Response
    {
        $account = $this->customers->accountFromRequest($request);
        if ($account === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Přihlaste se, prosím.', [], 401), $request);
        }
        if (($denied = GereqFormGuard::denyIfInvalid($request)) !== null) {
            return $denied;
        }
        $body = $this->body($request);
        if (isset($body['external_url'])) {
            // Drop non-http(s) schemes (e.g. javascript:) instead of keeping the raw value.
            $body['external_url'] = CatalogEvent::normalizeExternalUrl((string) $body['external_url']);
        }
        $rich = $this->html->sanitizeNullable((string) ($body['long_description'] ?? $body['description'] ?? ''));
        if ($rich !== null) {
            $body['long_description'] = $rich;
            $plain = trim(html_entity_decode(strip_tags($rich), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain !== '') {
                $body['description'] = mb_substr($plain, 0, 2000);
            }
        }
        try {
            $data = gereq()->make(ValidationRegistry::class)->validate('events.submit', $body);
        } catch (ValidationException $exception) {
            return $this->cors(ApiResponse::error(
                'validation_failed',
                $exception->getMessage(),
                $exception->errors(),
                422,
            ), $request);
        }
        $data['long_description'] = $rich;
        $media = EventMediaLibrary::fromBasePath($this->em, base_path());
        $poster = $request->files->get('poster') ?? $request->files->get('file');
        if ($poster instanceof UploadedFile && $poster->isValid()) {
            $imageError = $this->validateImageUpload($poster);
            if ($imageError !== null) {
                return $this->cors(ApiResponse::error(
                    'validation_failed',
                    $imageError,
                    ['poster' => $imageError],
                    422,
                ), $request);
            }
            try {
                $asset = $media->importUploaded($this->files, $poster, (string) $data['title']);
            } catch (\Throwable $e) {
                return $this->cors(ApiResponse::error(
                    'upload_failed',
                    $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('events.error.upload'),
                    ['poster' => $this->translator->trans('events.error.upload')],
                    422,
                ), $request);
            }
            $data['media_asset_id'] = $asset->id();
        }
        $gallery = $this->importGallery($request, $media, (string) $data['title']);
        if ($gallery !== []) {
            $data['gallery'] = $gallery;
        }
        $event = $this->catalog->submit($data, $account->customerId());
        $payload = $this->catalog->serialize($event, $account->customerId());
        $this->adminNotifier->notifySubmitted($event);
        $this->events->emit('events.submitted', ['type' => 'event', 'id' => $event->id()], $payload);

        return $this->cors(ApiResponse::data($payload, 201), $request);
    }

    public function revise(Request $request, string $id): Response
    {
        if (($denied = GereqFormGuard::denyIfInvalid($request)) !== null) {
            return $denied;
        }

        $account = $this->customers->accountFromRequest($request);
        if ($account === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Přihlaste se, prosím.', [], 401), $request);
        }
        $event = $this->catalog->find($id);
        if ($event === null) {
            return $this->cors(ApiResponse::notFound('event_not_found', 'Akce neexistuje.'), $request);
        }
        $body = $this->body($request);
        if (isset($body['external_url'])) {
            $body['external_url'] = CatalogEvent::normalizeExternalUrl((string) $body['external_url']);
        }
        $rich = $this->html->sanitizeNullable((string) ($body['long_description'] ?? $body['description'] ?? ''));
        if ($rich !== null) {
            $body['long_description'] = $rich;
            $plain = trim(html_entity_decode(strip_tags($rich), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain !== '') {
                $body['description'] = mb_substr($plain, 0, 2000);
            }
        }
        try {
            $data = gereq()->make(ValidationRegistry::class)->validate('events.submit', $body);
        } catch (ValidationException $exception) {
            return $this->cors(ApiResponse::error(
                'validation_failed',
                $exception->getMessage(),
                $exception->errors(),
                422,
            ), $request);
        }
        $data['long_description'] = $rich;
        $data['gallery_sync'] = $request->request->get('gallery_sync') === '1';
        $keep = $request->request->all('gallery_keep');
        $data['gallery_keep'] = is_array($keep) ? $keep : [];
        $existingMedia = trim((string) $request->request->get('media_asset_id', ''));
        if ($existingMedia !== '') {
            $data['media_asset_id'] = $existingMedia;
        }
        $poster = $request->files->get('poster') ?? $request->files->get('file');
        $media = EventMediaLibrary::fromBasePath($this->em, base_path());
        if ($poster instanceof UploadedFile && $poster->isValid()) {
            $imageError = $this->validateImageUpload($poster);
            if ($imageError !== null) {
                return $this->cors(ApiResponse::error(
                    'validation_failed',
                    $imageError,
                    ['poster' => $imageError],
                    422,
                ), $request);
            }
            try {
                $asset = $media->importUploaded($this->files, $poster, (string) $data['title']);
            } catch (\Throwable $e) {
                return $this->cors(ApiResponse::error(
                    'upload_failed',
                    $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('events.error.upload'),
                    ['poster' => $this->translator->trans('events.error.upload')],
                    422,
                ), $request);
            }
            $data['media_asset_id'] = $asset->id();
        }
        $gallery = $this->importGallery($request, $media, (string) $data['title']);
        if ($gallery !== []) {
            $data['gallery'] = $gallery;
        }
        try {
            $event = $this->catalog->reviseOwned($event, $data, $account->customerId());
        } catch (\InvalidArgumentException $exception) {
            $status = $exception->getMessage() === 'Archivovanou akci už upravit nejde.' ? 422 : 403;

            return $this->cors(ApiResponse::error('forbidden', $exception->getMessage(), [], $status), $request);
        }
        if ($event->mediaAssetId() !== null) {
            $media->bindExistingCover($event);
            $this->em->flush();
        }
        $payload = $this->catalog->serialize($event, $account->customerId(), true);
        $this->adminNotifier->notifyRevised($event);
        $this->events->emit('events.revised', ['type' => 'event', 'id' => $event->id()], $payload);

        return $this->cors(ApiResponse::data($payload), $request);
    }

    public function favorite(Request $request, string $id): Response
    {
        $account = $this->customers->accountFromRequest($request);
        if ($account === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Přihlaste se, prosím.', [], 401), $request);
        }
        $event = $this->catalog->find($id) ?? $this->catalog->findBySlug($id);
        if ($event === null) {
            return $this->cors(ApiResponse::notFound('event_not_found', 'Akce neexistuje.'), $request);
        }
        $on = $this->catalog->toggleFavorite($event, $account->customerId());

        return $this->cors(ApiResponse::data(['favorite' => $on, 'id' => $event->id()]), $request);
    }

    public function interest(Request $request, string $id): Response
    {
        $account = $this->customers->accountFromRequest($request);
        if ($account === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Přihlaste se, prosím.', [], 401), $request);
        }
        $event = $this->catalog->find($id) ?? $this->catalog->findBySlug($id);
        if ($event === null) {
            return $this->cors(ApiResponse::notFound('event_not_found', 'Akce neexistuje.'), $request);
        }
        $created = $this->catalog->addInterest($event, $account->customerId());
        if ($created) {
            $this->events->emit('events.interest', ['type' => 'event', 'id' => $event->id()], [
                'customer_id' => $account->customerId(),
            ]);
        }

        return $this->cors(ApiResponse::data([
            'interested' => true,
            'created' => $created,
            'interest_count' => $this->catalog->interestCount($event->id()),
            'id' => $event->id(),
        ]), $request);
    }

    public function contact(Request $request): Response
    {
        if (($denied = GereqFormGuard::denyIfInvalid($request)) !== null) {
            return $denied;
        }
        $data = gereq()->make(ValidationRegistry::class)->validate('events.contact', $this->body($request));
        $item = $this->inbox->submit($data);
        $payload = $this->inbox->serialize($item);
        $this->events->emit('events.contact', ['type' => 'event_contact', 'id' => $item->id()], $payload);

        return $this->cors(ApiResponse::data($payload, 201), $request);
    }

    public function favorites(Request $request): Response
    {
        $account = $this->customers->accountFromRequest($request);
        if ($account === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Přihlaste se, prosím.', [], 401), $request);
        }
        $ids = $this->catalog->favoriteEventIds($account->customerId());
        $items = [];
        foreach ($ids as $id) {
            $event = $this->catalog->find($id);
            if ($event !== null && $this->catalog->isLiveOnPublic($event)) {
                $items[] = $this->catalog->serialize($event, $account->customerId());
            }
        }

        return $this->cors(ApiResponse::collection($items, ['total' => count($items)]), $request);
    }

    public function meta(Request $request): Response
    {
        return $this->cors(ApiResponse::data($this->meta->payload(
            $this->catalog->mediaUrls(),
            $this->oauth->publicStatus(),
        )), $request);
    }

    public function mine(Request $request): Response
    {
        $account = $this->customers->accountFromRequest($request);
        if ($account === null) {
            return $this->cors(ApiResponse::error('unauthorized', 'Přihlaste se, prosím.', [], 401), $request);
        }
        $cid = $account->customerId();
        $submitted = array_map(
            fn ($event) => $this->catalog->serialize($event, $cid, true),
            $this->catalog->submittedByCustomer($cid),
        );
        $interested = [];
        foreach ($this->catalog->interestsForCustomer($cid) as $row) {
            $interested[] = $this->catalog->serialize($row['event'], $cid);
        }

        return $this->cors(ApiResponse::data([
            'submitted' => $submitted,
            'interested' => $interested,
            'saved_count' => count($this->catalog->favoriteEventIds($cid)),
            'submitted_count' => count($submitted),
            'interest_count' => count($interested),
        ]), $request);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function importGallery(Request $request, EventMediaLibrary $media, string $title): array
    {
        $out = [];
        foreach ($this->uploadedImages($request, 'gallery') as $index => $file) {
            if ($index >= 8) {
                break;
            }
            try {
                $asset = $media->importUploaded($this->files, $file, $title . ' · foto ' . ($index + 1));
            } catch (\Throwable) {
                continue;
            }
            $out[] = [
                'kind' => 'photo',
                'title' => '',
                'media_asset_id' => $asset->id(),
                'url' => EventMediaLibrary::publicUrl($asset->id()),
            ];
        }

        return $out;
    }

    /** Max bytes for event poster / gallery images (defense-in-depth vs general FILE_MAX_SIZE). */
    private const MAX_EVENT_IMAGE_BYTES = 8 * 1024 * 1024;

    /** @return list<UploadedFile> */
    private function uploadedImages(Request $request, string $name): array
    {
        $raw = $request->files->get($name);
        if ($raw === null) {
            $raw = $request->files->get($name . '[]');
        }
        $files = $raw instanceof UploadedFile ? [$raw] : (is_array($raw) ? $raw : []);
        $out = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }
            if ($this->validateImageUpload($file) !== null) {
                continue;
            }
            $out[] = $file;
        }

        return $out;
    }

    private function validateImageUpload(UploadedFile $file): ?string
    {
        $size = (int) $file->getSize();
        if ($size <= 0 || $size > self::MAX_EVENT_IMAGE_BYTES) {
            return $this->translator->trans('events.error.image_too_large', [
                '%max%' => (string) self::MAX_EVENT_IMAGE_BYTES,
            ]);
        }

        $mime = strtolower((string) ($file->getMimeType() ?: ''));
        if (!str_starts_with($mime, 'image/')) {
            return $this->translator->trans('events.error.not_image');
        }

        // Magic bytes — spoofed Content-Type alone must not pass.
        $info = @getimagesize($file->getPathname());
        if ($info === false) {
            return $this->translator->trans('events.error.not_image');
        }
        $detected = strtolower((string) ($info['mime'] ?? ''));
        if ($detected === '' || !str_starts_with($detected, 'image/')) {
            return $this->translator->trans('events.error.not_image');
        }

        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
        if (!in_array($detected, $allowed, true)) {
            return $this->translator->trans('events.error.not_image');
        }

        return null;
    }

    private function cors(Response $response, Request $request): Response
    {
        return PublicCors::apply($response, $request, 'GET, POST, PATCH, DELETE, OPTIONS');
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return array_merge($data, $json);
            }
        }

        return $data;
    }
}
