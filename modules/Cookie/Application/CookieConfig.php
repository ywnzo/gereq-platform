<?php

declare(strict_types=1);

namespace Modules\Cookie\Application;

use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;

final class CookieConfig
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /** @return array<string, array{type: string, default: mixed, section: string}> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach ([
            'enabled' => [true, 'general'],
            'floating_button' => [true, 'appearance'],
            'position' => ['right', 'appearance'],
            'style' => ['card', 'appearance'],
            'icon' => ['🍪', 'appearance'],
            'image' => ['', 'appearance'],
            'show_on_mobile' => [true, 'appearance'],
            'accent' => ['#cdea63', 'appearance'],
            'background' => ['#ffffff', 'appearance'],
            'color' => ['#252c27', 'appearance'],
            'lifetime_days' => [180, 'general'],
            'storage_key' => ['cookie-consent', 'general'],
            'policy_path' => ['/ochrana-osobnich-udaju/', 'general'],
            'title' => ['Vaše soukromí, vaše volba', 'texts'],
            'text' => ['Nezbytné cookies zajišťují chod webu. Volitelné měření a záznam používání webu spouštíme pouze s vaším souhlasem. Obsah formulářových polí nezaznamenáváme.', 'texts'],
            'btn_allow_all' => ['Povolit vše', 'texts'],
            'btn_deny' => ['Pouze nezbytné', 'texts'],
            'btn_settings' => ['Nastavit výběr', 'texts'],
            'btn_save' => ['Uložit výběr', 'texts'],
            'close_label' => ['Zavřít', 'texts'],
            'error_text' => ['Volbu nelze uložit. Povolte ukládání dat webu v prohlížeči.', 'texts'],
            'settings_label' => ['Nastavení cookies', 'texts'],
            'policy_label' => ['Ochrana osobních údajů', 'texts'],
        ] as $key => [$default, $section]) {
            $definitions['cookie.' . $key] = [
                'type' => is_bool($default) ? SettingRecord::TYPE_BOOLEAN : (is_int($default) ? SettingRecord::TYPE_INTEGER : SettingRecord::TYPE_STRING),
                'default' => $default, 'section' => $section,
            ];
        }
        foreach ([
            'necessary' => [true, 'Nezbytné', 'Chod webu, zabezpečení a uložení této volby. Jsou vždy aktivní.'],
            'preferences' => [false, 'Preferenční', 'Zapamatování volitelných nastavení rozhraní.'],
            'statistics' => [true, 'Statistické', 'Měření návštěvnosti a průběhu práce s formulářem bez obsahu polí.'],
            'replay' => [true, 'Záznam návštěvy', 'Přehrávání klikání a posouvání webu. Formuláře a editovatelná pole jsou skryté.'],
            'marketing' => [false, 'Marketingové', 'Personalizace a měření reklamních kampaní.'],
        ] as $key => [$enabled, $title, $text]) {
            if ($key !== 'necessary') {
                $definitions['cookie.' . $key . '_enabled'] = ['type' => SettingRecord::TYPE_BOOLEAN, 'default' => $enabled, 'section' => $key];
            }
            $definitions['cookie.' . $key . '_title'] = ['type' => SettingRecord::TYPE_STRING, 'default' => $title, 'section' => $key];
            $definitions['cookie.' . $key . '_text'] = ['type' => SettingRecord::TYPE_STRING, 'default' => $text, 'section' => $key];
        }

        return $definitions;
    }

    public function storageKey(): string
    {
        $key = (string) $this->value('storage_key');

        return preg_match('/\A[a-zA-Z0-9_.-]{1,64}\z/D', $key) === 1 ? $key : 'cookie-consent';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $categories = [];
        foreach (['necessary', 'preferences', 'statistics', 'replay', 'marketing'] as $id) {
            if ($id === 'replay' && !$this->value('statistics_enabled')) {
                continue;
            }
            if ($id !== 'necessary' && !$this->value($id . '_enabled')) {
                continue;
            }
            $categories[] = ['id' => $id, 'label' => $this->value($id . '_title'), 'description' => $this->value($id . '_text'), 'required' => $id === 'necessary'];
        }
        $path = (string) $this->value('policy_path');
        if (!str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/[\x00-\x20\\\\]/', $path) === 1) {
            $path = '/ochrana-osobnich-udaju/';
        }
        $payload = [
            'enabled' => (bool) $this->value('enabled'),
            'storage_key' => $this->storageKey(),
            'lifetime_days' => max(1, min(365, (int) $this->value('lifetime_days'))),
            'policy_path' => $path,
            'policy_label' => $this->value('policy_label'),
            'title' => $this->value('title'),
            'text' => $this->value('text'),
            'settings_label' => $this->value('settings_label'),
            'close_label' => $this->value('close_label'),
            'error_text' => $this->value('error_text'),
            'buttons' => ['allow_all' => $this->value('btn_allow_all'), 'deny' => $this->value('btn_deny'), 'settings' => $this->value('btn_settings'), 'save' => $this->value('btn_save')],
            'categories' => $categories,
            'floating_button' => (bool) $this->value('floating_button'),
            'show_on_mobile' => (bool) $this->value('show_on_mobile'),
            'style' => in_array($this->value('style'), ['card', 'compact', 'square', 'banner'], true) ? $this->value('style') : 'card',
            'icon' => mb_substr((string) $this->value('icon'), 0, 16),
            'image_url' => preg_match('/\A[a-f0-9]{32}\.(?:png|jpg|webp|gif)\z/D', (string) $this->value('image')) === 1
                ? '/api/public/cookie/image/' . $this->value('image') : '',
            'position' => in_array($this->value('position'), ['left', 'right'], true) ? $this->value('position') : 'right',
            'appearance' => ['accent' => $this->color('accent'), 'background' => $this->color('background'), 'color' => $this->color('color')],
        ];
        $payload['version'] = substr(hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)), 0, 16);

        return $payload;
    }

    private function color(string $key): string
    {
        $value = (string) $this->value($key);

        return preg_match('/\A#[a-f0-9]{6}\z/Di', $value) === 1 ? $value : self::definitions()['cookie.' . $key]['default'];
    }

    private function value(string $key): mixed
    {
        return $this->settings->get('cookie.' . $key, self::definitions()['cookie.' . $key]['default']);
    }
}
