<?php

declare(strict_types=1);

namespace Modules\Reviews\Tests;

use Gereq\Hook\HookManager;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;
use Modules\Reviews\Http\ReviewSettingsController;
use PHPUnit\Framework\TestCase;

final class ReviewSettingsValidationTest extends TestCase
{
    public function testAcceptsBlankCredentialsAndBooleanChoices(): void
    {
        $data = $this->validInput();
        $values = $this->validation()->validate('reviews.settings.save', $data);
        self::assertNull($values['reviews__google_api_key__password']);
        self::assertNull($values['reviews__facebook_access_token__password']);
        self::assertSame('0', $values['reviews__google_enabled']);
        self::assertSame('1', $values['reviews__replies_enabled']);
    }

    public function testRejectsMalformedSettings(): void
    {
        $data = $this->validInput();
        $data['reviews__facebook_enabled'] = 'invalid';
        $data['reviews__default_locale'] = ['invalid'];
        try {
            $this->validation()->validate('reviews.settings.save', $data);
            self::fail('Invalid settings must be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('reviews__facebook_enabled', $exception->errors());
            self::assertArrayHasKey('reviews__default_locale', $exception->errors());
        }
    }

    /** @return array<string, mixed> */
    private function validInput(): array
    {
        return [
            'reviews__google_enabled' => '0',
            'reviews__google_place_id' => '',
            'reviews__google_api_key__password' => '',
            'reviews__facebook_enabled' => '0',
            'reviews__facebook_page_id' => '',
            'reviews__facebook_access_token__password' => '',
            'reviews__replies_enabled' => '1',
            'reviews__default_locale' => 'cs',
        ];
    }

    private function validation(): ValidationRegistry
    {
        $translator = new PassthroughTranslator();
        $validation = new ValidationRegistry(new HookManager(), $translator);
        ReviewSettingsController::registerValidation($validation, $translator);

        return $validation;
    }
}
