<?php

declare(strict_types=1);

return [
    'id' => 'customers',
    'name' => 'Customers',
    'version' => '1.0.0',
    'description' => 'Evidence zákazníků a firemních kontaktů.',
    'class' => Modules\Customers\CustomersModule::class,
    'author' => 'GEREQ',
    'requires' => [
        'core' => '^1.0',
    ],
    'optional' => [
        'notifications' => '^1.0',
    ],
    'provides' => [
        'contacts',
    ],
    'capabilities' => [
        'contacts',
    ],
    'permissions' => [
        ['name' => 'customers.view', 'label' => 'Zobrazit zákazníky', 'group' => 'Zákazníci'],
        ['name' => 'customers.create', 'label' => 'Vytvořit zákazníka', 'group' => 'Zákazníci'],
        ['name' => 'customers.edit', 'label' => 'Upravit zákazníka', 'group' => 'Zákazníci'],
        ['name' => 'customers.delete', 'label' => 'Smazat zákazníka', 'group' => 'Zákazníci'],
    ],
];
