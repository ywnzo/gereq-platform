<?php

declare(strict_types=1);

namespace Modules\ESign\Http;

use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\ESign\Application\DocumentRenderer;
use Modules\ESign\Application\DocumentSigningService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class PublicSignController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly DocumentSigningService $signing,
        private readonly DocumentRenderer $renderer,
        private readonly TranslatorInterface $translator,
        private readonly EventBus $events,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(string $token): Response
    {
        $resolved = $this->signing->resolve($token);
        $status = $resolved['status'];

        if ($status === 'ok' && $resolved['document'] !== null && $resolved['link'] !== null) {
            $document = $resolved['document'];
            $html = $this->renderer->wrapDocument(
                (string) $document->lockedHtml(),
                $document->title(),
                $document->number(),
            );
            $response = $this->render('@esign/public/sign.html.twig', [
                'pageTitle' => $this->translator->trans('esign.public.title'),
                'token' => $token,
                'document' => $document,
                'link' => $resolved['link'],
                'documentHtml' => $html,
                'consents' => $this->signing->consentTexts(),
                'lockedHash' => $document->lockedHash(),
            ]);
        } elseif ($status === 'signed' && $resolved['document'] !== null) {
            $response = $this->render('@esign/public/complete.html.twig', [
                'pageTitle' => $this->translator->trans('esign.public.complete_title'),
                'document' => $resolved['document'],
            ], 200);
        } else {
            $code = in_array($status, ['expired', 'revoked'], true) ? 410 : 404;
            $response = $this->render('@esign/public/unavailable.html.twig', [
                'pageTitle' => $this->translator->trans('esign.public.unavailable_title'),
                'message' => $this->translator->trans($this->signing->unavailableMessage($status)),
            ], $code);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    public function store(Request $request, string $token): Response
    {
        $data = $this->validate($request, 'esign.public.sign');

        if (trim((string) $request->request->get('website_url', '')) !== '') {
            $this->abort(422, 'Spam');
        }

        try {
            $signature = $this->signing->signAsCounterparty($token, $request, (string) $data['signature']);
            $this->events->emit('esign.document_signed', [
                'type' => 'esign_document',
                'id' => $signature->documentId(),
            ]);
            $this->flash('success', $this->translator->trans('esign.public.signed_flash'));
        } catch (\Throwable $e) {
            $this->flash('error', $this->translator->trans($e->getMessage()));

            return $this->redirectTo($this->router->url('esign.sign', ['token' => $token], $request));
        }

        return $this->redirectTo($this->router->url('esign.sign', ['token' => $token], $request));
    }
}
