<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('portal_providers')) {
            return;
        }
        $t = $schema->getTable('portal_providers');
        if (!$t->hasColumn('api_spec')) {
            $t->addColumn('api_spec', Types::JSON)->setNotnull(false);
        }
    }

    public function afterUp(Connection $connection): void
    {
        $sm = $connection->createSchemaManager();
        if (!$sm->tablesExist(['portal_providers'])) {
            return;
        }
        $columns = array_map(
            static fn ($c) => $c->getName(),
            $sm->listTableColumns('portal_providers'),
        );
        if (!in_array('api_spec', $columns, true)) {
            return;
        }

        $specPath = dirname(__DIR__, 2) . '/Resources/sreality/api_spec.php';
        $spec = is_file($specPath) ? require $specPath : [];
        if (!is_array($spec) || $spec === []) {
            return;
        }

        $fields = [
            ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'number', 'required' => true, 'hint' => 'ID klienta přidělené Sreality', 'placeholder' => '12345'],
            ['key' => 'password', 'label' => 'Heslo (MD5)', 'type' => 'password', 'required' => true, 'hint' => 'MD5 hash hesla k importnímu rozhraní', 'placeholder' => ''],
            ['key' => 'software_key', 'label' => 'Importní klíč', 'type' => 'password', 'required' => true, 'hint' => 'Softwarový klíč přidělený Sreality', 'placeholder' => ''],
            ['key' => 'advert_lifetime', 'label' => 'Platnost inzerátu', 'type' => 'number', 'required' => false, 'hint' => 'Číselník advert_lifetime (3 = 30 dní)', 'placeholder' => '3'],
            ['key' => 'seller_rkid', 'label' => 'Makléř RK ID', 'type' => 'text', 'required' => false, 'hint' => 'Volitelné seller_rkid', 'placeholder' => ''],
            ['key' => 'locality_inaccuracy_level', 'label' => 'Znepřesnění adresy', 'type' => 'number', 'required' => false, 'hint' => '1=přesně, 2=ulice/obec, 3=část města', 'placeholder' => '1'],
        ];

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $endpoint = (string) ($spec['endpoint'] ?? 'https://import.sreality.cz/RPC2');
        $description = 'Importní XML-RPC rozhraní Sreality (v' . ($spec['version'] ?? '3.0.0') . '). Endpoint: ' . $endpoint;

        $existing = $connection->fetchOne('SELECT id FROM portal_providers WHERE code = ?', ['sreality']);
        $payload = [
            'name' => 'Sreality',
            'driver' => 'sreality',
            'description' => $description,
            'api_base_url' => $endpoint,
            'api_spec' => json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'fields' => json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'is_active' => true,
            'sort_order' => 10,
            'updated_at' => $now,
        ];

        if (is_string($existing) && $existing !== '') {
            $sets = [];
            $values = [];
            foreach ($payload as $col => $val) {
                $sets[] = $col . ' = ?';
                $values[] = $val;
            }
            $values[] = $existing;
            $connection->executeStatement(
                'UPDATE portal_providers SET ' . implode(', ', $sets) . ' WHERE id = ?',
                $values,
            );

            return;
        }

        $row = array_merge([
            'id' => \Ramsey\Uuid\Uuid::uuid7()->toString(),
            'code' => 'sreality',
            'created_at' => $now,
        ], $payload);
        if (in_array('openapi_url', $columns, true)) {
            $row['openapi_url'] = null;
        }
        $connection->insert('portal_providers', $row);
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('portal_providers')) {
            return;
        }
        $t = $schema->getTable('portal_providers');
        if ($t->hasColumn('api_spec')) {
            $t->dropColumn('api_spec');
        }
    }
};
