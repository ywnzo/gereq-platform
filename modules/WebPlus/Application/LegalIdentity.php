<?php

declare(strict_types=1);

namespace Modules\WebPlus\Application;

use Gereq\Setting\Settings;
use Gereq\Module\CapabilityRegistry;

/** Controller identity from WebPlus admin — used in CMS variables and public legal variables. */
final class LegalIdentity
{
    public function __construct(private readonly Settings $settings, private readonly ?CapabilityRegistry $capabilities = null)
    {
    }

    /** @return array{name: string, address: string, ico: string, dic: string, email: string, phone: string} */
    public function controller(): array
    {
        return [
            'name' => $this->str('webplus.legal_controller_name'),
            'address' => $this->str('webplus.legal_controller_address'),
            'ico' => $this->str('webplus.legal_controller_ico'),
            'dic' => $this->str('webplus.legal_controller_dic'),
            'email' => $this->str('webplus.legal_controller_email'),
            'phone' => $this->str('webplus.legal_controller_phone'),
        ];
    }

    public function privacyPath(): string
    {
        $path = $this->str('webplus.privacy_path');

        return $path !== '' ? $path : '/stranka/ochrana-osobnich-udaju';
    }

    public function cookiesPath(): string
    {
        if ($this->capabilities?->has('cookie')) {
            $provider = $this->capabilities->get('cookie');
            if (method_exists($provider, 'payload')) {
                return (string) ($provider->payload()['policy_path'] ?? $this->privacyPath());
            }
        }

        return $this->privacyPath();
    }

    /** @return array<string, string> */
    public function cmsVariables(): array
    {
        $c = $this->controller();
        $name = $c['name'] !== '' ? $c['name'] : 'GEREQ';
        $lines = [$this->escape($name)];
        if ($c['address'] !== '') {
            $lines[] = $this->escape($c['address']);
        }
        if ($c['ico'] !== '') {
            $lines[] = 'IČO: ' . $this->escape($c['ico']);
        }
        if ($c['dic'] !== '') {
            $lines[] = 'DIČ: ' . $this->escape($c['dic']);
        }
        if ($c['email'] !== '') {
            $mail = $this->escape($c['email']);
            $lines[] = 'E-mail: <a href="mailto:' . $mail . '">' . $mail . '</a>';
        }
        if ($c['phone'] !== '') {
            $lines[] = 'Telefon: ' . $this->escape($c['phone']);
        }
        if ($c['address'] === '' && $c['ico'] === '' && $c['email'] === '') {
            $lines[] = 'Doplňte sídlo, IČO a kontaktní e-mail správce v administraci (WebPlus → nastavení webu → GDPR). Bez těchto údajů informace podle čl.&nbsp;13 GDPR nejsou úplné.';
        }

        return [
            'legal.controller_name' => $name,
            'legal.controller_address' => $c['address'],
            'legal.controller_ico' => $c['ico'],
            'legal.controller_dic' => $c['dic'],
            'legal.controller_email' => $c['email'],
            'legal.controller_phone' => $c['phone'],
            'legal.controller_html' => implode('<br>', $lines),
            'legal.privacy_path' => $this->privacyPath(),
            'legal.cookies_path' => $this->cookiesPath(),
        ];
    }

    private function str(string $key): string
    {
        return trim((string) $this->settings->get($key, ''));
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
