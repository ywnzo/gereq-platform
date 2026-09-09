<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai;

use Modules\Ai\Application\Contracts\MutatingAiTool;
use Modules\Ai\Application\Dto\AiActor;
use Modules\Ai\Application\Dto\AiToolResult;
use Modules\Ai\Application\Tools\CustomersCreateTool;
use Modules\Ai\Domain\AiToolInvocation;
use PHPUnit\Framework\TestCase;

final class MutatingToolContractTest extends TestCase
{
    public function testCustomersCreateIsMutating(): void
    {
        $tool = new CustomersCreateTool($this->createMock(\Doctrine\ORM\EntityManagerInterface::class));
        self::assertInstanceOf(MutatingAiTool::class, $tool);
        self::assertSame(MutatingAiTool::MUTATION_CREATE, $tool->mutation());
        self::assertStringContainsString('Novák', $tool->confirmSummary([
            'name' => 'Jan Novák',
            'email' => 'jan@example.com',
        ]));
    }

    public function testInvocationPendingLifecycle(): void
    {
        $inv = new AiToolInvocation('conv-1', 'customers.create', ['name' => 'A', 'email' => 'a@b.c']);
        self::assertSame(AiToolInvocation::STATUS_EXECUTED, $inv->status());
        $inv->markPendingConfirmation(['status' => 'pending_confirmation', 'summary' => 'x']);
        self::assertTrue($inv->isPending());
        $inv->reject('nope');
        self::assertSame(AiToolInvocation::STATUS_REJECTED, $inv->status());
        self::assertFalse($inv->ok());
    }

    public function testToolResultPendingFlag(): void
    {
        $r = AiToolResult::pending(['status' => 'pending_confirmation']);
        self::assertTrue($r->pendingConfirmation);
        self::assertTrue($r->ok);
    }
}
