<?php

declare(strict_types=1);

namespace Modules\Engage\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Engage\Application\EngageProvider;
use Modules\Engage\Domain\Campaign;
use Modules\Media\Domain\MediaAsset;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CampaignController extends Controller
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
        private readonly EngageProvider $engage,
        private readonly CapabilityRegistry $capabilities,
        private readonly FileManager $files,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['name', 'slug', 'createdAt', 'updatedAt'],
            defaultSort: 'updatedAt',
            defaultDir: 'desc',
            filterKeys: ['active'],
        );
        $active = $list->filter('active');

        $qb = $this->em->getRepository(Campaign::class)->createQueryBuilder('c');
        $list->applySearch($qb, 'c', ['name', 'slug', 'notes']);
        if ($active === '1') {
            $qb->andWhere('c.active = true');
        } elseif ($active === '0') {
            $qb->andWhere('c.active = false');
        }
        $qb->orderBy('c.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@engage/campaigns/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('engage.campaigns.title'),
            'items' => $items,
            'active' => $active,
        ]));
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($this->engage->findCampaignBySlug($data['slug']) instanceof Campaign) {
            $this->abort(422, $this->translator->trans('engage.error.slug_taken'));
        }

        $og = $this->resolveImage($request, $data['og_image_url'], null, 'admin.engage.campaigns.create');
        if ($og instanceof Response) {
            return $og;
        }

        $campaign = new Campaign($data['slug'], $data['name']);
        $this->apply($campaign, $data, $og);
        $this->em->persist($campaign);
        $this->em->flush();

        $payload = $this->engage->serializeCampaign($campaign);
        $this->events->emit('engage.campaign.created', ['type' => 'engage_campaign', 'id' => $campaign->id()], $payload);
        $this->auditor->log('engage.campaign.created', 'engage_campaign', $campaign->id());
        $this->forgetForm('engage.campaign.save');
        $this->flash('success', $this->translator->trans('engage.flash.campaign_created'));

        return $this->redirect('admin.engage.campaigns.edit', ['id' => $campaign->id()]);
    }

    public function edit(string $id): Response
    {
        $item = $this->engage->findCampaign($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('engage.error.campaign_not_found'));
        }

        return $this->formPage($item);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->engage->findCampaign($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('engage.error.campaign_not_found'));
        }

        $data = $this->validated($request);
        $dup = $this->engage->findCampaignBySlug($data['slug']);
        if ($dup instanceof Campaign && $dup->id() !== $item->id()) {
            $this->abort(422, $this->translator->trans('engage.error.slug_taken'));
        }

        $og = $this->resolveImage($request, $data['og_image_url'], $item->ogImageUrl(), 'admin.engage.campaigns.edit', ['id' => $id]);
        if ($og instanceof Response) {
            return $og;
        }

        $this->apply($item, $data, $og);
        $this->em->flush();

        $payload = $this->engage->serializeCampaign($item);
        $this->events->emit('engage.campaign.updated', ['type' => 'engage_campaign', 'id' => $item->id()], $payload);
        $this->auditor->log('engage.campaign.updated', 'engage_campaign', $id);
        $this->forgetForm('engage.campaign.save');
        $this->flash('success', $this->translator->trans('engage.flash.campaign_saved'));

        return $this->redirect('admin.engage.campaigns.edit', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->engage->findCampaign($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('engage.error.campaign_not_found'));
        }

        $payload = $this->engage->serializeCampaign($item);
        $this->em->remove($item);
        $this->em->flush();
        $this->events->emit('engage.campaign.deleted', ['type' => 'engage_campaign', 'id' => $id], $payload);
        $this->auditor->log('engage.campaign.deleted', 'engage_campaign', $id);
        $this->flash('success', $this->translator->trans('engage.flash.campaign_deleted'));

        return $this->redirect('admin.engage.campaigns.index');
    }

    private function formPage(?Campaign $item): Response
    {
        return $this->page('@engage/campaigns/form.html.twig', [
            'pageTitle' => $item
                ? $this->translator->trans('engage.campaigns.edit')
                : $this->translator->trans('engage.campaigns.new'),
            'item' => $item,
            'cmsPages' => $this->engage->cmsPages(),
            'serialized' => $item ? $this->engage->serializeCampaign($item) : null,
            'hasMedia' => $this->mediaAvailable(),
            'libraryImages' => $this->libraryImages(),
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'engage.campaign.save');

        return [
            'slug' => Campaign::normalizeSlug((string) $data['slug']),
            'name' => trim((string) $data['name']),
            'notes' => $this->nullable($data['notes'] ?? null),
            'active' => $this->boolish($data['active'] ?? $request->request->get('active'), true),
            'landing_path' => $this->nullable($data['landing_path'] ?? null),
            'cms_page_id' => $this->nullable($data['cms_page_id'] ?? null),
            'cta_label' => $this->nullable($data['cta_label'] ?? null),
            'cta_url' => $this->nullable($data['cta_url'] ?? null),
            'utm_source' => $this->nullable($data['utm_source'] ?? null),
            'utm_medium' => $this->nullable($data['utm_medium'] ?? null),
            'utm_campaign' => $this->nullable($data['utm_campaign'] ?? null),
            'og_image_url' => $this->nullable($data['og_image_url'] ?? null),
        ];
    }

    /** @param array<string, mixed> $data */
    private function apply(Campaign $campaign, array $data, ?string $ogImage): void
    {
        $campaign->setSlug($data['slug']);
        $campaign->setName($data['name']);
        $campaign->setNotes($data['notes']);
        $campaign->setActive((bool) $data['active']);
        $campaign->setLandingPath($data['landing_path']);
        $campaign->setCmsPageId($data['cms_page_id']);
        $campaign->setCtaLabel($data['cta_label']);
        $campaign->setCtaUrl($data['cta_url']);
        $campaign->setUtmSource($data['utm_source']);
        $campaign->setUtmMedium($data['utm_medium']);
        $campaign->setUtmCampaign($data['utm_campaign']);
        $campaign->setOgImageUrl($ogImage);
    }

    /**
     * @param array<string, string> $redirectParams
     */
    private function resolveImage(
        Request $request,
        ?string $postedUrl,
        ?string $current,
        string $redirectRoute,
        array $redirectParams = [],
    ): Response|string|null {
        if ($request->request->get('clear_og_image') === '1' || $request->request->get('clear_og_image') === 'on') {
            return null;
        }

        $uploaded = $request->files->get('og_image_file');
        if ($uploaded instanceof UploadedFile && $uploaded->isValid()) {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('engage.error.media_required'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            $mime = (string) ($uploaded->getMimeType() ?: '');
            if (!str_starts_with($mime, 'image/')) {
                $this->flash('error', $this->translator->trans('engage.error.not_image'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            try {
                $stored = $this->files->store($uploaded, $this->auth->user());
            } catch (\Throwable $e) {
                $this->flash('error', $e->getMessage() !== '' ? $e->getMessage() : $this->translator->trans('engage.error.file_upload'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            $asset = new MediaAsset(
                $stored->originalName(),
                $stored->path(),
                $stored->mime(),
                $stored->size(),
                $this->auth->user()?->id(),
            );
            $this->em->persist($asset);
            $this->em->flush();

            return '/media/' . $asset->id() . '/file';
        }

        $mediaId = trim((string) $request->request->get('og_media_asset_id', ''));
        if ($mediaId !== '') {
            if (!$this->mediaAvailable()) {
                $this->flash('error', $this->translator->trans('engage.error.media_required'));

                return $this->redirect($redirectRoute, $redirectParams);
            }
            $asset = $this->em->find(MediaAsset::class, $mediaId);
            if (!$asset instanceof MediaAsset || !$asset->isImage()) {
                $this->flash('error', $this->translator->trans('engage.error.media_not_found'));

                return $this->redirect($redirectRoute, $redirectParams);
            }

            return '/media/' . $asset->id() . '/file';
        }

        return $postedUrl ?? $current;
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
            $options[] = ['value' => $asset->id(), 'label' => $asset->title() . ' (' . $asset->mime() . ')'];
        }

        return $options;
    }

    private function nullable(mixed $value): ?string
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
