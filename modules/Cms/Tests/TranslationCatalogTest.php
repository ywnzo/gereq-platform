<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Testing\TestCase;

final class TranslationCatalogTest extends TestCase
{
    public function testCatalogKeysAndPlaceholdersMatch(): void
    {
        $cs = require dirname(__DIR__) . '/Resources/translations/messages.cs.php';
        $en = require dirname(__DIR__) . '/Resources/translations/messages.en.php';
        self::assertSame([], array_diff_key($cs, $en));
        self::assertSame([], array_diff_key($en, $cs));
        foreach (['outcome' => \Modules\Cms\Application\FormSubmissionLog::OUTCOMES, 'delivery' => \Modules\Cms\Application\FormSubmissionLog::DELIVERIES] as $group => $values) {
            foreach ($values as $value) {
                self::assertArrayHasKey('cms.form.' . $group . '.' . $value, $cs);
                self::assertArrayHasKey('cms.form.' . $group . '.' . $value, $en);
            }
        }
        foreach ($cs as $key => $message) {
            preg_match_all('/%[a-zA-Z_]+%/', $message, $csParameters);
            preg_match_all('/%[a-zA-Z_]+%/', $en[$key], $enParameters);
            sort($csParameters[0]);
            sort($enParameters[0]);
            self::assertSame($csParameters[0], $enParameters[0], $key);
        }
    }
}
