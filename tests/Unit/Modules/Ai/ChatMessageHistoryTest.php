<?php

declare(strict_types=1);

namespace Tests\Unit\Modules\Ai;

use Modules\Ai\Application\Dto\ChatMessage;
use Modules\Ai\Application\Providers\OpenAiCompatLlmProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class ChatMessageHistoryTest extends TestCase
{
    public function testSanitizeDropsOrphanToolMessages(): void
    {
        $provider = (new ReflectionClass(OpenAiCompatLlmProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(OpenAiCompatLlmProvider::class, 'sanitizeOpenAiMessages');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $clean */
        $clean = $method->invoke($provider, [
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'assistant', 'content' => ''],
            ['role' => 'tool', 'content' => '{"ok":true}', 'tool_call_id' => 'call_1'],
        ]);

        self::assertCount(2, $clean);
        self::assertSame('assistant', $clean[1]['role']);
    }

    public function testSanitizeKeepsValidToolTurn(): void
    {
        $provider = (new ReflectionClass(OpenAiCompatLlmProvider::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(OpenAiCompatLlmProvider::class, 'sanitizeOpenAiMessages');
        $method->setAccessible(true);

        /** @var list<array<string, mixed>> $clean */
        $clean = $method->invoke($provider, [
            ['role' => 'user', 'content' => 'hi'],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => 'customers.create', 'arguments' => '{}'],
                ]],
            ],
            ['role' => 'tool', 'content' => '{"ok":true}', 'tool_call_id' => 'call_1'],
        ]);

        self::assertCount(3, $clean);
        self::assertSame('tool', $clean[2]['role']);
    }

    public function testChatMessageRoundTripIncludesToolCalls(): void
    {
        $msg = new ChatMessage(
            'assistant',
            '',
            toolCalls: [[
                'id' => 'call_abc',
                'type' => 'function',
                'function' => ['name' => 'customers.create', 'arguments' => '{"name":"A"}'],
            ]],
        );
        $row = $msg->toOpenAi();
        self::assertArrayHasKey('tool_calls', $row);
        self::assertSame('call_abc', $row['tool_calls'][0]['id']);
    }
}
