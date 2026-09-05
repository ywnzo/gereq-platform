<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Validation\Validator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

final class DesignController extends Controller
{
    public function __construct(
        \Twig\Environment $twig,
        Router $router,
        \Symfony\Component\HttpFoundation\RequestStack $requestStack,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('design/index.html.twig', [
            'pageTitle' => $this->translator->trans('design.page_title'),
        ]);
    }

    public function submit(Request $request, Validator $validator): \Symfony\Component\HttpFoundation\Response
    {
        $validator->validateRequest($request, 'design.demo');
        $this->forgetForm('design.demo');
        $this->flash('success', $this->translator->trans('design.flash.validated'));

        return $this->redirectTo('/design.html#formular');
    }
}
