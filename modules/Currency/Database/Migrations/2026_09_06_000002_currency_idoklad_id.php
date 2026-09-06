<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: currencies.idoklad_id
    }

    public function down(Schema $schema): void
    {
    }
};
