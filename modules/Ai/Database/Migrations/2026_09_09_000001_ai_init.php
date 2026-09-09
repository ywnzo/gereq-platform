<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\Schema;

return new class {
    public function up(Schema $schema): void
    {
        // Tables created via Doctrine SchemaTool from Modules\Ai\Domain entities.
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'ai_usage_daily',
            'ai_tool_invocations',
            'ai_messages',
            'ai_conversations',
            'ai_chunk_pgvector',
            'ai_chunks',
            'ai_sources',
            'ai_agent_knowledge',
            'ai_knowledge_bases',
            'ai_agent_tools',
            'ai_agents',
            'ai_models',
            'ai_providers',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->dropTable($table);
            }
        }
    }

    public function afterUp(Connection $conn): void
    {
        try {
            if (!$conn->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                return;
            }
            $conn->executeStatement('CREATE EXTENSION IF NOT EXISTS vector');
            $conn->executeStatement(
                'CREATE TABLE IF NOT EXISTS ai_chunk_pgvector (
                    chunk_id VARCHAR(36) PRIMARY KEY,
                    embedding vector(768)
                 )'
            );
            $conn->executeStatement(
                'CREATE INDEX IF NOT EXISTS idx_ai_chunk_pgvector_hnsw
                 ON ai_chunk_pgvector USING hnsw (embedding vector_cosine_ops)'
            );
        } catch (\Throwable) {
            // Extension may be unavailable — SqlVectorStore remains the default.
        }
    }
};
