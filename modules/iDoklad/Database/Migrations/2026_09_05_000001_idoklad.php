<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tabulku idoklad_links vytváří SchemaTool z entity Modules\iDoklad\Domain\iDokladLink.
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('idoklad_links')) {
            $schema->dropTable('idoklad_links');
        }
    }
};
