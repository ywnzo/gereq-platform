<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Setup;

use Gereq\Hook\HookManager;
use Gereq\Setup\SetupProfile;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;

final class SetupProfileTest extends TestCase
{
    private function profile(): SetupProfile
    {
        return new SetupProfile(new ValidationRegistry(new HookManager(), new PassthroughTranslator()));
    }

    public function testBusinessDoesNotRequireVatIdAndClearsPrivateFields(): void
    {
        $data = $this->profile()->validate([
            'operator_type' => 'business', 'company_name' => ' Example ', 'ico' => '12345678',
            'email' => 'owner@example.test', 'address' => 'Example 1', 'birth_date' => '1990-01-01',
        ]);
        self::assertSame('Example', $data['company_name']);
        self::assertSame('', $data['dic']);
        self::assertSame('', $data['birth_date']);
    }

    public function testPersonHasSeparateIdentityWithoutBusinessFields(): void
    {
        $data = $this->profile()->validate([
            'operator_type' => 'person', 'first_name' => 'Jane', 'last_name' => 'Doe',
            'address' => 'Example 1', 'birth_date' => '1990-01-01', 'ico' => '12345678',
        ]);
        self::assertSame('person', $data['type']);
        self::assertSame('Jane', $data['first_name']);
        self::assertSame('', $data['ico']);
        self::assertSame('', $data['email']);
    }

    public function testRejectsInvalidDatesAndFutureBirthDate(): void
    {
        foreach (['2025-02-30', '2099-01-01', '', 'yesterday'] as $date) {
            try {
                $this->profile()->validate([
                    'operator_type' => 'person', 'first_name' => 'Jane', 'last_name' => 'Doe',
                    'address' => 'Example 1', 'birth_date' => $date,
                ]);
                self::fail('Invalid birth date accepted: ' . $date);
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('birth_date', $exception->errors());
            }
        }
    }

    public function testRejectsMissingOperatorType(): void
    {
        $this->expectException(ValidationException::class);
        $this->profile()->validate(['operator_type' => 'other']);
    }
}
