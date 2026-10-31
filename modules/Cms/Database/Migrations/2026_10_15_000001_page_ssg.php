<?php

declare(strict_types=1);

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;

/** Pages: opt-in static shell HTML for storefront first paint (SSG). */
return new class {
    public function up(Schema $schema): void
    {
        if (!$schema->hasTable('cms_pages')) {
            return;
        }

        $pages = $schema->getTable('cms_pages');
        if (!$pages->hasColumn('ssg')) {
            $pages->addColumn('ssg', Types::BOOLEAN, [
                'notnull' => true,
                'default' => false,
            ]);
        }
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('cms_pages')) {
            return;
        }

        $pages = $schema->getTable('cms_pages');
        if ($pages->hasColumn('ssg')) {
            $pages->dropColumn('ssg');
        }
    }
};
