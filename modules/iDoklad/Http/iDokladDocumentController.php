<?php

declare(strict_types=1);

namespace Modules\iDoklad\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\iDoklad\Application\iDokladService;
use Modules\iDoklad\Domain\iDokladLink;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class iDokladDocumentController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
        private readonly iDokladService $iDoklad,
        private readonly EntityManagerInterface $em,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $types = $this->iDoklad->documentTypes();
        $typeKey = trim((string) $request->query->get('type', 'issued_invoice'));
        $meta = $this->iDoklad->resolveDocumentType($typeKey);
        if ($meta === null && $types !== []) {
            $meta = $types[0];
            $typeKey = $meta['key'];
        }

        $search = trim((string) $request->query->get('q', ''));
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = 20;

        $items = [];
        $total = 0;
        $pages = 1;
        $error = null;
        $enabled = $this->iDoklad->isEnabled();

        if (!$enabled) {
            $error = $this->translator->trans('idoklad.error.not_configured');
        } elseif ($meta !== null) {
            $result = $this->iDoklad->listDocuments($typeKey, $page, $perPage, $search);
            if ($result['isSucceed']) {
                $items = $this->normalizeListRows($result['items']);
                $total = max(0, $result['totalItems']);
                $pages = max(1, $result['totalPages'] > 0 ? $result['totalPages'] : (int) ceil($total / $perPage));
            } else {
                $message = (string) ($result['message'] ?? 'idoklad.documents.load_failed');
                $error = str_starts_with($message, 'idoklad.')
                    ? $this->translator->trans($message)
                    : $message;
            }
        }

        return $this->page('@idoklad/documents/index.html.twig', [
            'pageTitle' => $this->translator->trans('idoklad.documents.title'),
            'types' => $types,
            'type' => $typeKey,
            'typeMeta' => $meta,
            'items' => $items,
            'search' => $search,
            'page' => $page,
            'pages' => $pages,
            'perPage' => $perPage,
            'total' => $total,
            'enabled' => $enabled,
            'error' => $error,
            'list_query' => new class($typeKey, $search, $page) {
                public function __construct(
                    private readonly string $type,
                    private readonly string $search,
                    private readonly int $page,
                ) {
                }

                /** @return array<string, scalar> */
                public function params(): array
                {
                    return array_filter([
                        'type' => $this->type,
                        'q' => $this->search,
                        'page' => $this->page,
                    ], static fn ($v) => $v !== '' && $v !== null);
                }
            },
        ]);
    }

    public function show(string $type, string $id): Response
    {
        $meta = $this->iDoklad->resolveDocumentType($type);
        if ($meta === null) {
            $this->abort(404, $this->translator->trans('idoklad.error.unknown_document_type'));
        }

        $documentId = (int) $id;
        if ($documentId <= 0) {
            $this->abort(404, $this->translator->trans('idoklad.documents.not_found'));
        }

        if (!$this->iDoklad->isEnabled()) {
            $this->flash('error', $this->translator->trans('idoklad.error.not_configured'));

            return $this->redirect('admin.idoklad.documents');
        }

        $response = $this->iDoklad->getDocument($documentId, $meta['key']);
        if (!($response['isSucceed'] ?? false) || !is_array($response['data'] ?? null)) {
            $message = (string) ($response['message'] ?? 'idoklad.documents.not_found');
            $this->flash('error', str_starts_with($message, 'idoklad.')
                ? $this->translator->trans($message)
                : $message);

            return $this->redirectTo($this->router->url('admin.idoklad.documents') . '?type=' . rawurlencode($meta['key']));
        }

        /** @var array<string, mixed> $data */
        $data = $response['data'];
        $links = $this->em->getRepository(iDokladLink::class)->findBy([
            'iDokladId' => (string) $documentId,
        ]);

        return $this->page('@idoklad/documents/show.html.twig', [
            'pageTitle' => $this->documentTitle($data, $meta['label']),
            'type' => $meta['key'],
            'typeMeta' => $meta,
            'document' => $data,
            'documentId' => $documentId,
            'links' => $links,
            'canPdf' => !empty($meta['pdf']),
        ]);
    }

    public function pdf(string $type, string $id): Response
    {
        $meta = $this->iDoklad->resolveDocumentType($type);
        if ($meta === null || empty($meta['pdf'])) {
            $this->abort(404, $this->translator->trans('idoklad.error.unknown_document_type'));
        }

        $documentId = (int) $id;
        if ($documentId <= 0 || !$this->iDoklad->isEnabled()) {
            $this->abort(404, $this->translator->trans('idoklad.documents.not_found'));
        }

        $binary = $this->iDoklad->downloadDocumentPdf($documentId, $meta['key']);
        if ($binary === null || $binary === '') {
            $this->flash('error', $this->translator->trans('idoklad.documents.pdf_failed'));

            return $this->redirectTo($this->router->url('admin.idoklad.documents.show', [
                'type' => $meta['key'],
                'id' => (string) $documentId,
            ]));
        }

        $filename = sprintf('idoklad-%s-%d.pdf', $meta['key'], $documentId);

        return new Response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            'Content-Length' => (string) strlen($binary),
        ]);
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function normalizeListRows(array $items): array
    {
        $rows = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['Id'] ?? $item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $total = $item['TotalWithVat']
                ?? $item['TotalAmount']
                ?? $item['Total']
                ?? $item['Price']
                ?? $item['totalWithVat']
                ?? null;
            $date = (string) (
                $item['DateOfIssue']
                ?? $item['DateOfTransaction']
                ?? $item['DateOfPayment']
                ?? $item['dateOfIssue']
                ?? ''
            );
            $partner = $item['Partner'] ?? $item['partner'] ?? null;
            $partnerName = '';
            if (is_array($partner)) {
                $partnerName = (string) ($partner['CompanyName'] ?? $partner['Name'] ?? $partner['companyName'] ?? '');
            }
            if ($partnerName === '') {
                $partnerName = (string) ($item['PartnerName'] ?? $item['CompanyName'] ?? '');
            }

            $rows[] = [
                'id' => $id,
                'number' => (string) ($item['DocumentNumber'] ?? $item['documentNumber'] ?? $item['VariableSymbol'] ?? $id),
                'date' => $date !== '' ? substr($date, 0, 10) : '',
                'partner' => $partnerName,
                'description' => (string) ($item['Description'] ?? $item['Name'] ?? $item['description'] ?? ''),
                'total' => is_numeric($total) ? number_format((float) $total, 2, '.', '') : (string) ($total ?? '—'),
                'currency' => (string) ($item['CurrencyCode'] ?? $item['currencyCode'] ?? 'CZK'),
                'paid' => (bool) ($item['IsPaid'] ?? $item['isPaid'] ?? false),
                'raw' => $item,
            ];
        }

        return $rows;
    }

    /** @param array<string, mixed> $data */
    private function documentTitle(array $data, string $fallbackLabel): string
    {
        $number = (string) ($data['DocumentNumber'] ?? $data['documentNumber'] ?? '');
        $label = $this->translator->trans($fallbackLabel);
        if ($number !== '') {
            return $label . ' ' . $number;
        }

        return $label;
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
