<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Api;

use Gereq\Api\OpenApi\ValidationRulesToSchema;
use PHPUnit\Framework\TestCase;

final class ValidationRulesToSchemaTest extends TestCase
{
    public function testConvertsCommonRules(): void
    {
        $schema = (new ValidationRulesToSchema())->convert([
            'title' => 'required|min:2|max:190',
            'stage' => 'required|in:lead,won,lost',
            'amount' => 'nullable|max:40',
            'active' => 'boolean',
        ]);

        self::assertSame('object', $schema['type']);
        self::assertSame(['title', 'stage'], $schema['required']);
        self::assertSame(2, $schema['properties']['title']['minLength']);
        self::assertSame(190, $schema['properties']['title']['maxLength']);
        self::assertSame(['lead', 'won', 'lost'], $schema['properties']['stage']['enum']);
        self::assertSame(['string', 'null'], $schema['properties']['amount']['type']);
        self::assertSame('boolean', $schema['properties']['active']['type']);
    }

    public function testInRuleSupportsSemicolonSeparatedValuesWithCommas(): void
    {
        $schema = (new ValidationRulesToSchema())->convert([
            'robots' => 'required|in:index,follow;index,nofollow;noindex,follow;noindex,nofollow',
        ]);

        self::assertSame(
            ['index,follow', 'index,nofollow', 'noindex,follow', 'noindex,nofollow'],
            $schema['properties']['robots']['enum'],
        );
    }
}
