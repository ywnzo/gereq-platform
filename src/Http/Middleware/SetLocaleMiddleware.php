<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\I18n\LocaleResolver;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

final class SetLocaleMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly LocaleResolver $resolver,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        $locale = $this->resolver->resolve();
        $request->setLocale($locale);
        $request->attributes->set('_locale', $locale);

        if ($this->translator instanceof LocaleAwareInterface) {
            $this->translator->setLocale($locale);
        }

        if (function_exists('locale_set_default')) {
            @locale_set_default($locale);
        }

        return $next($request);
    }
}
