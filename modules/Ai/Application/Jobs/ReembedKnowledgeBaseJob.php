<?php

declare(strict_types=1);

namespace Modules\Ai\Application\Jobs;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Queue\Job;
use Modules\Ai\Application\Rag\IngestPipeline;
use Modules\Ai\Domain\AiSource;

final class ReembedKnowledgeBaseJob implements Job
{
    public function __construct(private readonly string $knowledgeBaseId)
    {
    }

    public function handle(): void
    {
        /** @var EntityManagerInterface $em */
        $em = gereq()->make(EntityManagerInterface::class);
        /** @var IngestPipeline $pipeline */
        $pipeline = gereq()->make(IngestPipeline::class);
        $sources = $em->getRepository(AiSource::class)->findBy(['knowledgeBaseId' => $this->knowledgeBaseId]);
        foreach ($sources as $source) {
            if ($source instanceof AiSource) {
                $source->markPending();
                $em->flush();
                $pipeline->ingestSource($source->id());
            }
        }
    }
}
