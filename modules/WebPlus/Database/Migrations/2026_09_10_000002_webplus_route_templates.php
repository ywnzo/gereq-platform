<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Ramsey\Uuid\Uuid;

return new class {
    public function up(Schema $schema): void
    {
        // Data seed in afterUp.
    }

    public function down(Schema $schema): void
    {
        //
    }

    public function afterUp(Connection $conn): void
    {
        if (!$conn->createSchemaManager()->tablesExist(['webplus_metas'])) {
            return;
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $templates = [
            [
                'path' => '/nabidky/{slug}',
                'seo_title' => '{title}',
                'seo_description' => '{description}',
            ],
            [
                'path' => '/stranka/{slug}',
                'seo_title' => '{title}',
                'seo_description' => '{description}',
            ],
            [
                'path' => '/{page}/{x}',
                'seo_title' => '{x}',
                'seo_description' => '{page} / {x}',
            ],
        ];

        foreach ($templates as $tpl) {
            $exists = $conn->fetchOne('SELECT id FROM webplus_metas WHERE path = ?', [$tpl['path']]);
            if ($exists) {
                continue;
            }
            $conn->insert('webplus_metas', [
                'id' => Uuid::uuid7()->toString(),
                'path' => $tpl['path'],
                'cms_page_id' => null,
                'is_active' => 1,
                'seo_title' => $tpl['seo_title'],
                'seo_description' => $tpl['seo_description'],
                'canonical_url' => null,
                'robots' => 'index,follow',
                'keywords' => null,
                'geo_region' => null,
                'geo_placename' => null,
                'geo_position' => null,
                'ai_summary' => null,
                'json_ld' => null,
                'og_title' => $tpl['seo_title'],
                'og_description' => $tpl['seo_description'],
                'og_image_url' => null,
                'og_type' => 'website',
                'og_url' => null,
                'og_locale' => null,
                'twitter_card' => 'summary_large_image',
                'twitter_title' => null,
                'twitter_description' => null,
                'twitter_image_url' => null,
                'sitemap_include' => 0,
                'sitemap_priority' => '0.5',
                'sitemap_changefreq' => 'weekly',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
