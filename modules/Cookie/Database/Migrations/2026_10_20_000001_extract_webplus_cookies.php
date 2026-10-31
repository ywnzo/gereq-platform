<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Gereq\Setting\Settings;
use Modules\Cookie\Application\LegacySettingsImporter;

return new class {
    public function up(Schema $schema): void
    {
    }

    public function afterUp(Connection $connection): void
    {
        (new LegacySettingsImporter(gereq()->make(Settings::class)))->copy();
    }

    public function down(Schema $schema): void
    {
        // No schema changes. Retain copied settings and the original Web+ values on rollback.
    }
};
