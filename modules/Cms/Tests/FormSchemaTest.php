<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Cms\Cms;
use Gereq\Hook\HookManager;
use Gereq\Module\EnabledModulesProvider;
use Gereq\Testing\TestCase;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationException;
use Gereq\Validation\ValidationRegistry;
use Modules\Cms\Application\FormSchema;
use Modules\Cms\Http\Public\PublicFormController;
use PHPUnit\Framework\Attributes\DataProvider;

final class FormSchemaTest extends TestCase
{
    public function testFieldLabelNormalizesHtmlSpaces(): void
    {
        foreach (['Phone&nbsp;', "Phone\u{00A0}", '<b>Phone</b>&nbsp;', 'Phone&#160;'] as $label) {
            self::assertSame('Phone', FormSchema::field(['label' => $label])['label']);
        }
    }

    public function testInputAppearanceUsesSafeColorsAndOptionalBoundedDimensions(): void
    {
        $payload = FormSchema::normalize([
            'input_bg_color' => '#abc', 'input_text_color' => 'brand',
            'input_border_color' => 'url(https://example.com)', 'input_focus_color' => 'ink',
            'input_radius' => 0, 'input_padding' => 200, 'input_font_size' => 3,
        ], static fn (): array => []);
        self::assertSame('#aabbcc', $payload['input_bg_color']);
        self::assertSame('brand', $payload['input_text_color']);
        self::assertSame('inherit', $payload['input_border_color']);
        self::assertSame('ink', $payload['input_focus_color']);
        self::assertSame(0, $payload['input_radius']);
        self::assertSame(100, $payload['input_padding']);
        self::assertSame(10, $payload['input_font_size']);
        $defaults = FormSchema::normalize([], static fn (): array => []);
        self::assertSame('', $defaults['input_radius']);
        self::assertSame('', $defaults['input_padding']);
        self::assertSame('', $defaults['input_font_size']);
    }

    public function testSubmitButtonStylesAreWhitelistedAndClamped(): void
    {
        $payload = FormSchema::normalize([
            'submit_variant' => 'ghost', 'submit_align' => 'end', 'submit_full' => '1',
            'submit_bg_color' => '#abc', 'submit_text_color' => 'url(https://example.com)',
            'submit_radius' => -5, 'submit_padding' => 200,
        ], static fn (): array => []);
        self::assertSame('ghost', $payload['submit_variant']);
        self::assertSame('end', $payload['submit_align']);
        self::assertSame(1, $payload['submit_full']);
        self::assertSame('#aabbcc', $payload['submit_bg_color']);
        self::assertSame('inherit', $payload['submit_text_color']);
        self::assertSame(0, $payload['submit_radius']);
        self::assertSame(100, $payload['submit_padding']);
        $defaults = FormSchema::normalize([], static fn (): array => []);
        self::assertSame('', $defaults['submit_variant']);
        self::assertSame('', $defaults['submit_radius']);
        self::assertSame('', $defaults['submit_padding']);
    }

    public function testErrorSummaryIsOptInAndNormalized(): void
    {
        foreach ([null, 0, '0', false, 'false', 2] as $value) {
            self::assertSame(0, FormSchema::normalize(['error_summary' => $value], static fn (): array => [])['error_summary']);
        }
        self::assertSame(0, FormSchema::normalize([], static fn (): array => [])['error_summary']);
        foreach ([1, '1', true] as $value) {
            self::assertSame(1, FormSchema::normalize(['error_summary' => $value], static fn (): array => [])['error_summary']);
        }
    }

    private function schema(bool $enabled = true): FormSchema
    {
        $modules = self::createStub(EnabledModulesProvider::class);
        $modules->method('enabledIds')->willReturn($enabled ? ['cms'] : []);
        $cms = new Cms($modules);
        $cms->registerFormAction('test', 'Test', 'test.submit', ['email' => 'email'], static fn (array $values): array => throw new \RuntimeException('The test endpoint must never call the action handler'));
        $translator = new PassthroughTranslator();
        $validation = new ValidationRegistry(new HookManager(), $translator);
        $validation->ruleset('test.submit', ['email' => 'required|email']);
        FormSchema::registerValidation($validation, $translator);
        return new FormSchema($cms, $validation, $translator);
    }

    /** @return array<string, mixed> */
    private function form(): array
    {
        return ['action' => 'test', 'children' => [
            ['id' => 'email', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => 'email', 'control' => 'email', 'required' => 1])],
            ['id' => 'select', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => 'choice', 'control' => 'select', 'options' => "One\nTwo", 'required' => 1])],
            ['id' => 'date', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => 'date', 'control' => 'date'])],
        ]];
    }

    public function testLegacyConsentNormalizesToCheckboxAndRadioLayoutIsBounded(): void
    {
        self::assertSame('checkbox', FormSchema::field(['control' => 'consent'])['control']);
        self::assertSame('horizontal', FormSchema::field(['control' => 'radio', 'options_layout' => 'horizontal'])['options_layout']);
        self::assertSame('vertical', FormSchema::field(['control' => 'radio', 'options_layout' => 'invalid'])['options_layout']);
    }

    public function testRadioAndConsentValidateAgainstTheConfiguredSchema(): void
    {
        $form = $this->form();
        $form['children'][1]['payload']['control'] = 'radio';
        $form['children'][] = ['type' => 'form_field', 'payload' => FormSchema::field(['name' => 'consent', 'control' => 'consent', 'required' => 1])];
        self::assertSame('1', $this->schema()->validate($form, ['email' => 'a@example.test', 'choice' => 'One', 'consent' => '1'])['consent']);
        try {
            $this->schema()->validate($form, ['email' => 'a@example.test', 'choice' => 'injected', 'consent' => 'yes']);
            self::fail('Unconfigured options and ambiguous consent must fail.');
        } catch (ValidationException $exception) {
            self::assertSame(['choice', 'consent'], array_keys($exception->errors()));
        }
    }

    public function testMalformedOptionsAreRejectedBeforeStringConversion(): void
    {
        $form = $this->form();
        $form['children'][1]['payload']['options'] = ['unexpected array'];
        $this->expectException(ValidationException::class);
        $this->schema()->assertValid($form);
    }

    public function testValidatesAndWhitelistsValuesUsingTheServerSchema(): void
    {
        self::assertSame(['email' => 'a@example.test', 'choice' => 'One', 'date' => ''], $this->schema()->validate($this->form(), [
            'email' => ' a@example.test ', 'choice' => 'One', 'admin' => '1',
        ]));
    }

    public function testReturnsFieldErrorsForRequiredEmailOptionsAndRealCalendarDate(): void
    {
        try {
            $this->schema()->validate($this->form(), ['email' => 'invalid', 'choice' => 'injected', 'date' => '2026-02-30']);
            self::fail('Invalid values must fail.');
        } catch (ValidationException $exception) {
            self::assertSame(['email', 'choice', 'date'], array_keys($exception->errors()));
        }
    }

    public function testRejectsArrayValuesBeforeCallingStringValidators(): void
    {
        $this->expectException(ValidationException::class);
        $this->schema()->validate($this->form(), ['email' => ['nested'], 'choice' => 'One']);
    }

    public function testMappedFieldsUseCanonicalActionNamesAndKeepExtras(): void
    {
        $form = $this->form();
        $form['children'][0]['payload']['name'] = 'contact_email';
        $form['field_mapping'] = ['email' => 'contact_email'];
        self::assertSame(['choice' => 'One', 'date' => '', 'email' => 'a@example.test'], $this->schema()->validate($form, [
            'contact_email' => 'a@example.test', 'choice' => 'One',
        ]));
    }

    public function testMappingCannotOverwriteAnExistingCanonicalField(): void
    {
        $form = $this->form();
        $form['field_mapping'] = ['email' => 'other'];
        $form['children'][] = ['type' => 'form_field', 'payload' => FormSchema::field(['name' => 'other', 'control' => 'email', 'required' => 1])];
        $this->expectException(ValidationException::class);
        $this->schema()->assertValid($form);
    }

    public function testIncompleteDraftIsEditableButCannotBePublished(): void
    {
        $form = ['action' => '', 'children' => []];
        $this->schema()->assertTree([['type' => 'form', 'payload' => $form]]);
        $this->expectException(ValidationException::class);
        $this->schema()->assertPublishable([['type' => 'layout', 'payload' => ['children' => [['type' => 'form', 'payload' => $form]]]]]);
    }

    public function testPublishAdapterBlocksAnIncompleteWorkingCopy(): void
    {
        $config = \Doctrine\ORM\ORMSetup::createAttributeMetadataConfiguration([__DIR__ . '/../Domain'], true);
        $config->enableNativeLazyObjects(true);
        $em = new \Doctrine\ORM\EntityManager(\Doctrine\DBAL\DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]), $config);
        (new \Doctrine\ORM\Tools\SchemaTool($em))->createSchema(array_map(fn (string $class) => $em->getClassMetadata($class), [\Modules\Cms\Domain\Page::class, \Modules\Cms\Domain\PageBlock::class]));
        $page = new \Modules\Cms\Domain\Page('contact', 'Contact');
        $page->setStatus('published');
        $em->persist($page);
        $em->persist(new \Modules\Cms\Domain\PageBlock($page->id(), 'form', 0, ['action' => '', 'children' => []]));
        $em->flush();
        $router = new \Gereq\Http\Router();
        $router->get('/admin/cms/pages/{id}/builder', static fn () => null)->name('admin.cms.pages.builder');
        $adapter = new \Modules\Cms\Publish\CmsPagePublishAdapter($em, new \Gereq\Publish\StagingStore($em), $this->schema(), $router, new PassthroughTranslator());
        $entry = new \Gereq\Publish\Entity\ContentStagingEntry('cms.pages', 'page', $page->id(), 'update', [], 'Contact');
        try {
            $adapter->apply($entry);
            self::fail('Invalid form must not be publishable');
        } catch (ValidationException $exception) {
            self::assertStringContainsString('/admin/cms/pages/' . $page->id() . '/builder?focus_form=', $exception->redirectTo() ?? '');
            self::assertSame('cms.form.publish_open', $exception->input()['action_label']);
        }
    }

    public function testPublishValidationIdentifiesNestedForm(): void
    {
        try {
            $this->schema()->assertPublishable([['type' => 'layout', 'payload' => ['children' => [
                ['id' => 'form-123', 'type' => 'form', 'payload' => ['action' => '', 'admin_label' => 'Contact', 'children' => []]],
            ]]]]);
            self::fail('Invalid form must not be publishable');
        } catch (ValidationException $exception) {
            self::assertSame('form-123', $exception->input()['block_id']);
            self::assertSame('Contact', $exception->input()['form_label']);
        }
    }

    public function testBackendTestValidatesWithoutInvokingTheRegisteredAction(): void
    {
        $reflection = new \ReflectionClass(\Modules\Cms\Http\FormController::class);
        $controller = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('em')->setValue($controller, self::createStub(\Doctrine\ORM\EntityManagerInterface::class));
        $reflection->getProperty('formSchema')->setValue($controller, $this->schema());
        $reflection->getProperty('translator')->setValue($controller, new PassthroughTranslator());
        $request = \Symfony\Component\HttpFoundation\Request::create('/admin/cms/forms/test', 'POST', [], [], [], [], json_encode([
            'form' => $this->form(), 'values' => ['email' => 'a@example.test', 'choice' => 'One'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(200, $controller->testForm($request)->getStatusCode());
        $invalid = \Symfony\Component\HttpFoundation\Request::create('/admin/cms/forms/test', 'POST', [], [], [], [], json_encode([
            'form' => $this->form(), 'values' => ['email' => 'bad'],
        ], JSON_THROW_ON_ERROR));
        self::assertSame(422, $controller->testForm($invalid)->getStatusCode());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidNames(): iterable
    {
        foreach (['', 'email', 'website_url', '__proto__', 'constructor', 'user.email', '1field'] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('invalidNames')]
    public function testRejectsDuplicateReservedAndInvalidNames(string $name): void
    {
        $form = $this->form();
        $form['children'][] = ['type' => 'form_field', 'payload' => FormSchema::field(['name' => $name])];
        $this->expectException(ValidationException::class);
        $this->schema()->assertValid($form);
    }

    public function testDisabledOrUnknownActionsCannotBeUsed(): void
    {
        $this->expectException(ValidationException::class);
        $this->schema(false)->assertValid($this->form());
    }

    public function testRequiredActionFieldCannotBeRemovedOrMadeOptional(): void
    {
        $form = $this->form();
        $form['children'][0]['payload']['required'] = 0;
        $this->expectException(ValidationException::class);
        $this->schema()->assertValid($form);
    }

    public function testNestedFormsAreRejected(): void
    {
        $form = $this->form();
        $form['children'][] = ['type' => 'form', 'payload' => $this->form()];
        $this->expectException(ValidationException::class);
        $this->schema()->assertValid($form);
    }

    public function testNestedFormLookupAndSubmissionPathsAreIndependentOfStorefrontOrigin(): void
    {
        $form = ['id' => 'form-id', 'type' => 'form', 'payload' => $this->form()];
        $blocks = [['id' => 'layout', 'type' => 'layout', 'payload' => ['children' => [$form]]]];
        $result = PublicFormController::endpoints($blocks, 'contact');
        self::assertSame('/api/public/pages/contact/forms/form-id', $result[0]['payload']['children'][0]['payload']['submit_path']);
        self::assertSame($form['payload'], PublicFormController::findForm($blocks, 'form-id'));
        self::assertNull(PublicFormController::findForm($blocks, 'missing'));
    }

    public function testPreflightAllowsTheIdempotencyHeader(): void
    {
        $controller = (new \ReflectionClass(PublicFormController::class))->newInstanceWithoutConstructor();
        $response = $controller->options(new \Symfony\Component\HttpFoundation\Request());
        self::assertSame(204, $response->getStatusCode());
        self::assertStringContainsString('Idempotency-Key', $response->headers->get('Access-Control-Allow-Headers'));
    }
    public function testEverySaveNormalizerPreservesFormConfigurationAndFieldSettings(): void
    {
        $raw = $this->form() + ['columns' => 1, 'submit_label' => 'Send', 'success_message' => 'Thanks'];
        $raw['children'][1]['payload']['full'] = 1;
        foreach ([
            \Modules\Cms\Http\PageController::class => 'normalizeBuilderPayload',
            \Modules\Cms\Http\PartialController::class => 'normalizePayload',
            \Modules\Cms\Http\BlockController::class => 'payloadFromData',
            \Modules\Cms\Http\Api\BlockApiController::class => 'payloadFromData',
        ] as $class => $method) {
            $reflection = new \ReflectionClass($class);
            $controller = $reflection->newInstanceWithoutConstructor();
            $reflection->getProperty('em')->setValue($controller, self::createStub(\Doctrine\ORM\EntityManagerInterface::class));
            $reflection->getProperty('formSchema')->setValue($controller, $this->schema());
            $saved = $reflection->getMethod($method)->invoke($controller, 'form', $raw);
            self::assertSame('test', $saved['action'], $class);
            self::assertSame('Send', $saved['submit_label'], $class);
            self::assertSame('Thanks', $saved['success_message'], $class);
            self::assertSame(1, $saved['columns'], $class);
            self::assertSame(1, $saved['children'][1]['payload']['full'], $class);
            self::assertSame("One\nTwo", $saved['children'][1]['payload']['options'], $class);
        }
    }
    public function testIncompleteFormsCanBeSavedWithoutAnEnabledAction(): void
    {
        $schema = $this->schema(false);
        foreach ([
            ['action' => '', 'children' => []],
            ['action' => '', 'children' => [
                ['id' => 'email', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => 'email', 'control' => 'email'])],
                ['id' => 'password', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => 'password'])],
            ]],
            ['action' => 'disabled.action', 'children' => [
                ['id' => 'choice', 'type' => 'form_field', 'payload' => FormSchema::field(['name' => '', 'control' => 'select'])],
            ]],
        ] as $payload) {
            $schema->assertTree([['type' => 'form', 'payload' => $payload]]);
        }
        $this->addToAssertionCount(1);
    }

    public function testAnIncompleteSavedFormStillCannotSubmit(): void
    {
        $this->expectException(ValidationException::class);
        $this->schema()->validate(['action' => '', 'children' => []], []);
    }

    public function testDraftValidationStillRejectsNestedForms(): void
    {
        $this->expectException(ValidationException::class);
        $this->schema()->assertEditable(['children' => [['type' => 'form', 'payload' => ['children' => []]]]]);
    }
}
