<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use Ramsey\Uuid\Uuid;

return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('portal_providers')) {
            $t = $schema->createTable('portal_providers');
            $t->addColumn('id', Types::STRING)->setLength(36);
            $t->addColumn('code', Types::STRING)->setLength(64);
            $t->addColumn('name', Types::STRING)->setLength(190);
            $t->addColumn('driver', Types::STRING)->setLength(32);
            $t->addColumn('description', Types::TEXT)->setNotnull(false);
            $t->addColumn('api_base_url', Types::STRING)->setLength(500)->setNotnull(false);
            $t->addColumn('fields', Types::JSON);
            $t->addColumn('is_active', Types::BOOLEAN);
            $t->addColumn('sort_order', Types::INTEGER);
            $t->addColumn('created_at', Types::DATETIME_IMMUTABLE);
            $t->addColumn('updated_at', Types::DATETIME_IMMUTABLE);
            $t->setPrimaryKey(['id']);
            $t->addUniqueIndex(['code'], 'uniq_portal_providers_code');
            $t->addIndex(['is_active'], 'idx_portal_providers_active');
        }

        if ($schema->hasTable('portal_accounts')) {
            $accounts = $schema->getTable('portal_accounts');
            if (!$accounts->hasColumn('provider_id')) {
                $accounts->addColumn('provider_id', Types::STRING)->setLength(36)->setNotnull(false);
            }
            if (!$accounts->hasIndex('idx_portal_accounts_provider')) {
                $accounts->addIndex(['provider_id'], 'idx_portal_accounts_provider');
            }
        }
    }

    public function afterUp(Connection $connection): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $defs = $this->defaultProviders();
        $idsByCode = [];

        foreach ($defs as $def) {
            $existing = $connection->fetchOne(
                'SELECT id FROM portal_providers WHERE code = ?',
                [$def['code']],
            );
            if (is_string($existing) && $existing !== '') {
                $idsByCode[$def['code']] = $existing;
                $connection->executeStatement(
                    'UPDATE portal_providers SET name = ?, driver = ?, description = ?, api_base_url = ?, fields = ?, is_active = ?, sort_order = ?, updated_at = ? WHERE id = ?',
                    [
                        $def['name'],
                        $def['driver'],
                        $def['description'],
                        $def['api_base_url'],
                        json_encode($def['fields'], JSON_UNESCAPED_UNICODE),
                        true,
                        $def['sort_order'],
                        $now,
                        $existing,
                    ],
                );
                continue;
            }

            $id = Uuid::uuid7()->toString();
            $idsByCode[$def['code']] = $id;
            $connection->insert('portal_providers', [
                'id' => $id,
                'code' => $def['code'],
                'name' => $def['name'],
                'driver' => $def['driver'],
                'description' => $def['description'],
                'api_base_url' => $def['api_base_url'],
                'fields' => json_encode($def['fields'], JSON_UNESCAPED_UNICODE),
                'is_active' => true,
                'sort_order' => $def['sort_order'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $sm = $connection->createSchemaManager();
        if (!$sm->tablesExist(['portal_accounts'])) {
            return;
        }

        $columns = array_map(
            static fn ($c) => $c->getName(),
            $sm->listTableColumns('portal_accounts'),
        );

        if (in_array('provider', $columns, true) && in_array('provider_id', $columns, true)) {
            $rows = $connection->fetchAllAssociative('SELECT id, provider FROM portal_accounts WHERE provider_id IS NULL OR provider_id = \'\'');
            foreach ($rows as $row) {
                $code = (string) ($row['provider'] ?? 'custom');
                $providerId = $idsByCode[$code] ?? ($idsByCode['custom'] ?? null);
                if ($providerId === null) {
                    continue;
                }
                $connection->executeStatement(
                    'UPDATE portal_accounts SET provider_id = ? WHERE id = ?',
                    [$providerId, $row['id']],
                );
            }
        }

        // Fallback: any account still without provider_id → custom
        if (isset($idsByCode['custom']) && in_array('provider_id', $columns, true)) {
            $connection->executeStatement(
                'UPDATE portal_accounts SET provider_id = ? WHERE provider_id IS NULL OR provider_id = \'\'',
                [$idsByCode['custom']],
            );
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('portal_providers')) {
            $schema->dropTable('portal_providers');
        }
    }

    /** @return list<array<string, mixed>> */
    private function defaultProviders(): array
    {
        return [
            [
                'code' => 'sreality',
                'name' => 'Sreality',
                'driver' => 'sreality',
                'description' => 'Export nabídek na Sreality.cz',
                'api_base_url' => 'https://www.sreality.cz',
                'sort_order' => 10,
                'fields' => [
                    ['key' => 'account_id', 'label' => 'ID účtu RK', 'type' => 'text', 'required' => true, 'hint' => 'Identifikátor realitní kanceláře', 'placeholder' => 'RK-12345'],
                    ['key' => 'export_format', 'label' => 'Formát exportu', 'type' => 'text', 'required' => true, 'hint' => 'xml nebo api', 'placeholder' => 'xml'],
                    ['key' => 'api_key', 'label' => 'API klíč', 'type' => 'password', 'required' => false, 'hint' => 'Volitelné pro API sync', 'placeholder' => ''],
                ],
            ],
            [
                'code' => 'idnes',
                'name' => 'Idnes Reality',
                'driver' => 'idnes',
                'description' => 'Partner feed pro Idnes Reality',
                'api_base_url' => 'https://reality.idnes.cz',
                'sort_order' => 20,
                'fields' => [
                    ['key' => 'partner_id', 'label' => 'Partner ID', 'type' => 'text', 'required' => true, 'hint' => '', 'placeholder' => ''],
                    ['key' => 'feed_url', 'label' => 'URL feedu', 'type' => 'url', 'required' => false, 'hint' => 'Pokud používáte vlastní feed', 'placeholder' => 'https://…'],
                    ['key' => 'api_key', 'label' => 'API klíč', 'type' => 'password', 'required' => false, 'hint' => '', 'placeholder' => ''],
                ],
            ],
            [
                'code' => 'bezrealitky',
                'name' => 'Bezrealitky',
                'driver' => 'bezrealitky',
                'description' => 'REST API Bezrealitky',
                'api_base_url' => 'https://api.bezrealitky.cz/v1',
                'sort_order' => 30,
                'fields' => [
                    ['key' => 'api_key', 'label' => 'API klíč', 'type' => 'password', 'required' => true, 'hint' => 'Klíč z partnerského účtu', 'placeholder' => ''],
                    ['key' => 'endpoint', 'label' => 'API endpoint', 'type' => 'url', 'required' => true, 'hint' => '', 'placeholder' => 'https://api.bezrealitky.cz/v1'],
                ],
            ],
            [
                'code' => 'custom',
                'name' => 'Vlastní',
                'driver' => 'custom',
                'description' => 'Obecný / vlastní endpoint',
                'api_base_url' => null,
                'sort_order' => 90,
                'fields' => [
                    ['key' => 'endpoint', 'label' => 'Endpoint URL', 'type' => 'url', 'required' => true, 'hint' => 'Kam posílat nabídky', 'placeholder' => 'https://…'],
                    ['key' => 'api_key', 'label' => 'API klíč / token', 'type' => 'password', 'required' => false, 'hint' => '', 'placeholder' => ''],
                ],
            ],
        ];
    }
};
