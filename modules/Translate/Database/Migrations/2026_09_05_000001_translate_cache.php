<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: translate_cache
        // afterUp: rename z legacy aitranslate_*
    }

    public function afterUp(Connection $connection): void
    {
        $sm = $connection->createSchemaManager();
        $platform = $connection->getDatabasePlatform();

        if ($sm->tablesExist(['aitranslate_cache']) && !$sm->tablesExist(['translate_cache'])) {
            $connection->executeStatement('ALTER TABLE aitranslate_cache RENAME TO translate_cache');
        }

        if ($sm->tablesExist(['modules'])) {
            $hasOld = (int) $connection->fetchOne("SELECT COUNT(*) FROM modules WHERE id = 'aitranslate'") > 0;
            $hasNew = (int) $connection->fetchOne("SELECT COUNT(*) FROM modules WHERE id = 'translate'") > 0;
            if ($hasOld && !$hasNew) {
                $connection->executeStatement(
                    "UPDATE modules SET id = 'translate', version = '2.0.0' WHERE id = 'aitranslate'"
                );
            } elseif ($hasOld && $hasNew) {
                $connection->executeStatement("DELETE FROM modules WHERE id = 'aitranslate'");
            }
        }

        if ($sm->tablesExist(['settings'])) {
            $keyCol = $this->settingsKeyColumn($connection);
            $keyQuoted = $platform->quoteIdentifier($keyCol);

            $renames = [
                'aitranslate.ms_api_key' => 'translate.ms_api_key',
                'aitranslate.ms_region' => 'translate.ms_region',
                'aitranslate.ms_endpoint' => 'translate.ms_endpoint',
                'aitranslate.source_locale' => 'translate.source_locale',
                'aitranslate.target_locale' => 'translate.target_locale',
                // legacy Cursor settings — drop if still present
                'aitranslate.cursor_api_key' => null,
                'aitranslate.agent_id' => null,
                'aitranslate.model' => null,
            ];
            foreach ($renames as $from => $to) {
                if ($to === null) {
                    $connection->executeStatement('DELETE FROM settings WHERE ' . $keyQuoted . ' = ?', [$from]);
                    continue;
                }
                $existsNew = (int) $connection->fetchOne(
                    'SELECT COUNT(*) FROM settings WHERE ' . $keyQuoted . ' = ?',
                    [$to],
                ) > 0;
                if ($existsNew) {
                    $connection->executeStatement('DELETE FROM settings WHERE ' . $keyQuoted . ' = ?', [$from]);
                } else {
                    $connection->executeStatement(
                        'UPDATE settings SET ' . $keyQuoted . ' = ?, module = ? WHERE ' . $keyQuoted . ' = ?',
                        [$to, 'translate', $from],
                    );
                }
            }
            $connection->executeStatement(
                "UPDATE settings SET module = 'translate' WHERE module = 'aitranslate'"
            );
        }

        if ($sm->tablesExist(['permissions'])) {
            $permRenames = [
                'aitranslate.view' => 'translate.view',
                'aitranslate.manage' => 'translate.manage',
            ];
            foreach ($permRenames as $from => $to) {
                $existsNew = (int) $connection->fetchOne('SELECT COUNT(*) FROM permissions WHERE name = ?', [$to]) > 0;
                if ($existsNew) {
                    $oldId = $connection->fetchOne('SELECT id FROM permissions WHERE name = ?', [$from]);
                    if (is_string($oldId) && $oldId !== '' && $sm->tablesExist(['role_permissions'])) {
                        $connection->executeStatement('DELETE FROM role_permissions WHERE permission_id = ?', [$oldId]);
                    }
                    $connection->executeStatement('DELETE FROM permissions WHERE name = ?', [$from]);
                } else {
                    $connection->executeStatement(
                        'UPDATE permissions SET name = ?, label = ?, group_name = ? WHERE name = ?',
                        [$to, 'perm.' . $to, 'perm.group.translate', $from],
                    );
                }
            }
        }

        if ($sm->tablesExist(['schema_migrations'])) {
            $connection->executeStatement(
                "DELETE FROM schema_migrations WHERE version LIKE 'aitranslate:%'"
            );
        }
    }

    private function settingsKeyColumn(Connection $connection): string
    {
        $cols = $connection->createSchemaManager()->listTableColumns('settings');
        foreach (array_keys($cols) as $name) {
            if (strcasecmp((string) $name, 'keyname') === 0 || strcasecmp((string) $name, 'key_name') === 0) {
                return (string) $name;
            }
        }

        // Fallback — Doctrine default property name
        return 'keyName';
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('translate_cache')) {
            $schema->dropTable('translate_cache');
        }
    }
};
