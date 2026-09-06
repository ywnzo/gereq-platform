<?php

declare(strict_types=1);

namespace Modules\ESign\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Modules\ESign\Application\DocumentSigningService;
use Modules\ESign\Application\SignatureStorage;
use Modules\ESign\Domain\SignableDocument;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DocumentController extends Controller
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
        private readonly DocumentSigningService $signing,
        private readonly SignatureStorage $signatures,
        private readonly Settings $settings,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['number', 'title', 'status', 'createdAt', 'signedAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
            filterKeys: ['status'],
        );
        $status = $list->filter('status');

        $qb = $this->em->getRepository(SignableDocument::class)->createQueryBuilder('d');
        $list->applySearch($qb, 'd', ['number', 'title', 'counterpartyName', 'counterpartyEmail']);
        if (in_array($status, SignableDocument::STATUSES, true)) {
            $qb->andWhere('d.status = :s')->setParameter('s', $status);
        }
        $qb->orderBy('d.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@esign/documents/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('esign.documents.title'),
            'items' => $items,
            'status' => $status,
            'statuses' => SignableDocument::STATUSES,
        ]));
    }

    public function create(): Response
    {
        return $this->page('@esign/documents/form.html.twig', [
            'pageTitle' => $this->translator->trans('esign.documents.new'),
            'item' => null,
            'defaultBody' => $this->defaultBody(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $document = $this->signing->create(
            $data['title'],
            $data['body_html'],
            $data['counterparty_name'],
            $data['counterparty_email'],
            $data['counterparty_company'],
            $data['number'] !== '' ? $data['number'] : null,
        );
        $this->events->emit('esign.document_created', ['type' => 'esign_document', 'id' => $document->id()]);
        $this->auditor->log('esign.document.created', 'esign_document', $document->id());
        $this->forgetForm('esign.document.save');
        $this->flash('success', $this->translator->trans('esign.flash.created'));

        return $this->redirect('admin.esign.documents.show', ['id' => $document->id()]);
    }

    public function show(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }

        return $this->page('@esign/documents/show.html.twig', [
            'pageTitle' => $item->number(),
            'item' => $item,
            'signatures' => $this->signing->signaturesFor($item->id()),
            'links' => $this->signing->linksFor($item->id()),
            'hasProvider' => $this->signing->hasProviderSignature($item),
            'previewHtml' => $this->signingPreview($item),
            'providerName' => (string) $this->settings->get('esign.provider_name', 'GEREQ'),
        ]);
    }

    public function edit(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }
        if ($item->isLocked() || $item->isFullySigned()) {
            $this->flash('error', $this->translator->trans('esign.error.locked'));

            return $this->redirect('admin.esign.documents.show', ['id' => $id]);
        }

        return $this->page('@esign/documents/form.html.twig', [
            'pageTitle' => $this->translator->trans('esign.documents.edit'),
            'item' => $item,
            'defaultBody' => $this->defaultBody(),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }
        try {
            $data = $this->validated($request);
            $item->setTitle($data['title']);
            $item->setBodyHtml($data['body_html']);
            $item->setCounterpartyName($data['counterparty_name']);
            $item->setCounterpartyEmail($data['counterparty_email']);
            $item->setCounterpartyCompany($data['counterparty_company']);
            $this->em->flush();
        } catch (\RuntimeException $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirect('admin.esign.documents.show', ['id' => $id]);
        }
        $this->auditor->log('esign.document.updated', 'esign_document', $id);
        $this->forgetForm('esign.document.save');
        $this->flash('success', $this->translator->trans('esign.flash.saved'));

        return $this->redirect('admin.esign.documents.show', ['id' => $id]);
    }

    public function signProvider(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }
        try {
            $png = trim((string) $request->request->get('signature', ''));
            $this->signing->applyProviderSignature($item, $png !== '' ? $png : null);
            $this->auditor->log('esign.document.provider_signed', 'esign_document', $id);
            $this->flash('success', $this->translator->trans('esign.flash.provider_signed'));
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));
        }

        return $this->redirect('admin.esign.documents.show', ['id' => $id]);
    }

    public function send(Request $request, string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }
        $email = trim((string) $request->request->get('email', $item->counterpartyEmail() ?? ''));
        try {
            $result = $this->signing->sendForSignature($item, $email, $request);
            $this->events->emit('esign.document_sent', ['type' => 'esign_document', 'id' => $id, 'email' => $email]);
            $this->auditor->log('esign.document.sent', 'esign_document', $id);
            $this->flash('success', $this->translator->trans('esign.flash.sent', ['%url%' => $result['url']]));
            $session = $request->getSession();
            $session->set('esign.last_signing_url.' . $id, $result['url']);
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));
        }

        return $this->redirect('admin.esign.documents.show', ['id' => $id]);
    }

    public function cancel(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }
        try {
            $this->signing->cancel($item);
            $this->auditor->log('esign.document.cancelled', 'esign_document', $id);
            $this->flash('success', $this->translator->trans('esign.flash.cancelled'));
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));
        }

        return $this->redirect('admin.esign.documents.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $item = $this->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('esign.error.not_found'));
        }
        if ($item->isFullySigned()) {
            $this->flash('error', $this->translator->trans('esign.error.already_signed'));

            return $this->redirect('admin.esign.documents.show', ['id' => $id]);
        }
        $this->em->remove($item);
        $this->em->flush();
        $this->auditor->log('esign.document.deleted', 'esign_document', $id);
        $this->flash('success', $this->translator->trans('esign.flash.deleted'));

        return $this->redirect('admin.esign.documents.index');
    }

    public function settings(): Response
    {
        $path = (string) $this->settings->get('esign.provider_signature_path', '');

        return $this->page('@esign/settings.html.twig', [
            'pageTitle' => $this->translator->trans('esign.settings.title'),
            'providerName' => (string) $this->settings->get('esign.provider_name', 'GEREQ'),
            'providerEmail' => (string) $this->settings->get('esign.provider_email', ''),
            'providerSignaturePath' => $path,
            'providerSignaturePreview' => $this->signatures->dataUri($path !== '' ? $path : null),
            'linkTtlDays' => (int) $this->settings->get('esign.link_ttl_days', 14),
            'consentVersion' => (string) $this->settings->get('esign.consent_version', '2026-09-06'),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $data = $this->validate($request, 'esign.settings');
        $this->settings->set('esign.provider_name', trim((string) $data['provider_name']), SettingRecord::TYPE_STRING, 'esign');
        $this->settings->set('esign.provider_email', trim((string) $data['provider_email']), SettingRecord::TYPE_STRING, 'esign');
        $this->settings->set('esign.link_ttl_days', max(1, (int) $data['link_ttl_days']), SettingRecord::TYPE_INTEGER, 'esign');
        $this->settings->set('esign.consent_version', trim((string) $data['consent_version']), SettingRecord::TYPE_STRING, 'esign');

        $png = trim((string) $request->request->get('signature', ''));
        if ($png !== '') {
            try {
                $this->signing->saveDefaultProviderSignature($png);
            } catch (\Throwable $e) {
                $this->flash('error', $this->translator->trans($e->getMessage()));

                return $this->redirect('admin.esign.settings');
            }
        }

        $this->forgetForm('esign.settings');
        $this->flash('success', $this->translator->trans('esign.flash.settings_saved'));

        return $this->redirect('admin.esign.settings');
    }

    private function find(string $id): ?SignableDocument
    {
        $item = $this->em->find(SignableDocument::class, $id);

        return $item instanceof SignableDocument ? $item : null;
    }

    /** @return array{title: string, body_html: string, counterparty_name: string, counterparty_email: string, counterparty_company: string, number: string} */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'esign.document.save');

        return [
            'title' => trim((string) $data['title']),
            'body_html' => (string) $data['body_html'],
            'counterparty_name' => trim((string) $data['counterparty_name']),
            'counterparty_email' => trim((string) ($data['counterparty_email'] ?? '')),
            'counterparty_company' => trim((string) ($data['counterparty_company'] ?? '')),
            'number' => trim((string) ($data['number'] ?? '')),
        ];
    }

    private function defaultBody(): string
    {
        return '<h1>{{ title }}</h1>'
            . '<p>Číslo: <strong>{{ number }}</strong></p>'
            . '<p>Protistrana: <strong>{{ counterparty_name }}</strong> ({{ counterparty_company }})</p>'
            . '<p>Text smlouvy / dokumentu…</p>'
            . '<h2>Podpisy</h2>'
            . '<table style="width:100%"><tr>'
            . '<td style="width:50%;vertical-align:top"><p><strong>Poskytovatel</strong></p>{{ provider_signature }}</td>'
            . '<td style="width:50%;vertical-align:top"><p><strong>Protistrana</strong></p>{{ counterparty_signature }}</td>'
            . '</tr></table>';
    }

    private function signingPreview(SignableDocument $item): string
    {
        $renderer = gereq()->make(\Modules\ESign\Application\DocumentRenderer::class);

        if (
            $item->sourceType() === \Modules\ESign\Application\ESignProvider::SOURCE_CARSERVICE_ORDER
            && $item->sourceId() !== null
            && $item->sourceId() !== ''
            && class_exists(\Modules\CarService\Application\OrderEsignService::class)
        ) {
            try {
                $rebuilt = gereq()
                    ->make(\Modules\CarService\Application\OrderEsignService::class)
                    ->previewSignedHandoverHtml($item);
                if (is_string($rebuilt) && $rebuilt !== '') {
                    return $rebuilt;
                }
            } catch (\Throwable) {
                // fallback na uložené body
            }
        }

        return $renderer->wrapDocument($renderer->documentBody($item), $item->title(), $item->number());
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();
        $request = $this->request();
        $lastUrl = null;
        if (isset($data['item']) && $data['item'] instanceof SignableDocument && $request !== null) {
            $lastUrl = $request->getSession()->get('esign.last_signing_url.' . $data['item']->id());
        }

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $request),
            'commands' => $this->palette->all($user),
            'user' => $user,
            'lastSigningUrl' => $lastUrl,
        ]));
    }
}
