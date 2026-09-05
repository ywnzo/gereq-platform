<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // MoneyS3 ukládá cache do settings (moneys3.cache) — žádné vlastní tabulky.
    }

    public function down(Schema $schema): void
    {
    }
};
