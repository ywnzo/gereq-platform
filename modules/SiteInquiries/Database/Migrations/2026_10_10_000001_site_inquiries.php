<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Schema from Doctrine entity attributes (Inquiry).
    }

    public function down(Schema $schema): void
    {
        if ($schema->hasTable('site_inquiries')) {
            $schema->dropTable('site_inquiries');
        }
    }
};
