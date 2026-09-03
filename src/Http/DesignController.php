<?php

declare(strict_types=1);

namespace Gereq\Http;

use Gereq\Validation\Validator;
use Symfony\Component\HttpFoundation\Request;

final class DesignController extends Controller
{
    public function show(): \Symfony\Component\HttpFoundation\Response
    {
        return $this->render('design/index.html.twig', [
            'pageTitle' => 'Design system',
        ]);
    }

    public function submit(Request $request, Validator $validator): \Symfony\Component\HttpFoundation\Response
    {
        $validator->validateRequest($request, 'design.demo');
        $this->forgetForm('design.demo');
        $this->flash('success', 'Formulář prošel validací. Stav byl vyčištěn.');

        return $this->redirectTo('/design.html#formular');
    }
}
