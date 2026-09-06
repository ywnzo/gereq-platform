<?php

declare(strict_types=1);

namespace Modules\BikeService\Application;

/**
 * Typy PDF dokumentů bike servisu.
 */
final class PdfTemplateCatalog
{
    public const TYPE_WORKSHEET = 'worksheet';
    public const TYPE_INTAKE = 'intake';
    public const TYPE_HANDOVER = 'handover';

    /** @return list<string> */
    public static function types(): array
    {
        return [
            self::TYPE_WORKSHEET,
            self::TYPE_INTAKE,
            self::TYPE_HANDOVER,
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
                'label' => 'bikeservice.documents.type.' . $type,
                'hint' => 'bikeservice.documents.type.' . $type . '.hint',
            ];
        }

        return $out;
    }

    public static function filenameSlug(string $type): string
    {
        return match ($type) {
            self::TYPE_WORKSHEET => 'zakazkovy-list',
            self::TYPE_INTAKE => 'protokol-prijmu',
            self::TYPE_HANDOVER => 'predavaci-protokol',
            default => $type,
        };
    }

    public static function documentTitleKey(string $type): string
    {
        return 'bikeservice.documents.type.' . $type;
    }
}
