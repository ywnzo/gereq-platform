<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Modules\Ai;

use Modules\Ai\Application\AgentService;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Domain\AiAgent;
use PHPUnit\Framework\TestCase;

final class AgentServiceAccessTest extends TestCase
{
    public function testRejectsUnpublishedForPublic(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $service = new AgentService($em);
        $agent = new AiAgent('demo', 'Demo', 'You are helpful', ['public']);
        $actor = new AiActor('api_token', 'tok', 'public', [], ['ai.agent.demo']);

        $this->expectException(\RuntimeException::class);
        $service->assertAccessible($agent, $actor, requirePublished: true);
    }

    public function testAllowsPublishedWithScope(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $service = new AgentService($em);
        $agent = new AiAgent('demo', 'Demo', 'You are helpful', ['public', 'api']);
        $agent->setPublished(true);
        $actor = new AiActor('api_token', 'tok', 'public', [], ['ai.agent.demo']);
        $service->assertAccessible($agent, $actor, requirePublished: true);
        $this->addToAssertionCount(1);
    }

    public function testRejectsWrongSurface(): void
    {
        $em = $this->createMock(\Doctrine\ORM\EntityManagerInterface::class);
        $service = new AgentService($em);
        $agent = new AiAgent('demo', 'Demo', 'You are helpful', ['admin']);
        $agent->setPublished(true);
        $actor = new AiActor('api_token', 'tok', 'public', [], ['ai.agent.demo']);
        $this->expectException(\RuntimeException::class);
        $service->assertAccessible($agent, $actor, requirePublished: true);
    }
}
