<?php

declare(strict_types=1);

namespace Gereq\Tests\Support;

use Symfony\Contracts\Translation\TranslatorInterface;

/** Minimal translator: returns the message id / given string unchanged. */
final class PassthroughTranslator implements TranslatorInterface
{
    public function trans(string $id, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        $out = $id;
        foreach ($parameters as $key => $value) {
            $out = str_replace('%' . $key . '%', (string) $value, $out);
            $out = str_replace('{' . $key . '}', (string) $value, $out);
        }

        return $out;
    }

    public function getLocale(): string
    {
        return 'cs';
    }
}
