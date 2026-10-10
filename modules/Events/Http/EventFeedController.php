<?php

declare(strict_types=1);

namespace Modules\Events\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Queue;
use Gereq\Validation\ValidationException;
use Modules\Vikendo\Application\VikendoImportJob;
use Modules\Events\Application\EventFeeds;
use Modules\Events\Application\Import\EventImportService;
use Modules\Events\Application\Import\VikendoSource;
use Modules\Events\Domain\CatalogEvent;
use Modules\Events\Domain\EventFeed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventFeedController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly EventFeeds $feeds,
        private readonly EventImportService $import,
        private readonly Queue $queue,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        return $this->page('@events/feeds/index.html.twig', [
            'pageTitle' => $this->translator->trans('events.feeds.title'),
            'items' => $this->feeds->all(),
            'drivers' => EventFeed::DRIVERS,
        ]);
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'events.feed.save');
        try {
            $item = $this->feeds->create($data);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(
                ['key' => $this->translator->trans($e->getMessage())],
                $data,
                'events.feed.save',
            );
        }
        $this->auditor->log('events.feed.created', 'event_feed', $item->id());
        $this->forgetForm('events.feed.save');
        $this->flash('success', $this->translator->trans('events.feeds.flash.created'));

        return $this->redirect('admin.events.feeds');
    }

    public function edit(string $id): Response
    {
        $item = $this->feeds->find($id);
        if (!$item instanceof EventFeed) {
            $this->flash('error', $this->translator->trans('events.feeds.error.not_found'));

            return $this->redirect('admin.events.feeds');
        }

        return $this->formPage($item);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->feeds->find($id);
        if (!$item instanceof EventFeed) {
            $this->flash('error', $this->translator->trans('events.feeds.error.not_found'));

            return $this->redirect('admin.events.feeds');
        }
        $data = $this->validate($request, 'events.feed.save');
        try {
            $this->feeds->save($item, $data);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(
                ['key' => $this->translator->trans($e->getMessage())],
                $data,
                'events.feed.save',
            );
        }
        $this->auditor->log('events.feed.updated', 'event_feed', $item->id());
        $this->forgetForm('events.feed.save');
        $this->flash('success', $this->translator->trans('events.feeds.flash.updated'));

        return $this->redirect('admin.events.feeds');
    }

    public function destroy(string $id): Response
    {
        $item = $this->feeds->find($id);
        if ($item instanceof EventFeed) {
            $this->feeds->delete($item);
            $this->auditor->log('events.feed.deleted', 'event_feed', $id);
            $this->flash('success', $this->translator->trans('events.feeds.flash.deleted'));
        }

        return $this->redirect('admin.events.feeds');
    }

    public function run(string $id): Response
    {
        $item = $this->feeds->find($id);
        if (!$item instanceof EventFeed) {
            $this->flash('error', $this->translator->trans('events.feeds.error.not_found'));

            return $this->redirect('admin.events.feeds');
        }

        // Vikendo import běží ve frontě — HTTP request nesmí čekat na tisíce událostí.
        if ($item->driver() === 'vikendo' && class_exists(VikendoImportJob::class)) {
            if (class_exists(\Modules\Vikendo\Application\VikendoLog::class)) {
                \Modules\Vikendo\Application\VikendoLog::info('queue.dispatch', [
                    'source' => $item->key(),
                    'archive_missing' => true,
                    'via' => 'admin.events.feeds',
                ]);
            }
            $this->queue->dispatch(new VikendoImportJob($item->key(), true));
            $this->auditor->log('events.feed.queued', 'event_feed', $item->id(), [], [
                'driver' => 'vikendo',
            ]);
            $this->flash('success', $this->translator->trans('events.feeds.flash.queued', [
                '%name%' => $item->name(),
            ]));

            return $this->redirect('admin.events.feeds');
        }

        $result = $this->import->import($item->key(), archiveMissing: true, dryRun: false);
        $this->auditor->log('events.feed.imported', 'event_feed', $item->id(), [], [
            'fetched' => $result['fetched'],
            'created' => $result['created'],
            'updated' => $result['updated'],
            'archived' => $result['archived'],
        ]);
        if ($result['errors'] !== []) {
            $this->flash('error', $item->name() . ': ' . implode(' · ', array_slice($result['errors'], 0, 2)));
        } else {
            $this->flash('success', $this->translator->trans('events.feeds.flash.imported', [
                '%name%' => $item->name(),
                '%fetched%' => (string) $result['fetched'],
                '%created%' => (string) $result['created'],
                '%updated%' => (string) $result['updated'],
            ]));
        }

        return $this->redirect('admin.events.feeds');
    }

    private function formPage(?EventFeed $item): Response
    {
        $cfg = $item?->config() ?? [];

        return $this->page('@events/feeds/form.html.twig', [
            'pageTitle' => $item
                ? $this->translator->trans('events.feeds.edit')
                : $this->translator->trans('events.feeds.new'),
            'item' => $item,
            'drivers' => EventFeed::DRIVERS,
            'regions' => CatalogEvent::REGIONS,
            'defaults' => [
                'enabled' => true,
                'sort_order' => 0,
                'driver' => 'ical',
                'region' => 'Domažlice',
                'config_category' => (string) ($cfg['category'] ?? 'mks'),
                'config_per_page' => (string) ($cfg['per_page'] ?? 50),
                'config_max_pages' => (string) ($cfg['max_pages'] ?? 8),
                'config_fetch_details' => ($cfg['fetch_details'] ?? true) !== false,
                'config_venues' => is_array($cfg['venues'] ?? null)
                    ? implode(', ', $cfg['venues'])
                    : (string) ($cfg['venues'] ?? 'KT_KD'),
                'config_district_eids' => is_array($cfg['district_eids'] ?? null)
                    ? implode(', ', $cfg['district_eids'])
                    : implode(', ', VikendoSource::DEFAULT_DISTRICT_EIDS),
                'config_start_from' => (string) ($cfg['start_from'] ?? ''),
                'config_map_card' => (string) (($cfg['map']['card'] ?? '')),
                'config_map_link' => (string) (($cfg['map']['link'] ?? '')),
                'config_map_title' => (string) (($cfg['map']['title'] ?? '')),
                'config_map_type' => (string) (($cfg['map']['type'] ?? '')),
                'config_map_date_class' => (string) (($cfg['map']['date_class'] ?? '')),
                'config_map_time_class' => (string) (($cfg['map']['time_class'] ?? '')),
                'config_map_place_class' => (string) (($cfg['map']['place_class'] ?? '')),
                'config_map_price_class' => (string) (($cfg['map']['price_class'] ?? '')),
                'config_map_image' => (string) (($cfg['map']['image'] ?? '')),
                'config_map_page_param' => (string) (($cfg['map']['page_param'] ?? '')),
                'config_map_detail_body' => (string) (($cfg['map']['detail_body'] ?? '')),
                'config_map_detail_teaser' => (string) (($cfg['map']['detail_teaser'] ?? '')),
                'config_map_detail_gallery' => (string) (($cfg['map']['detail_gallery'] ?? '')),
                'config_map_poster_alt' => (string) (($cfg['map']['poster_alt'] ?? '')),
            ],
            'mapDefaults' => \Modules\Events\Application\Import\HtmlScrapeMap::DEFAULTS,
        ]);
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
