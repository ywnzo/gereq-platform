<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

/**
 * Typy PDF dokumentů servisu (tělo/hlavička/patička = Twig v Resources/views/pdf/).
 */
final class PdfTemplateCatalog
{
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

    public static function documentTitle(string $documentType): string
    {
        return match ($documentType) {
            self::TYPE_WORKSHEET => 'Zakázkový list',
            self::TYPE_INTAKE => 'Protokol příjmu',
            self::TYPE_HANDOVER => 'Předávací protokol',
            self::TYPE_QUOTE => 'Nabídka oprav',
            self::TYPE_COST_SHEET => 'Nákladový list',
            default => 'Dokument',
        };
    }
}
