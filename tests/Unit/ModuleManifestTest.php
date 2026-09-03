<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

final class ModuleManifestTest extends TestCase
{
    public function testFromArray(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'customers',
            'name' => 'Customers',
            'version' => '1.0.0',
            'class' => 'Modules\\Customers\\CustomersModule',
            'requires' => ['core' => '^1.0'],
            'provides' => ['contacts'],
            'permissions' => [
                ['name' => 'customers.view', 'label' => 'Zobrazit', 'group' => 'Zákazníci'],
            ],
        ], '/tmp/customers');

        self::assertSame('customers', $manifest->id);
        self::assertSame(['contacts'], $manifest->provides);
        self::assertCount(1, $manifest->permissions);
    }
}
