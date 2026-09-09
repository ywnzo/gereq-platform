<?php

declare(strict_types=1);

namespace Modules\Ai\Application;

use Doctrine\ORM\EntityManagerInterface;
use Modules\Ai\Application\Tools\CustomToolRuntime;
use Modules\Ai\Domain\AiCustomTool;

final class CustomToolCatalog
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** @return list<CustomToolRuntime> */
    public function enabledRuntimes(): array
    {
        $rows = $this->em->getRepository(AiCustomTool::class)->findBy(['enabled' => true], ['name' => 'ASC']);
        $out = [];
        foreach ($rows as $row) {
            if ($row instanceof AiCustomTool) {
                $out[] = new CustomToolRuntime($row);
            }
        }

        return $out;
    }

    public function runtimeByName(string $name): ?CustomToolRuntime
    {
        $row = $this->em->getRepository(AiCustomTool::class)->findOneBy(['name' => $name, 'enabled' => true]);

        return $row instanceof AiCustomTool ? new CustomToolRuntime($row) : null;
    }

    public static function normalizeName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_.]+/', '_', $name) ?? $name;
        $name = trim($name, '._');
        if ($name === '') {
            return 'custom.tool';
        }
        if (!str_contains($name, '.')) {
            $name = 'custom.' . $name;
        }

        return $name;
    }
}
