<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // SchemaTool: emailing_attachments.content_id, emailing_attachments.is_inline
    }

    public function down(Schema $schema): void
    {
    }
};
