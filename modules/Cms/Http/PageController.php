<?php

declare(strict_types=1);

namespace Modules\Cms\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Ui\Icons;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Brand\Branding;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Modules\Cms\Publish\CmsPagePublishAdapter;
use Gereq\Publish\ContentVersion;
use Gereq\Publish\ProductionFreeze;
use Gereq\Security\HtmlSanitizer;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\Cms\Application\BlockDesignNormalizer;
use Modules\Cms\Application\BrandColorVariables;
use Modules\Cms\Application\CmsProvider;
use Modules\Cms\Domain\CmsPartial;
use Modules\Cms\Domain\Events\PagePublished;
use Modules\Cms\Domain\Events\PageUpdated;
use Modules\Cms\Domain\Page;
use Modules\Cms\Domain\PageBlock;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PageController extends Controller
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
        private readonly CapabilityRegistry $capabilities,
        private readonly CmsProvider $cms,
        private readonly HtmlSanitizer $html,
        private readonly ProductionFreeze $freeze,
        private readonly Settings $settings,
        private readonly ContentVersion $contentVersion,
        private readonly Branding $branding,
        private readonly \Modules\Cms\Application\FormSchema $formSchema,
        private readonly \Gereq\Cms\Cms $formActions,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query->get('q', ''));
        $status = (string) $request->query->get('status', '');
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = min(100, max(1, (int) $request->query->get('per_page', 20)));

        $qb = $this->em->getRepository(Page::class)->createQueryBuilder('p')
            ->orderBy('p.updatedAt', 'DESC');

        self_repair_search($qb, 'p', ['title', 'slug'], $search);
        if (in_array($status, Page::STATUSES, true)) {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        $deleteKeys = $this->freeze->pendingDeleteKeys('cms.pages');
        if ($deleteKeys !== []) {
            $qb->andWhere('p.id NOT IN (:pendingDelete)')->setParameter('pendingDelete', $deleteKeys);
        }

        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult(($page - 1) * $perPage)->setMaxResults($perPage)->getQuery()->getResult();

        $cssKey = 'cms.custom_css_public';
        $cssValue = (string) $this->settings->get($cssKey, '');
        if ($cssValue === '') {
            $cssValue = (string) $this->settings->get('cms.custom_css', '');
        }

        return $this->page('@cms/pages/index.html.twig', [
            'pageTitle' => $this->translator->trans('cms.pages.title'),
            'items' => $items,
            'search' => $search,
            'status' => $status,
            'statuses' => Page::STATUSES,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'pages' => (int) ceil(max(1, $total) / $perPage),
            'homePageId' => (string) $this->settings->get('cms.home_page_id', ''),
            'cssVariables' => $this->branding->documentedVariables(),
            'customCssPublic' => $cssValue,
        ]);
    }

    public function setHome(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $current = (string) $this->settings->get('cms.home_page_id', '');
        if ($current !== $item->id()) {
            $this->settings->set('cms.home_page_id', $item->id(), SettingRecord::TYPE_STRING, 'cms');
            $this->auditor->log('page.home_set', 'cms_page', $item->id());
        }
        $message = $this->translator->trans('cms.flash.home_set', ['%title%' => $item->title()]);
        $on = true;

        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        // Exclusive home switch — reload list so other rows stay in sync.
        if ($this->wantsJson()) {
            return $this->json(['on' => $on, 'message' => $message, 'reload' => true]);
        }

        return $this->toggleResponse($on, 'admin.cms.pages.index', [], $message);
    }

    public function saveStorefrontSettings(Request $request): Response
    {
        $data = $this->validate($request, 'cms.storefront.settings');
        $id = trim((string) ($data['home_page_id'] ?? ''));
        if ($id !== '' && ($this->find($id) === null || $this->freeze->isPendingDelete('cms.pages', $id))) {
            throw new \Gereq\Validation\ValidationException(['home_page_id' => 'cms.error.page_not_found']);
        }
        $this->settings->set('cms.home_page_id', $id, SettingRecord::TYPE_STRING, 'cms');
        if ($request->request->has('cms__custom_css_public')) {
            $this->settings->set('cms.custom_css_public', (string) ($data['cms__custom_css_public'] ?? ''), SettingRecord::TYPE_STRING, 'cms');
        }
        $this->auditor->log('cms.storefront_settings.updated', 'settings', null);
        $this->flash('success', $this->translator->trans('settings.saved'));

        return $this->redirect('admin.settings.index', ['tab' => 'storefront']);
    }

    public function saveStorefrontTheme(Request $request): Response
    {
        $data = $this->validate($request, 'cms.storefront.theme');
        BrandColorVariables::saveStorefrontMode($this->settings, (string) $data['mode']);
        $this->auditor->log('cms.storefront_theme.updated', 'settings', null);

        return $this->json(['mode' => $data['mode']]);
    }

    public function savePublicCss(Request $request): Response
    {
        $data = $this->validate($request, 'cms.public_css.save');
        $css = (string) ($data['cms__custom_css_public'] ?? '');
        $this->settings->set('cms.custom_css_public', $css, SettingRecord::TYPE_STRING, 'cms');
        $this->auditor->log('cms.css_public.updated', 'settings', null);
        $message = $this->translator->trans('cms.flash.css_saved');
        if ($this->wantsJson($request)) {
            return $this->json(['message' => $message]);
        }
        $this->flash('success', $message);

        return $this->redirect('admin.cms.pages.index');
    }

    public function create(): Response
    {
        return $this->page('@cms/pages/form.html.twig', [
            'pageTitle' => $this->translator->trans('cms.pages.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'cms.page.save');
        $page = new Page((string) $data['slug'], trim((string) $data['title']));
        $page->setTemplate((string) $data['template']);
        $page->setContentMode((string) ($data['content_mode'] ?? 'blocks'));
        $page->setBody($this->sanitizeBody($data['body'] ?? null));
        $page->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        $page->setSsg($request->request->getBoolean('ssg'));
        $page->setMenuLocations($this->menuLocationsFromRequest($request));
        $wantPublished = (string) $data['status'] === 'published';
        // Never go public on create until publish badge apply.
        $page->setStatus($wantPublished ? 'published' : (string) $data['status']);
        $this->em->persist($page);
        $this->em->flush();

        if ($wantPublished) {
            $this->freeze->stageCreate(
                'cms.pages',
                'page',
                $page->id(),
                $page->title(),
                $this->auth->user()?->id(),
            );
        }

        $payload = $this->serialize($page);
        $this->events->dispatch(PageUpdated::make($page->id(), $payload));
        if ($page->isPublished()) {
            $this->events->dispatch(PagePublished::make($page->id(), $payload));
        }
        $this->auditor->log('page.created', 'cms_page', $page->id(), null, ['slug' => $page->slug()]);
        $this->forgetForm('cms.page.save');
        $this->flash(
            'success',
            $wantPublished
                ? $this->translator->trans('publish.flash.staged')
                : $this->translator->trans('cms.flash.page_created', ['%title%' => $page->title()]),
        );

        return $this->redirect('admin.cms.pages.show', ['id' => $page->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $pageData = $this->cms->serializePage($item, true, true);
        $storefrontUrl = $this->storefrontUrl($item);
        return $this->page('@cms/pages/show.html.twig', [
            'storefrontUrl' => $storefrontUrl,
            'previewBoot' => [
                'pageId' => $item->id(),
                'blocks' => $pageData['blocks'] ?? [],
                'storefrontUrl' => $storefrontUrl,
                'previewPalette' => BrandColorVariables::palette($this->settings),
            ],
            'pageTitle' => $item->title(),
            'item' => $item,
            'staged' => $this->freeze->hasFreeze('cms.pages', $id),
            'stagedCreate' => $this->freeze->isPendingCreate('cms.pages', $id),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        return $this->page('@cms/pages/form.html.twig', [
            'pageTitle' => $this->translator->trans('cms.pages.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $data = $this->validate($request, 'cms.page.save', ['ignore_id' => $id]);
        $wasPublished = $item->isPublished();
        $wantPublished = (string) $data['status'] === 'published';
        // Already public → freeze production snapshot before mutating working copy.
        if ($wasPublished || $this->freeze->hasFreeze('cms.pages', $item->id())) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $item->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }
        $item->setSlug((string) $data['slug']);
        $item->setTitle(trim((string) $data['title']));
        $item->setTemplate((string) $data['template']);
        $item->setContentMode((string) ($data['content_mode'] ?? $item->contentMode()));
        $item->setBody($this->sanitizeBody($data['body'] ?? null));
        $item->setMetaDescription(isset($data['meta_description']) ? (string) $data['meta_description'] : null);
        $item->setSsg($request->request->getBoolean('ssg'));
        $item->setMenuLocations($this->menuLocationsFromRequest($request));
        $item->setStatus((string) $data['status']);
        $this->em->flush();

        // First publish never goes live — stage create until topbar Publish.
        if ($wantPublished && !$wasPublished && !$this->freeze->hasFreeze('cms.pages', $item->id())) {
            $this->freeze->stageCreate(
                'cms.pages',
                'page',
                $item->id(),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }

        $payload = $this->serialize($item);
        $this->events->dispatch(PageUpdated::make($item->id(), $payload));
        if ($item->isPublished() && !$wasPublished) {
            $this->events->dispatch(PagePublished::make($item->id(), $payload));
        }
        $this->auditor->log('page.updated', 'cms_page', $item->id());
        $this->forgetForm('cms.page.save');
        $message = ($wasPublished || $wantPublished)
            ? $this->translator->trans('publish.flash.staged')
            : $this->translator->trans('cms.flash.page_updated', ['%title%' => $item->title()]);
        $this->flash('success', $message);

        return $this->redirect('admin.cms.pages.show', ['id' => $id]);
    }

    public function publish(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        if ($item->isPublished()) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $item->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $item->title(),
                $this->auth->user()?->id(),
            );
            $item->setStatus('draft');
            $this->em->flush();
            $payload = $this->serialize($item);
            $this->events->dispatch(PageUpdated::make($item->id(), $payload));
            $this->auditor->log('page.unpublished', 'cms_page', $item->id());
            $message = $this->translator->trans('publish.flash.staged');
            if (!$this->wantsJson()) {
                $this->flash('success', $message);
            }

            return $this->toggleResponse(false, 'admin.cms.pages.index', [], $message);
        }

        // Mark published in working copy, but keep off /api/public until topbar Publish.
        $item->setStatus('published');
        $this->em->flush();
        if (!$this->freeze->hasFreeze('cms.pages', $item->id())) {
            $this->freeze->stageCreate(
                'cms.pages',
                'page',
                $item->id(),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }
        $payload = $this->serialize($item);
        $this->events->dispatch(PagePublished::make($item->id(), $payload));
        $this->events->dispatch(PageUpdated::make($item->id(), $payload));
        $this->auditor->log('page.published', 'cms_page', $item->id());
        $message = $this->translator->trans('publish.flash.staged');
        if (!$this->wantsJson()) {
            $this->flash('success', $message);
        }

        return $this->toggleResponse(true, 'admin.cms.pages.index', [], $message);
    }

    public function preview(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        // Working-copy preview (staging) behind cms.view — not production freeze.
        $pageData = $this->cms->serializePage($item, true, true);
        $storefrontUrl = $this->storefrontUrl($item);
        $blocks = is_array($pageData['blocks'] ?? null) ? $pageData['blocks'] : [];

        return $this->render('@cms/pages/preview.html.twig', [
            'pageTitle' => $item->title(),
            'item' => $item,
            'pageData' => $pageData,
            'staged' => $this->freeze->hasFreeze('cms.pages', $id),
            'contentVersion' => $this->contentVersion->current(),
            'storefrontUrl' => $storefrontUrl,
            'previewBoot' => [
                'pageId' => $item->id(),
                'blocks' => $blocks,
                'storefrontUrl' => $storefrontUrl,
                'previewPalette' => BrandColorVariables::palette($this->settings),
            ],
            'user' => $this->auth->user(),
        ]);
    }

    public function previewJson(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        return new JsonResponse(['data' => $this->cms->serializePage($item, true, true)], 200, [
            'Content-Type' => 'application/json; charset=UTF-8',
        ]);
    }

    public function sandboxBuilder(): Response
    {
        $boot = $this->builderBoot(null);

        return $this->page('@cms/pages/builder.html.twig', [
            'pageTitle' => $this->translator->trans('cms.builder.sandbox.title'),
            'item' => null,
            'sandbox' => true,
            'boot' => $boot,
            'cssVariables' => $this->branding->documentedVariables(),
            'customCssPublic' => $this->publicCss(),
            'staged' => false,
        ]);
    }

    public function builder(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        if ($item->isRaw()) {
            return $this->redirect('admin.cms.pages.edit', ['id' => $id]);
        }

        $boot = $this->builderBoot($item);

        return $this->page('@cms/pages/builder.html.twig', [
            'pageTitle' => $this->translator->trans('cms.builder.title') . ' — ' . $item->title(),
            'item' => $item,
            'sandbox' => false,
            'boot' => $boot,
            'cssVariables' => $this->branding->documentedVariables(),
            'customCssPublic' => $this->publicCss(),
            'isHome' => (string) $this->settings->get('cms.home_page_id', '') === $item->id(),
            'staged' => $this->freeze->hasFreeze('cms.pages', $id),
        ]);
    }

    private function publicCss(): string
    {
        $css = (string) $this->settings->get('cms.custom_css_public', '');

        return $css !== '' ? $css : (string) $this->settings->get('cms.custom_css', '');
    }

    private function storefrontUrl(Page $item): string
    {
        $parts = \Gereq\Http\StorefrontOrigins::list();
        $origin = trim((string) ($parts[0] ?? ''));
        if ($origin === '') {
            return '';
        }

        $path = match ($item->slug()) {
            'home' => '/',
            '404' => '/404',
            'kontakt' => '/kontakt',
            'galerie', 'o-nas' => '/' . $item->slug(),
            default => '/stranka/' . $item->slug(),
        };

        return rtrim($origin, '/') . $path;
    }

    private function sandboxStorefrontUrl(): string {
        $origin = (\Gereq\Http\StorefrontOrigins::list()[0] ?? '');
        return $origin === '' ? '' : rtrim($origin, '/') . '/';
    }

    /**
     * @return array<string, mixed>
     */
    private function builderBoot(?Page $item): array
    {
        $sandbox = $item === null;
        $blockTypes = [];
        foreach (PageBlock::TYPES as $type) {
            if (in_array($type, ['dynamic', 'products'], true)) {
                continue;
            }
            $blockTypes[] = [
                'value' => $type,
                'label' => $this->translator->trans('cms.block.type.' . $type),
            ];
        }

        $variables = $this->builderVariables($item);

        /** @var list<CmsPartial> $partialEntities */
        $partialEntities = $this->em->getRepository(CmsPartial::class)->findBy([], ['updatedAt' => 'DESC', 'name' => 'ASC']);
        $partials = array_map(static fn (CmsPartial $p): array => $p->toArray(), $partialEntities);

        $blocks = [];
        if ($item !== null) {
            $pageData = $this->cms->serializePage($item, true);
            $blocks = $pageData['blocks'] ?? [];
        }

        return [
            'pageId' => $item?->id() ?? '',
            'title' => $item?->title() ?? $this->translator->trans('cms.builder.sandbox.title'),
            'slug' => $item?->slug() ?? 'sandbox',
            'template' => $item?->template() ?? 'landing',
            'sandbox' => $sandbox,
            'saveUrl' => $sandbox
                ? ''
                : $this->router->url('admin.cms.pages.builder.save', ['id' => $item->id()]),
            'previewUrl' => $sandbox
                ? ''
                : $this->router->url('admin.cms.pages.preview', ['id' => $item->id()]),
            'storefrontUrl' => $sandbox
                ? $this->sandboxStorefrontUrl()
                : $this->storefrontUrl($item),
            'exitUrl' => $sandbox
                ? $this->router->url('admin.cms.pages.index')
                : $this->router->url('admin.cms.pages.show', ['id' => $item->id()]),
            'partialsUrl' => $this->router->url('admin.cms.partials.store'),
            'partialsListUrl' => $this->router->url('admin.cms.partials.index'),
            'partialUpdateUrl' => $this->router->url('admin.cms.partials.update', ['id' => '__ID__']),
            'partialDeleteUrl' => $this->router->url('admin.cms.partials.destroy', ['id' => '__ID__']),
            'formAttemptsUrl' => $this->router->url('admin.cms.forms.attempts'),
            'formTestUrl' => $this->router->url('admin.cms.forms.test'),
            'csrf' => '',
            'formActions' => array_map(function (array $action): array {
                $action['label'] = $this->translator->trans($action['label']);
                return $action;
            }, $this->formActions->formActions()),
            'dynamicSources' => array_map(function (array $source): array {
                $source['label'] = $this->translator->trans($source['label']);
                $source['items'] = gereq()->make(\Gereq\Cms\Cms::class)->items($source['id'], 100);
                return $source;
            }, gereq()->make(\Gereq\Cms\Cms::class)->catalog()),
            'blockTypes' => $blockTypes,
            'blocks' => $blocks,
            'icons' => (new Icons())->catalog(),
            'media' => $this->mediaLibrary(),
            'mediaUploadUrl' => $this->capabilities->has('media')
                ? $this->router->url('admin.media.store')
                : '',
            'documents' => $this->documentLibrary(),
            'variables' => $variables['catalog'],
            'varValues' => $variables['values'],
            'previewPalette' => BrandColorVariables::palette($this->settings),
            'themeSaveUrl' => $this->auth->user()?->can('settings.manage')
                ? $this->router->url('admin.cms.pages.settings.theme')
                : '',
            'partials' => $partials,
            'labels' => [
                'textLimit.fieldLabel' => $this->translator->trans('cms.form.field_label'),
                'textLimit.body' => $this->translator->trans('cms.dynamic.field.body'),
                'textLimit.quote' => $this->translator->trans('cms.dynamic.field.quote'),
                'textLimit.all' => $this->translator->trans('cms.text_limit.all'),
                'textLimit.author' => $this->translator->trans('cms.dynamic.field.author'),
                'textLimit.role' => $this->translator->trans('cms.dynamic.field.role'),
                'textLimit.label' => $this->translator->trans('cms.dynamic.field.label'),
                'textLimit.caption' => $this->translator->trans('cms.dynamic.field.caption'),
                'textLimit.left' => $this->translator->trans('cms.dynamic.field.left'),
                'textLimit.right' => $this->translator->trans('cms.dynamic.field.right'),
                'textLimit.placeholder' => $this->translator->trans('cms.dynamic.field.placeholder'),
                'formTestConfiguration' => $this->translator->trans('cms.form.test_configuration'),
                'formTestInvalid' => $this->translator->trans('cms.form.test_invalid'),
                'formTestFailed' => $this->translator->trans('cms.form.failed'),
                'formField.new' => $this->translator->trans('cms.form.field.new'),
                'formField.name' => $this->translator->trans('cms.form.field.name'),
                'formField.email' => $this->translator->trans('cms.form.field.email'),
                'formField.phone' => $this->translator->trans('cms.form.field.phone'),
                'formField.details' => $this->translator->trans('cms.form.field.details'),
                'htmlPhrase.Find' => $this->translator->trans('cms.builder.html.cm.Find'),
                'htmlPhrase.Replace' => $this->translator->trans('cms.builder.html.cm.Replace'),
                'htmlPhrase.next' => $this->translator->trans('cms.builder.html.cm.next'),
                'htmlPhrase.previous' => $this->translator->trans('cms.builder.html.cm.previous'),
                'htmlPhrase.all' => $this->translator->trans('cms.builder.html.cm.all'),
                'htmlPhrase.match case' => $this->translator->trans('cms.builder.html.cm.match_case'),
                'htmlPhrase.regexp' => $this->translator->trans('cms.builder.html.cm.regexp'),
                'htmlPhrase.by word' => $this->translator->trans('cms.builder.html.cm.by_word'),
                'htmlPhrase.replace' => $this->translator->trans('cms.builder.html.cm.replace'),
                'htmlPhrase.replace all' => $this->translator->trans('cms.builder.html.cm.replace_all'),
                'htmlPhrase.close' => $this->translator->trans('cms.builder.html.cm.close'),
                'htmlPhrase.Go to line' => $this->translator->trans('cms.builder.html.cm.Go_to_line'),
                'htmlPhrase.go' => $this->translator->trans('cms.builder.html.cm.go'),
                'htmlPhrase.current match' => $this->translator->trans('cms.builder.html.cm.current_match'),
                'htmlPhrase.on line' => $this->translator->trans('cms.builder.html.cm.on_line'),
                'htmlPhrase.replaced match on line $' => $this->translator->trans('cms.builder.html.cm.replaced_match_on_line'),
                'htmlPhrase.replaced $ matches' => $this->translator->trans('cms.builder.html.cm.replaced_matches'),
                'htmlPhrase.Folded lines' => $this->translator->trans('cms.builder.html.cm.Folded_lines'),
                'htmlPhrase.Unfolded lines' => $this->translator->trans('cms.builder.html.cm.Unfolded_lines'),
                'htmlPhrase.to' => $this->translator->trans('cms.builder.html.cm.to'),
                'htmlPhrase.folded code' => $this->translator->trans('cms.builder.html.cm.folded_code'),
                'htmlPhrase.unfold' => $this->translator->trans('cms.builder.html.cm.unfold'),
                'htmlPhrase.Fold line' => $this->translator->trans('cms.builder.html.cm.Fold_line'),
                'htmlPhrase.Unfold line' => $this->translator->trans('cms.builder.html.cm.Unfold_line'),
                'htmlPhrase.Completions' => $this->translator->trans('cms.builder.html.cm.Completions'),
                'htmlPhrase.Control character' => $this->translator->trans('cms.builder.html.cm.Control_character'),
                'htmlPhrase.Diagnostics' => $this->translator->trans('cms.builder.html.cm.Diagnostics'),
                'htmlPhrase.No diagnostics' => $this->translator->trans('cms.builder.html.cm.No_diagnostics'),
                'hudDrag' => $this->translator->trans('cms.builder.hud.hudDrag'),
                'hudUp' => $this->translator->trans('cms.builder.hud.hudUp'),
                'hudDown' => $this->translator->trans('cms.builder.hud.hudDown'),
                'hudDuplicate' => $this->translator->trans('cms.builder.hud.hudDuplicate'),
                'hudSwap' => $this->translator->trans('cms.builder.hud.hudSwap'),
                'hudDelete' => $this->translator->trans('cms.builder.hud.hudDelete'),
                'hudPauseFilm' => $this->translator->trans('cms.builder.hud.hudPauseFilm'),
                'hudPlayFilm' => $this->translator->trans('cms.builder.hud.hudPlayFilm'),
                'hudAddInside' => $this->translator->trans('cms.builder.hud.hudAddInside'),
                'hudAddBelow' => $this->translator->trans('cms.builder.hud.hudAddBelow'),
                'hudInsideHint' => $this->translator->trans('cms.builder.hud.hudInsideHint'),
                'hudBelowHint' => $this->translator->trans('cms.builder.hud.hudBelowHint'),
                'hudMore' => $this->translator->trans('cms.builder.hud.hudMore'),
                'htmlEdit' => $this->translator->trans('cms.builder.hud.htmlEdit'),
                'hudType.layout' => $this->translator->trans('cms.block.type.layout'),
                'hudType.rating' => $this->translator->trans('cms.block.type.rating'),
                'hudType.text' => $this->translator->trans('cms.block.type.text'),
                'hudType.heading' => $this->translator->trans('cms.block.type.heading'),
                'hudType.icon' => $this->translator->trans('cms.block.type.icon'),
                'hudType.image' => $this->translator->trans('cms.block.type.image'),
                'hudType.avatar' => $this->translator->trans('cms.block.type.avatar'),
                'hudType.badge' => $this->translator->trans('cms.block.type.badge'),
                'hudType.widget' => $this->translator->trans('cms.block.type.widget'),
                'hudType.cta' => $this->translator->trans('cms.block.type.cta'),
                'hudType.quote' => $this->translator->trans('cms.block.type.quote'),
                'hudType.spacer' => $this->translator->trans('cms.block.type.spacer'),
                'hudType.html' => $this->translator->trans('cms.block.type.html'),
                'hudType.table' => $this->translator->trans('cms.block.type.table'),
                'htmlToolbar' => $this->translator->trans('cms.builder.html.toolbar'),
                'htmlUndo' => $this->translator->trans('cms.builder.html.undo'),
                'htmlRedo' => $this->translator->trans('cms.builder.html.redo'),
                'htmlFind' => $this->translator->trans('cms.builder.html.find'),
                'htmlLine' => $this->translator->trans('cms.builder.html.line'),
                'htmlLabel' => $this->translator->trans('cms.builder.html.title'),
                'htmlPreview' => $this->translator->trans('cms.builder.html.preview'),
                'htmlSource' => $this->translator->trans('cms.builder.html.source'),
                'htmlSourceLabel' => $this->translator->trans('cms.builder.html.source_label'),
                'htmlStyleHint' => $this->translator->trans('cms.builder.html.style_hint'),
                'themeSaved' => $this->translator->trans('cms.builder.theme.saved'),
                'themeSaveFailed' => $this->translator->trans('cms.builder.theme.error'),
                'saveFailed' => $this->translator->trans('cms.builder.save_failed'),
                'saving' => $this->translator->trans('cms.builder.saving'),
                'saved' => $this->translator->trans('cms.builder.saved'),
                'mediaUpload' => $this->translator->trans('cms.builder.media.upload'),
                'mediaUploading' => $this->translator->trans('cms.builder.media.uploading'),
                'mediaUploadFailed' => $this->translator->trans('cms.builder.media.upload_failed'),
                'mediaUploadImageOnly' => $this->translator->trans('cms.builder.media.upload_image_only'),
                'mediaUploadPartial' => $this->translator->trans('cms.builder.media.upload_partial'),
                'mediaUploaded' => $this->translator->trans('cms.builder.media.uploaded'),
                'connections' => $this->translator->trans('cms.builder.vars.connections'),
                'selectConnection' => $this->translator->trans('cms.builder.vars.select_connection'),
                'widgetEmpty' => $this->translator->trans('cms.widget.empty'),
                'dynamicEdit' => $this->translator->trans('cms.dynamic.edit_inline'),
                'dynamicPreview' => $this->translator->trans('cms.dynamic.live_preview'),
                'dynamicPrevious' => $this->translator->trans('cms.dynamic.previous'),
                'dynamicNext' => $this->translator->trans('cms.dynamic.next'),
                'dynamicItem' => $this->translator->trans('cms.dynamic.item'),
                'insert.inside' => $this->translator->trans('cms.builder.insert.inside'),
                'insert.before' => $this->translator->trans('cms.builder.insert.before'),
                'insert.after' => $this->translator->trans('cms.builder.insert.after'),
                'dynamicEmptyContainer' => $this->translator->trans('cms.dynamic.empty_container'),
                'entitySourceField.id' => $this->translator->trans('cms.dynamic.source_field.id'),
                'entitySourceField.author_name' => $this->translator->trans('cms.dynamic.source_field.author_name'),
                'entitySourceField.author_initials' => $this->translator->trans('cms.dynamic.source_field.author_initials'),
                'entitySourceField.rating' => $this->translator->trans('cms.dynamic.source_field.rating'),
                'entitySourceField.title' => $this->translator->trans('cms.dynamic.source_field.title'),
                'entitySourceField.body' => $this->translator->trans('cms.dynamic.source_field.body'),
                'entitySourceField.source' => $this->translator->trans('cms.dynamic.source_field.source'),
                'entitySourceField.external_url' => $this->translator->trans('cms.dynamic.source_field.external_url'),
                'entitySourceField.created_at' => $this->translator->trans('cms.dynamic.source_field.created_at'),
                'entitySourceField.photo' => $this->translator->trans('cms.dynamic.source_field.photo'),
                'entitySourceField.name' => $this->translator->trans('cms.dynamic.source_field.name'),
                'entitySourceField.url' => $this->translator->trans('cms.dynamic.source_field.url'),
                'entitySourceField.image' => $this->translator->trans('cms.dynamic.source_field.image'),
                'entitySourceField.description' => $this->translator->trans('cms.dynamic.source_field.description'),
                'entityField.body' => $this->translator->trans('cms.dynamic.field.body'),
                'entityField.text' => $this->translator->trans('cms.dynamic.field.text'),
                'entityField.html' => $this->translator->trans('cms.dynamic.field.html'),
                'entityField.url' => $this->translator->trans('cms.dynamic.field.url'),
                'entityField.alt' => $this->translator->trans('cms.dynamic.field.alt'),
                'entityField.caption' => $this->translator->trans('cms.dynamic.field.caption'),
                'entityField.name' => $this->translator->trans('cms.dynamic.field.name'),
                'entityField.photo_url' => $this->translator->trans('cms.dynamic.field.photo_url'),
                'entityField.initials' => $this->translator->trans('cms.dynamic.field.initials'),
                'entityField.label' => $this->translator->trans('cms.dynamic.field.label'),
                'entityField.value' => $this->translator->trans('cms.dynamic.field.value'),
                'entityField.quote' => $this->translator->trans('cms.dynamic.field.quote'),
                'entityField.author' => $this->translator->trans('cms.dynamic.field.author'),
                'entityField.role' => $this->translator->trans('cms.dynamic.field.role'),
                'entityField.title' => $this->translator->trans('cms.dynamic.field.title'),
                'entityField.left' => $this->translator->trans('cms.dynamic.field.left'),
                'entityField.right' => $this->translator->trans('cms.dynamic.field.right'),
                'dynamicTemplate' => $this->translator->trans('cms.dynamic.template'),
                'pickField' => $this->translator->trans('cms.builder.vars.pick_field'),
                'replaceOnePage' => $this->translator->trans('cms.builder.onepage.replace_confirm'),
                'onePageInserted' => $this->translator->trans('cms.builder.onepage.inserted'),
                'layoutPageTitle' => $this->translator->trans('cms.builder.layout_pages.title'),
                'layoutPageReplace' => $this->translator->trans('cms.builder.layout_pages.replace_confirm'),
                'layoutPageInserted' => $this->translator->trans('cms.builder.layout_pages.inserted'),
                'layoutPageAtelier' => $this->translator->trans('cms.builder.layout_pages.atelier'),
                'layoutPageAtelierDesc' => $this->translator->trans('cms.builder.layout_pages.atelier_desc'),
                'layoutPageLaunch' => $this->translator->trans('cms.builder.layout_pages.launch'),
                'layoutPageLaunchDesc' => $this->translator->trans('cms.builder.layout_pages.launch_desc'),
                'layoutPageEditorial' => $this->translator->trans('cms.builder.layout_pages.editorial'),
                'layoutPageEditorialDesc' => $this->translator->trans('cms.builder.layout_pages.editorial_desc'),
                'partialSaved' => $this->translator->trans('cms.builder.partials.saved'),
                'partialInserted' => $this->translator->trans('cms.builder.partials.inserted'),
                'partialDeleted' => $this->translator->trans('cms.builder.partials.deleted'),
                'partialEmpty' => $this->translator->trans('cms.builder.partials.empty_canvas'),
                'partialNameRequired' => $this->translator->trans('cms.error.partial_name'),
                'partialDeleteConfirm' => $this->translator->trans('cms.builder.partials.delete_confirm'),
                'sandboxSave' => $this->translator->trans('cms.builder.sandbox.save'),
                'sandboxHint' => $this->translator->trans('cms.builder.sandbox.hint'),
                'fixedLayoutTitle' => $this->translator->trans('cms.builder.fixed_layout.title'),
                'fixedLayoutBody' => $this->translator->trans('cms.builder.fixed_layout.body'),
                'fixedLayoutOk' => $this->translator->trans('cms.builder.fixed_layout.ok'),
                'fixedLayoutClose' => $this->translator->trans('cms.builder.fixed_layout.close'),
                'loadingTitle' => $this->translator->trans('cms.builder.loading.title'),
                'loadingBody' => $this->translator->trans('cms.builder.loading.body'),
                'layerDefaultName' => $this->translator->trans('cms.builder.layers.default_name'),
                'preview' => $this->translator->trans('cms.pages.preview'),
                'previewUnsavedTitle' => $this->translator->trans('cms.preview.unsaved_title'),
                'previewUnsavedBody' => $this->translator->trans('cms.preview.unsaved_body'),
                'previewUnsavedConfirm' => $this->translator->trans('cms.preview.unsaved_confirm'),
                'sectionHero' => $this->translator->trans('cms.builder.section.hero'),
                'sectionTrust' => $this->translator->trans('cms.builder.section.trust'),
                'sectionListings' => $this->translator->trans('cms.builder.section.listings'),
                'sectionWhy' => $this->translator->trans('cms.builder.section.why'),
                'sectionServices' => $this->translator->trans('cms.builder.section.services'),
                'sectionHow' => $this->translator->trans('cms.builder.section.how'),
                'sectionTypes' => $this->translator->trans('cms.builder.section.types'),
                'sectionTestimonials' => $this->translator->trans('cms.builder.section.testimonials'),
                'sectionInsights' => $this->translator->trans('cms.builder.section.insights'),
                'sectionFaq' => $this->translator->trans('cms.builder.section.faq'),
                'sectionAbout' => $this->translator->trans('cms.builder.section.about'),
                'sectionCta' => $this->translator->trans('cms.builder.section.cta'),
                'sectionContact' => $this->translator->trans('cms.builder.section.contact'),
                'sectionFooter' => $this->translator->trans('cms.builder.section.footer'),
                'sectionQuote' => $this->translator->trans('cms.builder.section.quote'),
                'sectionHeroDesc' => $this->translator->trans('cms.builder.section.hero.desc'),
                'sectionTrustDesc' => $this->translator->trans('cms.builder.section.trust.desc'),
                'sectionListingsDesc' => $this->translator->trans('cms.builder.section.listings.desc'),
                'sectionWhyDesc' => $this->translator->trans('cms.builder.section.why.desc'),
                'sectionServicesDesc' => $this->translator->trans('cms.builder.section.services.desc'),
                'sectionHowDesc' => $this->translator->trans('cms.builder.section.how.desc'),
                'sectionTypesDesc' => $this->translator->trans('cms.builder.section.types.desc'),
                'sectionTestimonialsDesc' => $this->translator->trans('cms.builder.section.testimonials.desc'),
                'sectionInsightsDesc' => $this->translator->trans('cms.builder.section.insights.desc'),
                'sectionFaqDesc' => $this->translator->trans('cms.builder.section.faq.desc'),
                'sectionAboutDesc' => $this->translator->trans('cms.builder.section.about.desc'),
                'sectionCtaDesc' => $this->translator->trans('cms.builder.section.cta.desc'),
                'sectionContactDesc' => $this->translator->trans('cms.builder.section.contact.desc'),
                'sectionFooterDesc' => $this->translator->trans('cms.builder.section.footer.desc'),
                'sectionQuoteDesc' => $this->translator->trans('cms.builder.section.quote.desc'),
                'galleryImage_toolbar' => $this->translator->trans('cms.builder.gallery.image.toolbar'),
                'galleryImage_hint' => $this->translator->trans('cms.builder.gallery.image.hint'),
                'galleryImage_randomSelected' => $this->translator->trans('cms.builder.gallery.image.randomSelected'),
                'galleryImage_randomAll' => $this->translator->trans('cms.builder.gallery.image.randomAll'),
                'galleryImage_deselect' => $this->translator->trans('cms.builder.gallery.image.deselect'),
                'galleryImage_selected' => $this->translator->trans('cms.builder.gallery.image.selected'),
                'galleryImage_span' => $this->translator->trans('cms.builder.gallery.image.span'),
                'galleryImage_ratio' => $this->translator->trans('cms.builder.gallery.image.ratio'),
                'galleryImage_natural' => $this->translator->trans('cms.builder.gallery.image.natural'),
                'galleryImage_height' => $this->translator->trans('cms.builder.gallery.image.height'),
                'galleryImage_fit' => $this->translator->trans('cms.builder.gallery.image.fit'),
                'galleryImage_contain' => $this->translator->trans('cms.builder.gallery.image.contain'),
                'galleryImage_cover' => $this->translator->trans('cms.builder.gallery.image.cover'),
                'galleryImage_column' => $this->translator->trans('cms.builder.gallery.image.column'),
                'galleryImage_auto' => $this->translator->trans('cms.builder.gallery.image.auto'),
                'galleryImage_group' => $this->translator->trans('cms.builder.gallery.image.group'),
                'galleryImage_selectGroup' => $this->translator->trans('cms.builder.gallery.image.selectGroup'),
                'galleryImage_reset' => $this->translator->trans('cms.builder.gallery.image.reset'),
                'galleryImage_photo' => $this->translator->trans('cms.builder.gallery.image.photo'),
                'galleryVariant_grid' => $this->translator->trans('cms.builder.gallery.variant.grid'),
                'galleryVariant_grid_3' => $this->translator->trans('cms.builder.gallery.variant.grid_3'),
                'galleryVariant_masonry' => $this->translator->trans('cms.builder.gallery.variant.masonry'),
                'galleryVariant_mosaic' => $this->translator->trans('cms.builder.gallery.variant.mosaic'),
                'galleryVariant_spotlight' => $this->translator->trans('cms.builder.gallery.variant.spotlight'),
                'galleryVariant_carousel' => $this->translator->trans('cms.builder.gallery.variant.carousel'),
                'galleryVariant_carousel_peek' => $this->translator->trans('cms.builder.gallery.variant.carousel_peek'),
                'galleryVariant_filmstrip' => $this->translator->trans('cms.builder.gallery.variant.filmstrip'),
                'layoutVariant_stack' => $this->translator->trans('cms.builder.layout.variant.stack'),
                'layoutVariant_row' => $this->translator->trans('cms.builder.layout.variant.row'),
                'layoutVariant_image' => $this->translator->trans('cms.builder.layout.variant.image'),
                'layoutVariant_cta' => $this->translator->trans('cms.builder.layout.variant.cta'),
                'layoutVariant_grid_2' => $this->translator->trans('cms.builder.layout.variant.grid_2'),
                'layoutVariant_grid_3' => $this->translator->trans('cms.builder.layout.variant.grid_3'),
                'layoutVariant_hero' => $this->translator->trans('cms.builder.layout.variant.hero'),
                'layoutVariant_cards' => $this->translator->trans('cms.builder.layout.variant.cards'),
                'richSizePlaceholder' => $this->translator->trans('cms.builder.prop.size'),
                'richSizeSm' => $this->translator->trans('cms.builder.size.sm'),
                'richSizeMd' => $this->translator->trans('cms.builder.size.md'),
                'richSizeLg' => $this->translator->trans('cms.builder.size.lg'),
                'richSizeXl' => $this->translator->trans('cms.builder.size.xl'),
                'richSize2Xl' => $this->translator->trans('cms.builder.size.2xl'),
                'richWeightPlaceholder' => $this->translator->trans('cms.builder.prop.font_weight'),
                'richWeightNormal' => $this->translator->trans('cms.builder.weight.normal'),
                'richWeightMedium' => $this->translator->trans('cms.builder.weight.medium'),
                'richWeightSemibold' => $this->translator->trans('cms.builder.weight.semibold'),
                'richWeightBold' => $this->translator->trans('cms.builder.weight.bold'),
                'richStyleTitle' => $this->translator->trans('cms.rich.style.title'),
                'richStyleP' => $this->translator->trans('cms.rich.style.p'),
                'richFontTitle' => $this->translator->trans('cms.rich.font.title'),
                'richFontDefault' => $this->translator->trans('cms.rich.font.default'),
                'richFontSystem' => $this->translator->trans('cms.rich.font.system'),
                'richBold' => $this->translator->trans('cms.rich.bold'),
                'richItalic' => $this->translator->trans('cms.rich.italic'),
                'richUnderline' => $this->translator->trans('cms.rich.underline'),
                'richStrike' => $this->translator->trans('cms.rich.strike'),
                'richLink' => $this->translator->trans('cms.rich.link'),
                'richAlignment' => $this->translator->trans('cms.rich.alignment'),
                'richLists' => $this->translator->trans('cms.rich.lists'),
                'richLinkUrl' => $this->translator->trans('cms.rich.link.url'),
                'richLinkText' => $this->translator->trans('cms.rich.link.text'),
                'richLinkApply' => $this->translator->trans('cms.rich.link.apply'),
                'richLinkClose' => $this->translator->trans('cms.rich.link.close'),
                'richLinkCancel' => $this->translator->trans('cms.rich.link.cancel'),
                'richLinkOpen' => $this->translator->trans('cms.rich.link.open'),
                'richLinkRemove' => $this->translator->trans('cms.rich.link.remove'),
                'richLinkInvalid' => $this->translator->trans('cms.rich.link.invalid'),
                'richAlignLeft' => $this->translator->trans('cms.rich.align.left'),
                'richAlignCenter' => $this->translator->trans('cms.rich.align.center'),
                'richAlignRight' => $this->translator->trans('cms.rich.align.right'),
                'richAlignJustify' => $this->translator->trans('cms.rich.align.justify'),
                'richListBullet' => $this->translator->trans('cms.rich.list.bullet'),
                'richListOrdered' => $this->translator->trans('cms.rich.list.ordered'),
                'richQuote' => $this->translator->trans('cms.rich.quote'),
                'richHr' => $this->translator->trans('cms.rich.hr'),
                'richClearFormat' => $this->translator->trans('cms.rich.clear_format'),
                'richResetText' => $this->translator->trans('cms.rich.reset.text'),
                'richResetBg' => $this->translator->trans('cms.rich.reset.bg'),
                'richUndo' => $this->translator->trans('cms.rich.undo'),
                'richRedo' => $this->translator->trans('cms.rich.redo'),
                'richMixed' => $this->translator->trans('cms.rich.mixed'),
                'richPaletteShow' => $this->translator->trans('cms.rich.palette.show'),
                'richPaletteHide' => $this->translator->trans('cms.rich.palette.hide'),
                'richPaletteTitle' => $this->translator->trans('cms.rich.palette.title'),
                'richPaletteText' => $this->translator->trans('cms.rich.palette.text'),
                'palettePrimary' => $this->translator->trans('settings.palette.primary'),
                'paletteSecondary' => $this->translator->trans('settings.palette.secondary'),
                'paletteAccent' => $this->translator->trans('settings.palette.accent'),
                'paletteBackground' => $this->translator->trans('settings.palette.light_background'),
                'paletteSurface' => $this->translator->trans('settings.palette.light_surface'),
                'paletteHeader' => $this->translator->trans('settings.palette.light_header'),
                'richBgGroup' => $this->translator->trans('cms.rich.palette.bg'),
                'richCustomColor' => $this->translator->trans('cms.rich.custom_color'),
                'richColorDefault' => $this->translator->trans('cms.rich.color.default'),
                'richColorWhite' => $this->translator->trans('cms.rich.color.white'),
                'richColorYellow' => $this->translator->trans('cms.rich.color.yellow'),
                'richColorGray' => $this->translator->trans('cms.rich.color.gray'),
                'richColorBlack' => $this->translator->trans('cms.rich.color.black'),
                'richHlNone' => $this->translator->trans('cms.rich.hl.none'),
                'richHlYellow' => $this->translator->trans('cms.rich.hl.yellow'),
                'richHlWhite' => $this->translator->trans('cms.rich.hl.white'),
                'richHlDark' => $this->translator->trans('cms.rich.hl.dark'),
            ],
        ];
    }

    public function builderSave(Request $request, string $id): JsonResponse
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            return new JsonResponse(['error' => ['message' => $this->translator->trans('cms.error.page_not_found')]], 404);
        }

        $raw = $request->getContent();
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        $incoming = is_array($decoded) && isset($decoded['blocks']) && is_array($decoded['blocks'])
            ? $decoded['blocks']
            : [];

        $this->formSchema->assertTree($incoming);

        if ($item->isPublished() || $this->freeze->hasFreeze('cms.pages', $id)) {
            $this->freeze->freezeIfNeeded(
                'cms.pages',
                'page',
                $item->id(),
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $item->title(),
                $this->auth->user()?->id(),
            );
        }

        /** @var list<PageBlock> $existing */
        $existing = $this->em->getRepository(PageBlock::class)->findBy(['pageId' => $id]);
        $byId = [];
        foreach ($existing as $block) {
            $byId[$block->id()] = $block;
        }

        $keep = [];
        $idMap = [];
        $order = 0;
        foreach ($incoming as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['type'] ?? '');
            if (!in_array($type, PageBlock::TYPES, true)) {
                continue;
            }
            $order++;
            $clientId = (string) ($row['client_id'] ?? '');
            $serverId = trim((string) ($row['id'] ?? ''));
            $payloadRaw = is_array($row['payload'] ?? null) ? $row['payload'] : [];
            $payload = $this->normalizeBuilderPayload($type, $payloadRaw);

            if ($serverId !== '' && isset($byId[$serverId])) {
                $block = $byId[$serverId];
                $block->setType($type);
                $block->setSortOrder($order);
                $block->setPayload($payload);
                $keep[$serverId] = true;
                if ($clientId !== '') {
                    $idMap[$clientId] = $serverId;
                }
                continue;
            }

            $block = new PageBlock($id, $type, $order, $payload);
            $this->em->persist($block);
            $this->em->flush(); // need id for map
            $keep[$block->id()] = true;
            if ($clientId !== '') {
                $idMap[$clientId] = $block->id();
            }
        }

        foreach ($existing as $block) {
            if (!isset($keep[$block->id()])) {
                $this->em->remove($block);
            }
        }
        $this->em->flush();

        $this->events->dispatch(PageUpdated::make($item->id(), ['id' => $item->id(), 'touched' => 'builder.save']));
        $this->auditor->log('page.builder_saved', 'cms_page', $item->id());

        $serialized = $this->cms->serializePage($item, true);

        return new JsonResponse([
            'data' => [
                'blocks' => $serialized['blocks'] ?? [],
                'id_map' => $idMap,
                'staged' => $this->freeze->hasFreeze('cms.pages', $id),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeBuilderPayload(string $type, array $payload, int $depth = 0): array
    {
        $core = match ($type) {
            'form' => \Modules\Cms\Application\FormSchema::normalize($payload, fn (mixed $children): array => $this->normalizeChildren($children, $depth)),
            'form_field' => \Modules\Cms\Application\FormSchema::field($payload),
            'heading' => [
                // Rich inline styles (color / size) — same allowlist as text/html blocks.
                'text' => $this->html->sanitize((string) ($payload['text'] ?? '')),
                'level' => max(1, min(3, (int) ($payload['level'] ?? 2))),
            ],
            'text' => [
                'body' => $this->html->sanitize((string) ($payload['body'] ?? '')),
            ],
            'html' => [
                'html' => $this->html->sanitize((string) ($payload['html'] ?? '')),
            ],
            'image' => [
                'media_id' => trim((string) ($payload['media_id'] ?? '')),
                'url' => trim((string) ($payload['url'] ?? '')),
                'alt' => trim((string) ($payload['alt'] ?? '')),
                'caption' => trim((string) ($payload['caption'] ?? '')),
            ],
            'cta' => [
                'label' => (new \Gereq\Security\HtmlSanitizer())->sanitize((string) ($payload['label'] ?? '')),
                'url' => \Modules\Cms\Application\ContentSecurity::link((string) ($payload['url'] ?? '')),
                'style' => trim((string) ($payload['style'] ?? 'primary')),
            ],
            'layout' => $this->normalizeLayoutPayload($payload, $depth),
            'divider' => [
                'style' => trim((string) ($payload['style'] ?? 'solid')),
            ],
            'products' => [
                'product_ids' => trim((string) ($payload['product_ids'] ?? '')),
            ],
            'dynamic' => array_merge(\Modules\Cms\Application\DynamicElement::normalize($payload), [
                'item_blocks' => \Modules\Cms\Application\DynamicElement::templateBlocks($payload['item_blocks'] ?? [], fn (string $childType, array $childPayload): array => $this->normalizeBuilderPayload($childType, $childPayload, $depth + 1), $depth),
            ]),
            'widget' => \Modules\Cms\Application\IntegrationWidget::normalize($payload),
            'avatar', 'badge' => \Modules\Cms\Application\VisualElement::normalize($type, $payload),
            'rating' => ['value' => substr((string) ($payload['value'] ?? '5'), 0, 100)],
            'gallery' => [
                'media_ids' => trim((string) ($payload['media_ids'] ?? '')),
                'variant' => BlockDesignNormalizer::galleryVariant($payload['variant'] ?? 'grid'),
            ],
            'quote' => [
                'quote' => trim((string) ($payload['quote'] ?? '')),
                'author' => trim((string) ($payload['author'] ?? '')),
                'role' => trim((string) ($payload['role'] ?? '')),
            ],
            'video' => [
                'url' => trim((string) ($payload['url'] ?? '')),
                'caption' => trim((string) ($payload['caption'] ?? '')),
            ],
            'spacer' => [
                'size' => in_array(trim((string) ($payload['size'] ?? 'md')), ['sm', 'md', 'lg'], true)
                    ? trim((string) ($payload['size'] ?? 'md'))
                    : 'md',
            ],
            'icon' => [
                'name' => preg_replace('~[^a-z0-9_\\-]+~i', '', trim((string) ($payload['name'] ?? 'circle'))) ?: 'circle',
                'icon_style' => BlockDesignNormalizer::normalizeIconStyle($payload['icon_style'] ?? 'outlined'),
                'size' => BlockDesignNormalizer::normalizeIconSize($payload['size'] ?? '24'),
                'fill' => BlockDesignNormalizer::normalizeIconFill($payload['fill'] ?? '0'),
                'weight' => BlockDesignNormalizer::normalizeIconWeight($payload['weight'] ?? '400'),
                'grade' => BlockDesignNormalizer::normalizeIconGrade($payload['grade'] ?? '0'),
                'opsz' => BlockDesignNormalizer::normalizeIconOpsz($payload['opsz'] ?? ''),
                'title' => trim((string) ($payload['title'] ?? '')),
                'hover_color' => BlockDesignNormalizer::normalizeColor(
                    (string) ($payload['hover_color'] ?? 'inherit'),
                    BlockDesignNormalizer::textTokens(),
                    'inherit',
                ),
            ],
            'table' => [
                'html' => $this->html->sanitize((string) ($payload['html'] ?? '')),
                'caption' => $this->html->sanitize((string) ($payload['caption'] ?? '')),
                'caption_visible' => ((int) ($payload['caption_visible'] ?? 1)) === 0 ? '0' : '1',
                'style_cell_padding' => max(0, min(20, (int) ($payload['style_cell_padding'] ?? 0))),
                'style_border_inner' => ((int) ($payload['style_border_inner'] ?? 1)) === 0 ? '0' : '1',
                'style_border_outer' => ((int) ($payload['style_border_outer'] ?? 1)) === 0 ? '0' : '1',
            ],
            default => [],
        };

        if (isset($payload['gereq_tag']) || isset($payload['gereq_component'])) {
            if (isset($payload['children'])) {
                $core['children'] = $this->normalizeChildren($payload['children'], $depth);
            }
        }

        return BlockDesignNormalizer::merge($core, $payload);
    }

    /** @return list<array{id: string, title: string, url: string, mime: string, alt: string, width: ?int, height: ?int, created: int}> */
    private function mediaLibrary(): array
    {
        if (!$this->capabilities->has('media')) {
            return [];
        }
        $provider = $this->capabilities->get('media');
        if (!method_exists($provider, 'images')) {
            return [];
        }
        $out = [];
        foreach ($provider->images(80) as $asset) {
            $out[] = [
                'id' => $asset->id(),
                'title' => $asset->title(),
                'url' => '/media/' . $asset->id() . '/file',
                'mime' => $asset->mime(),
                'alt' => (string) ($asset->alt() ?? ''),
                'width' => $asset->width(),
                'height' => $asset->height(),
                'created' => $asset->createdAt()->getTimestamp(),
            ];
        }

        return $out;
    }

    /** @return list<array{id: string, title: string, mime: string, url: string}> */
    private function documentLibrary(): array
    {
        if (!$this->capabilities->has('documents')) {
            return [];
        }
        $provider = $this->capabilities->get('documents');
        if (!method_exists($provider, 'search')) {
            return [];
        }
        $out = [];
        foreach ($provider->search('', 40) as $doc) {
            $out[] = [
                'id' => $doc->id(),
                'title' => $doc->title(),
                'mime' => $doc->mime(),
                'url' => $this->router->url('admin.documents.file', ['id' => $doc->id()]),
            ];
        }

        return $out;
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null || $this->freeze->isPendingDelete('cms.pages', $id)) {
            $this->abort(404, $this->translator->trans('cms.error.page_not_found'));
        }

        $title = $item->title();
        $wasHome = (string) $this->settings->get('cms.home_page_id', '') === $id;
        if ($item->isPublished() || $this->freeze->hasFreeze('cms.pages', $id)) {
            $this->freeze->stageDelete(
                'cms.pages',
                'page',
                $id,
                fn (): array => CmsPagePublishAdapter::capture($this->em, $item),
                $title,
                $this->auth->user()?->id(),
            );
            $this->auditor->log('page.delete_staged', 'cms_page', $id);
            $this->flash('success', $this->translator->trans('publish.flash.staged'));
        } else {
            foreach ($this->em->getRepository(PageBlock::class)->findBy(['pageId' => $id]) as $block) {
                $this->em->remove($block);
            }
            $this->em->remove($item);
            $this->em->flush();
            $this->auditor->log('page.deleted', 'cms_page', $id);
            $this->flash('success', $this->translator->trans('cms.flash.page_deleted', ['%title%' => $title]));
        }

        if ($wasHome) {
            $this->settings->set('cms.home_page_id', '', SettingRecord::TYPE_STRING, 'cms');
        }

        return $this->redirect('admin.cms.pages.index');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeLayoutPayload(array $payload, int $depth): array
    {
        return BlockDesignNormalizer::layout($payload, fn (mixed $children): array => $this->normalizeChildren($children, $depth));
    }

    /**
     * @param mixed $children
     * @return list<array<string, mixed>>
     */
    private function normalizeChildren(mixed $children, int $depth): array
    {
        if (!is_array($children) || $depth >> 4) {
            return [];
        }

        $out = [];
        foreach ($children as $child) {
            if (!is_array($child)) {
                continue;
            }
            $type = (string) ($child['type'] ?? '');
            if (!in_array($type, PageBlock::TYPES, true)) {
                continue;
            }
            $payload = is_array($child['payload'] ?? null) ? $child['payload'] : [];
            $out[] = [
                'id' => trim((string) ($child['id'] ?? '')),
                'type' => $type,
                'payload' => $this->normalizeBuilderPayload($type, $payload, $depth + 1),
            ];
        }
        return $out;
    }

    private function find(string $id): ?Page
    {
        $page = $this->em->find(Page::class, $id);

        return $page instanceof Page ? $page : null;
    }

    private function resolveSiteTitle(): string
    {
        foreach (['brand.site_name', 'brand.name', 'cms.site_title'] as $key) {
            $value = trim((string) $this->settings->get($key, ''));
            if ($value !== '' && !($key === 'cms.site_title' && $value === 'GEREQ Site')) {
                return $value;
            }
        }

        return 'Site';
    }

    /** @return array<string, mixed> */
    private function serialize(Page $page): array
    {
        return [
            'id' => $page->id(),
            'slug' => $page->slug(),
            'title' => $page->title(),
            'status' => $page->status(),
            'template' => $page->template(),
            'content_mode' => $page->contentMode(),
            'body' => $page->body(),
            'meta_description' => $page->metaDescription(),
            'ssg' => $page->ssg(),
            'menu_locations' => $page->menuLocations(),
            'menu_location' => $page->menuLocations()[0] ?? null,
            'menu_sort_order' => $page->menuSortOrder(),
            'published_at' => $page->publishedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $page->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $page->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /** @return list<string> */
    private function menuLocationsFromRequest(Request $request): array
    {
        $raw = $request->request->all('menu_locations');
        if ($raw === []) {
            $single = $request->request->all()['menu_locations'] ?? null;
            $raw = is_array($single) ? $single : [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) || is_int($value)) {
                $out[] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @return array{catalog: list<array{path: string, label: string, description: string, group: string, kind: string, example: string, token: string}>, values: array<string, string>}
     */
    private function builderVariables(?Page $page): array
    {
        $siteTitle = $this->resolveSiteTitle();
        $brandName = (string) $this->settings->get('brand.name', '');
        $brandLogo = (string) $this->settings->get('brand.logo_url', '');
        $pageUrl = $page !== null ? '/' . ltrim($page->slug(), '/') : '/';

        $values = [
            'page.title' => $page?->title() ?? $this->translator->trans('cms.builder.sandbox.title'),
            'page.slug' => $page?->slug() ?? 'sandbox',
            'page.meta' => (string) ($page?->metaDescription() ?? ''),
            'page.url' => $pageUrl,
            'site.title' => $siteTitle,
            'site.home' => '/',
            'brand.name' => $brandName !== '' ? $brandName : $siteTitle,
            'brand.logo' => $brandLogo,
            'layout.gap' => '16',
            'layout.columns' => '12',
        ] + BrandColorVariables::resolve($this->settings);

        $defs = [
            ['page.title', 'cms.builder.var.page_title', 'cms.builder.var.page_title_hint', 'cms.builder.var.group.page', 'text'],
            ['page.slug', 'cms.builder.var.page_slug', 'cms.builder.var.page_slug_hint', 'cms.builder.var.group.page', 'text'],
            ['page.meta', 'cms.builder.var.page_meta', 'cms.builder.var.page_meta_hint', 'cms.builder.var.group.page', 'text'],
            ['page.url', 'cms.builder.var.page_url', 'cms.builder.var.page_url_hint', 'cms.builder.var.group.links', 'link'],
            ['site.title', 'cms.builder.var.site_title', 'cms.builder.var.site_title_hint', 'cms.builder.var.group.text', 'text'],
            ['site.home', 'cms.builder.var.site_home', 'cms.builder.var.site_home_hint', 'cms.builder.var.group.links', 'link'],
            ['brand.name', 'cms.builder.var.brand_name', 'cms.builder.var.brand_name_hint', 'cms.builder.var.group.text', 'text'],
            ['brand.logo', 'cms.builder.var.brand_logo', 'cms.builder.var.brand_logo_hint', 'cms.builder.var.group.images', 'image'],
            ['brand.primary', 'cms.builder.var.brand_primary', 'cms.builder.var.brand_primary_hint', 'cms.builder.var.group.colors', 'color'],
            ['brand.secondary', 'cms.builder.var.brand_secondary', 'cms.builder.var.brand_secondary_hint', 'cms.builder.var.group.colors', 'color'],
            ['brand.accent', 'cms.builder.var.brand_accent', 'cms.builder.var.brand_accent_hint', 'cms.builder.var.group.colors', 'color'],
            ['brand.light_background', 'cms.builder.var.light_background', 'cms.builder.var.light_background', 'cms.builder.var.group.colors', 'color'],
            ['brand.light_surface', 'cms.builder.var.light_surface', 'cms.builder.var.light_surface', 'cms.builder.var.group.colors', 'color'],
            ['brand.light_header', 'cms.builder.var.light_header', 'cms.builder.var.light_header', 'cms.builder.var.group.colors', 'color'],
            ['brand.dark_background', 'cms.builder.var.dark_background', 'cms.builder.var.dark_background', 'cms.builder.var.group.colors', 'color'],
            ['brand.dark_surface', 'cms.builder.var.dark_surface', 'cms.builder.var.dark_surface', 'cms.builder.var.group.colors', 'color'],
            ['brand.dark_header', 'cms.builder.var.dark_header', 'cms.builder.var.dark_header', 'cms.builder.var.group.colors', 'color'],
            ['layout.gap', 'cms.builder.var.layout_gap', 'cms.builder.var.layout_gap_hint', 'cms.builder.var.group.numbers', 'number'],
            ['layout.columns', 'cms.builder.var.layout_columns', 'cms.builder.var.layout_columns_hint', 'cms.builder.var.group.numbers', 'number'],
        ];

        $catalog = [];
        foreach ($defs as [$path, $labelKey, $hintKey, $groupKey, $kind]) {
            $example = $values[$path] ?? '';
            $catalog[] = [
                'path' => $path,
                'label' => $this->translator->trans($labelKey),
                'description' => $this->translator->trans($hintKey),
                'group' => $this->translator->trans($groupKey),
                'kind' => $kind,
                'example' => $example,
                'token' => '{{' . $path . '}}',
            ];
        }

        return ['catalog' => $catalog, 'values' => $values];
    }

    private function sanitizeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }
        $clean = $this->html->sanitize((string) $body);

        return $clean !== '' ? $clean : null;
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
