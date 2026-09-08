<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\Admin\Form\FormField;
use Gereq\Admin\Form\FormGenerator;
use Gereq\Admin\Form\Forms;
use Gereq\Admin\Resource\AdminField;
use Gereq\Hook\HookManager;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationRegistry;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\FilesystemLoader;

final class FormsTest extends TestCase
{
    public function testRequiredFieldsFromFormFieldAttribute(): void
    {
        $forms = $this->forms();
        self::assertSame(['title', 'city'], $forms->requiredFields(FormsTestListingStub::class));
        self::assertTrue($forms->isRequired(FormsTestListingStub::class, 'title'));
        self::assertTrue($forms->isRequired(new FormsTestListingStub(), 'city'));
        self::assertFalse($forms->isRequired(FormsTestListingStub::class, 'slug'));
        self::assertSame(['title' => true, 'city' => true], $forms->requiredMap(FormsTestListingStub::class));
    }

    public function testRequiredFieldsFromAdminFieldAttribute(): void
    {
        $forms = $this->forms();
        self::assertSame(['name', 'email'], $forms->requiredFields(FormsTestCustomerStub::class));
    }

    public function testRequiredFieldsFromRuleset(): void
    {
        $registry = new ValidationRegistry(new HookManager(), new PassthroughTranslator());
        $registry->ruleset('demo.save', [
            'title' => 'required|min:2',
            'note' => 'nullable|max:100',
            'flag' => 'accepted',
        ]);
        $forms = new Forms($registry, $this->twig());

        self::assertSame(['title', 'flag'], $forms->requiredFields('demo.save'));
    }

    public function testFormEntityBindingMergesWithRuleset(): void
    {
        $registry = new ValidationRegistry(new HookManager(), new PassthroughTranslator());
        $registry->ruleset('stub.save', [
            'title' => 'required|min:1',
        ]);
        $forms = new Forms($registry, $this->twig());
        $forms->entity('stub.save', FormsTestListingStub::class);

        self::assertSame(['title', 'city'], $forms->requiredFields('stub.save'));
        self::assertTrue($forms->isRequired('stub.save', 'city'));
    }

    public function testResolveRequiredPriority(): void
    {
        $forms = $this->forms();
        self::assertTrue($forms->resolveRequired('title', null, true));
        self::assertFalse($forms->resolveRequired('title', null, false));
        self::assertTrue($forms->resolveRequired('title', null, null, ['title' => true]));
        self::assertTrue($forms->resolveRequired('title', null, null, null, FormsTestListingStub::class));
    }

    public function testDoctrineColumnNameUsedAsFormField(): void
    {
        $forms = $this->forms();
        self::assertContains('offer_type', $forms->requiredFields(FormsTestColumnStub::class));
    }

    public function testFormGeneratorFieldsAndRender(): void
    {
        $forms = $this->forms();
        $entity = new FormsTestGeneratedStub('Hello');
        $fields = $forms->fields($entity);

        self::assertCount(1, $fields);
        self::assertSame('label', $fields[0]->name);
        self::assertSame('Label', $fields[0]->label);
        self::assertTrue($fields[0]->required);
        self::assertSame('Hello', $fields[0]->value);

        $html = (string) $forms->render($entity, ['form' => 'test.save']);
        self::assertStringContainsString('name="label"', $html);
        self::assertStringContainsString('Hello', $html);
    }

    public function testChoicesFromConstantAndPropertyDefaults(): void
    {
        $forms = $this->forms();
        $fields = $forms->fields(FormsTestChoicesStub::class, ['group' => 'basic']);

        self::assertSame(['status', 'energy'], array_map(static fn ($f) => $f->name, $fields));

        $status = $fields[0];
        self::assertSame('draft', $status->value);
        self::assertSame([
            ['value' => 'draft', 'label' => 'listings.status.draft'],
            ['value' => 'published', 'label' => 'listings.status.published'],
        ], $status->choices);

        $energy = $fields[1];
        self::assertNull($energy->value);
        self::assertSame([
            ['value' => '', 'label' => '—'],
            ['value' => 'A', 'label' => 'A'],
            ['value' => 'B', 'label' => 'B'],
        ], $energy->choices);
    }

    public function testChoiceOverridesBeatChoicesFrom(): void
    {
        $forms = $this->forms();
        $fields = $forms->fields(FormsTestChoicesStub::class, [
            'group' => 'basic',
            'only' => ['status'],
            'choices' => [
                'status' => [['value' => 'x', 'label' => 'Custom']],
            ],
        ]);

        self::assertSame([['value' => 'x', 'label' => 'Custom']], $fields[0]->choices);
    }

    private function forms(): Forms
    {
        return new Forms(new ValidationRegistry(new HookManager(), new PassthroughTranslator()), $this->twig());
    }

    private function twig(): Environment
    {
        $base = dirname(__DIR__, 2) . '/resources/views';
        $loader = new FilesystemLoader($base);
        $twig = new Environment($loader, ['strict_variables' => false]);
        $twig->addFunction(new \Twig\TwigFunction('old', static fn (string $k, mixed $d = null): mixed => $d));
        $twig->addFunction(new \Twig\TwigFunction('error', static fn (): ?string => null));
        $twig->addFunction(new \Twig\TwigFunction(
            'field_required',
            static fn (string $name, ?string $form = null, mixed $required = null): bool => (bool) ($required ?? false),
        ));
        $twig->addFunction(new \Twig\TwigFunction('icon', static fn (): string => ''));
        $twig->addFilter(new \Twig\TwigFilter('trans', static fn (string $id): string => $id));

        return $twig;
    }
}

final class FormsTestListingStub
{
    #[FormField(required: true)]
    private string $title = '';

    #[FormField(required: true)]
    private string $city = '';

    private string $slug = '';
}

final class FormsTestCustomerStub
{
    #[AdminField(type: 'text', label: 'Jméno', required: true)]
    private string $name = '';

    #[AdminField(type: 'email', label: 'E-mail', required: true)]
    private string $email = '';

    #[AdminField(type: 'text', label: 'Telefon')]
    private ?string $phone = null;
}

final class FormsTestColumnStub
{
    #[\Doctrine\ORM\Mapping\Column(name: 'offer_type', length: 16)]
    #[FormField(required: true)]
    private string $offerType = 'sale';
}

#[FormGenerator(exclude: ['id'], form: 'test.save', cols: 1)]
final class FormsTestGeneratedStub
{
    private string $id = 'x';

    #[FormField(label: 'Label', type: 'text', required: true, options: ['class' => 'form-control'])]
    private string $label;

    public function __construct(string $label)
    {
        $this->label = $label;
    }

    public function label(): string
    {
        return $this->label;
    }
}

#[FormGenerator(exclude: ['id'], form: 'choices.save')]
final class FormsTestChoicesStub
{
    public const STATUSES = ['draft', 'published'];
    public const ENERGY = ['A', 'B'];

    private string $id = 'x';

    #[FormField(
        label: 'Status',
        type: 'select',
        group: 'basic',
        choicesFrom: 'STATUSES',
        choiceLabel: 'listings.status.',
        order: 10,
    )]
    private string $status = 'draft';

    #[FormField(
        label: 'Energy',
        type: 'select',
        group: 'basic',
        choicesFrom: 'ENERGY',
        emptyLabel: '—',
        order: 20,
    )]
    private ?string $energy = null;
}
