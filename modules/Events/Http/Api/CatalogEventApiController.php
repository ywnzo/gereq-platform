<?php

declare(strict_types=1);

namespace Modules\Events\Http\Api;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Api\ApiQuery;
use Gereq\Api\ApiResponse;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Security\HtmlSanitizer;
use Gereq\Validation\ValidationRegistry;
use Modules\Events\Application\EventCatalog;
use Modules\Events\Application\EventCategories;
use Modules\Events\Application\EventSubmitterNotifier;
use Modules\Events\Domain\CatalogEvent;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

final class CatalogEventApiController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Auditor $auditor,
        private readonly EventBus $events,
        private readonly EventCatalog $catalog,
        private readonly EventCategories $categories,
        private readonly HtmlSanitizer $html,
        private readonly EventSubmitterNotifier $submitterNotifier,
        private readonly ValidationRegistry $validation,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): JsonResponse
    {
        $query = ApiQuery::fromRequest(
            $request,
            sortable: ['startsAt', 'title', 'status', 'createdAt', 'region', 'category', 'place'],
            filterable: ['status', 'region', 'spotlight'],
            defaultPerPage: 50,
        );

        $result = $this->catalog->search(
            $query->search,
            [
                'status' => (string) ($query->filters['status'] ?? ''),
                'region' => (string) ($query->filters['region'] ?? ''),
                'spotlight' => (string) ($query->filters['spotlight'] ?? ''),
            ],
            $query->page,
            $query->perPage,
            $query->sort ?? 'startsAt',
            $query->direction,
            false,
        );

        $items = array_map(
            fn (CatalogEvent $event): array => $this->serializeAdmin($event),
            $result['items'],
        );

        return ApiResponse::collection($items, $query->meta($result['total']));
    }

    public function show(string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }

        return ApiResponse::data($this->serializeAdmin($item));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->preparePayload($this->validateBody($request, 'events.save'));
        $event = $this->catalog->createAdmin($data);
        $this->events->emit('events.created', ['type' => 'event', 'id' => $event->id()], $this->catalog->serialize($event));
        $this->auditor->log('events.created', 'event', $event->id());

        return ApiResponse::data($this->serializeAdmin($event), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }
        $data = $this->preparePayload($this->validateBody($request, 'events.save'));
        $this->catalog->saveAdmin($item, $data);
        $this->events->emit('events.updated', ['type' => 'event', 'id' => $item->id()], $this->catalog->serialize($item));
        $this->auditor->log('events.updated', 'event', $item->id());

        return ApiResponse::data($this->serializeAdmin($item));
    }

    public function destroy(string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }
        $title = $item->title();
        $this->events->emit('events.deleted', ['type' => 'event', 'id' => $id], ['title' => $title]);
        $this->catalog->delete($item);
        $this->auditor->log('events.deleted', 'event', $id);

        return ApiResponse::data(['ok' => true]);
    }

    public function publish(string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }
        $item->publish();
        $this->catalog->discardStaging($item->id());
        $this->em->flush();
        $this->submitterNotifier->notifyPublished($item);
        $this->events->emit('events.published', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
        $this->auditor->log('events.published', 'event', $id);

        return ApiResponse::data($this->serializeAdmin($item));
    }

    public function archive(string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }
        $item->archive();
        $this->catalog->discardStaging($item->id());
        $this->em->flush();
        $this->events->emit('events.archived', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
        $this->auditor->log('events.archived', 'event', $id);

        return ApiResponse::data($this->serializeAdmin($item));
    }

    public function reject(Request $request, string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }
        $data = $this->validateBody($request, 'events.reject');
        $reason = (string) $this->html->sanitizeNullable((string) ($data['reason'] ?? ''));
        $plain = trim(html_entity_decode(strip_tags($reason), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (mb_strlen($plain) < 3) {
            return ApiResponse::error('validation_failed', 'Rejection reason is too short.', [
                'reason' => ['Required, at least a few words.'],
            ], 422);
        }
        $item->reject($reason);
        $this->catalog->discardStaging($item->id());
        $this->em->flush();
        $this->submitterNotifier->notifyRejected($item);
        $this->events->emit('events.rejected', ['type' => 'event', 'id' => $id], [
            'title' => $item->title(),
            'reason' => $reason,
            'customer_id' => $item->submittedByCustomerId(),
        ]);
        $this->auditor->log('events.rejected', 'event', $id);

        return ApiResponse::data($this->serializeAdmin($item));
    }

    public function spotlight(Request $request, string $id): JsonResponse
    {
        $item = $this->catalog->find($id);
        if ($item === null) {
            return ApiResponse::notFound('event_not_found', 'Event was not found.');
        }
        $data = $this->validateBody($request, 'events.spotlight');
        $this->catalog->assignSpotlight($item, (string) ($data['spotlight'] ?? 'none'));
        $this->auditor->log('events.spotlight', 'event', $id);

        return ApiResponse::data($this->serializeAdmin($item));
    }

    public function bulk(Request $request): JsonResponse
    {
        $data = $this->validateBody($request, 'events.bulk');
        $action = (string) $data['action'];
        $ids = $data['ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): string => trim((string) $id),
            $ids,
        ), static fn (string $id): bool => $id !== '')));

        if ($ids === []) {
            return ApiResponse::error('validation_failed', 'No events selected.', [], 422);
        }
        if ($action === 'delete' && empty($data['confirmed'])) {
            return ApiResponse::error('validation_failed', 'Confirm deletion.', [], 422);
        }

        $affected = 0;
        foreach ($ids as $id) {
            $item = $this->catalog->find($id);
            if ($item === null) {
                continue;
            }
            if ($action === 'delete') {
                $this->events->emit('events.deleted', ['type' => 'event', 'id' => $id], ['title' => $item->title()]);
                $this->catalog->delete($item);
                $this->auditor->log('events.deleted', 'event', $id);
                $affected++;
                continue;
            }
            if ($action === 'publish') {
                if ($item->status() === 'published') {
                    continue;
                }
                $item->publish();
                $this->catalog->discardStaging($item->id());
                $this->events->emit('events.published', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
                $this->auditor->log('events.published', 'event', $id);
                $affected++;
                continue;
            }
            if ($action === 'archive') {
                if ($item->status() === 'archived') {
                    continue;
                }
                $item->archive();
                $this->catalog->discardStaging($item->id());
                $this->events->emit('events.archived', ['type' => 'event', 'id' => $id], $this->catalog->serialize($item));
                $this->auditor->log('events.archived', 'event', $id);
                $affected++;
            }
        }
        $this->em->flush();

        return ApiResponse::data(['ok' => true, 'action' => $action, 'affected' => $affected]);
    }

    public function meta(): JsonResponse
    {
        return ApiResponse::data([
            'statuses' => CatalogEvent::STATUSES,
            'regions' => CatalogEvent::REGIONS,
            'categories' => $this->categories->assignableChoices(),
            'spotlights' => CatalogEvent::SPOTLIGHTS,
            'colors' => CatalogEvent::COLORS,
        ]);
    }

    /** @return array<string, mixed> */
    private function serializeAdmin(CatalogEvent $event): array
    {
        $payload = $this->catalog->serialize($event);
        $payload['hide_dates'] = $this->categories->hidesDates($event->category());

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function preparePayload(array $data): array
    {
        $data['description'] = trim(strip_tags((string) ($data['description'] ?? '')));
        $data['long_description'] = $this->html->sanitizeNullable(
            isset($data['long_description']) ? (string) $data['long_description'] : null,
        );

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateBody(Request $request, string $ruleset): array
    {
        return $this->validation->validate($ruleset, $this->body($request));
    }

    /** @return array<string, mixed> */
    private function body(Request $request): array
    {
        $data = $request->request->all();
        $raw = $request->getContent();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return array_merge($data, $decoded);
            }
        }

        return $data;
    }
}
