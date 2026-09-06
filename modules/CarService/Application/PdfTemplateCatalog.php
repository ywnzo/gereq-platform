<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

/**
 * Typy PDF dokumentů servisu + klíče nastavení pro hlavičku/patičku.
 */
final class PdfTemplateCatalog
{
    public const PART_HEADER = 'header';

    public const PART_FOOTER = 'footer';

    /** Zakázkový list (generuje se při dokončení). */
    public const TYPE_WORKSHEET = 'worksheet';

    /** Protokol příjmu vozidla. */
    public const TYPE_INTAKE = 'intake';

    /** Předávací protokol. */
    public const TYPE_HANDOVER = 'handover';

    /** Nabídka oprav. */
    public const TYPE_QUOTE = 'quote';

    /** Nákladový list. */
    public const TYPE_COST_SHEET = 'cost_sheet';

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_WORKSHEET,
            self::TYPE_INTAKE,
            self::TYPE_HANDOVER,
            self::TYPE_QUOTE,
            self::TYPE_COST_SHEET,
        ];
    }

    public static function globalKey(string $part): string
    {
        return 'carservice.pdf.' . $part;
    }

    public static function typeKey(string $type, string $part): string
    {
        return 'carservice.pdf.' . $type . '.' . $part;
    }

    /** Form field name for settings POST (dots → underscores). */
    public static function formField(string $settingKey): string
    {
        return str_replace('.', '_', $settingKey);
    }

    /**
     * @return list<array{type: string, label: string, hint: string}>
     */
    public static function typeMeta(): array
    {
        $out = [];
        foreach (self::types() as $type) {
            $out[] = [
                'type' => $type,
                'label' => 'carservice.settings.pdf.type.' . $type,
                'hint' => 'carservice.settings.pdf.type.' . $type . '.hint',
            ];
        }

        return $out;
    }

    /**
     * Všechny setting klíče (global + per type × header/footer).
     *
     * @return list<string>
     */
    public static function allSettingKeys(): array
    {
        $keys = [
            self::globalKey(self::PART_HEADER),
            self::globalKey(self::PART_FOOTER),
        ];
        foreach (self::types() as $type) {
            $keys[] = self::typeKey($type, self::PART_HEADER);
            $keys[] = self::typeKey($type, self::PART_FOOTER);
        }

        return $keys;
    }

    /** Výchozí hodnota při define() — u type override prázdné, u společných hotová šablona. */
    public static function defaultValueForKey(string $settingKey): string
    {
        return match ($settingKey) {
            self::globalKey(self::PART_HEADER) => self::defaultHeaderHtml(),
            self::globalKey(self::PART_FOOTER) => self::defaultFooterHtml(),
            default => '',
        };
    }

    /**
     * Prázdné / staré vestavěné šablony → aktuální default.
     * Vlastní úpravy bez legacy markerů nechá beze změny.
     */
    public static function effectiveTemplate(string $stored, string $part): string
    {
        $stored = trim($stored);
        if ($stored === '' || self::isLegacyStock($stored)) {
            return $part === self::PART_HEADER
                ? self::defaultHeaderHtml()
                : self::defaultFooterHtml();
        }

        return $stored;
    }

    public static function isLegacyStock(string $html): bool
    {
        if (str_contains($html, 'gereq-pdf-chrome:2')) {
            return false;
        }

        return str_contains($html, 'Autoservis · zakázková dokumentace')
            || str_contains($html, 'zakázková dokumentace')
            || (
                str_contains($html, 'Dokument vygenerován elektronicky')
                && str_contains($html, 'záruční a reklamační')
            );
    }

    /** Společná hlavička — tiskový layout pro Dompdf. */
    public static function defaultHeaderHtml(): string
    {
        return <<<'HTML'
<!-- gereq-pdf-chrome:2 -->
<table style="width:100%;border-collapse:collapse;margin:0 0 22px 0;font-family:DejaVu Sans,sans-serif">
  <tr>
    <td colspan="2" style="height:5px;background:#1c1917;font-size:1px;line-height:5px">&nbsp;</td>
  </tr>
  <tr>
    <td style="vertical-align:bottom;padding:16px 14px 12px 0;width:58%">
      <div style="font-size:9px;letter-spacing:0.18em;text-transform:uppercase;color:#78716c;margin-bottom:6px">Autoservis</div>
      <div style="font-size:22px;font-weight:bold;color:#0c0a09;letter-spacing:-0.02em;line-height:1.15">{{brand}}</div>
    </td>
    <td style="vertical-align:bottom;padding:16px 0 12px 14px;text-align:right;width:42%">
      <div style="font-size:15px;font-weight:bold;color:#0c0a09;line-height:1.2">{{doc_title}}</div>
      <div style="font-size:10px;color:#57534e;margin-top:6px">Vystaveno {{date}}</div>
    </td>
  </tr>
  <tr>
    <td colspan="2" style="height:2px;background:#d6d3d1;font-size:1px;line-height:2px">&nbsp;</td>
  </tr>
</table>
HTML;
    }

    /** Společná patička — tiskový layout pro Dompdf. */
    public static function defaultFooterHtml(): string
    {
        return <<<'HTML'
<!-- gereq-pdf-chrome:2 -->
<table style="width:100%;border-collapse:collapse;margin:36px 0 0 0;font-family:DejaVu Sans,sans-serif">
  <tr>
    <td colspan="2" style="height:2px;background:#1c1917;font-size:1px;line-height:2px">&nbsp;</td>
  </tr>
  <tr>
    <td style="vertical-align:top;padding:12px 16px 0 0;width:52%">
      <div style="font-size:10px;font-weight:bold;color:#0c0a09;margin-bottom:4px">{{brand}}</div>
      <div style="font-size:8.5px;color:#78716c;line-height:1.5">
        Oficiální servisní dokumentace<br>
        Vygenerováno elektronicky · {{date}}
      </div>
    </td>
    <td style="vertical-align:top;padding:12px 0 0 16px;width:48%;text-align:right">
      <div style="font-size:8.5px;color:#78716c;line-height:1.55">
        Doklad o provedení servisních prací.<br>
        Uchovejte pro záruku a reklamace.<br>
        <span style="color:#a8a29e">Strana 1</span>
      </div>
    </td>
  </tr>
</table>
HTML;
    }
}
