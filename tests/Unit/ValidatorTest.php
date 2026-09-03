<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Hook\HookManager;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;
use Gereq\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredAndEmail(): void
    {
        $validator = $this->validator();

        try {
            $validator->validate(['name' => '', 'email' => 'ne-email'], [
                'name' => 'required|min:2',
                'email' => 'required|email',
            ]);
            self::fail('Očekávána ValidationException');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('name', $exception->errors());
            self::assertArrayHasKey('email', $exception->errors());
            self::assertSame('', $exception->input()['name']);
        }
    }

    public function testReturnsValidatedPayload(): void
    {
        $data = $this->validator()->validate([
            'name' => 'Jiří',
            'email' => 'jiri@gereq.cz',
            'note' => '',
        ], [
            'name' => 'required|min:2',
            'email' => 'required|email',
            'note' => 'nullable|max:20',
        ]);

        self::assertSame('Jiří', $data['name']);
        self::assertNull($data['note']);
    }

    public function testModuleCanRegisterCustomRule(): void
    {
        $hooks = new HookManager();
        $registry = new ValidationRegistry($hooks);
        $registry->rule('no_gereq', static function (mixed $value): ?string {
            return is_string($value) && str_contains($value, 'gereq')
                ? 'Tenhle e-mail je rezervovaný.'
                : null;
        });
        $registry->ruleset('demo', [
            'email' => 'required|email|no_gereq',
        ]);

        try {
            $registry->validate('demo', ['email' => 'ahoj@gereq.cz']);
            self::fail('Očekávána ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame('Tenhle e-mail je rezervovaný.', $exception->errors()['email']);
        }
    }

    public function testAfterCallbackCanAddModuleError(): void
    {
        $hooks = new HookManager();
        $registry = new ValidationRegistry($hooks);
        $registry->ruleset('demo', ['name' => 'required']);
        $registry->after('demo', static function (array $data, array $errors): array {
            if (($data['name'] ?? '') === 'root') {
                $errors['name'] = 'Tohle jméno je zakázané.';
            }

            return $errors;
        });

        try {
            $registry->validate('demo', ['name' => 'root']);
            self::fail('Očekávána ValidationException');
        } catch (ValidationException $exception) {
            self::assertSame('Tohle jméno je zakázané.', $exception->errors()['name']);
        }
    }

    public function testInRule(): void
    {
        $this->expectException(ValidationException::class);
        $this->validator()->validate(['status' => 'nope'], ['status' => 'required|in:active,lead']);
    }

    private function validator(): Validator
    {
        $hooks = new HookManager();

        return new Validator(new ValidationRegistry($hooks), $hooks);
    }
}
