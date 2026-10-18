<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit\Admin\Ui;

use Gereq\Testing\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class ComponentMigrationTest extends TestCase
{
    public function testOptionEscapesValuesAndLabels(): void
    {
        $html = $this->twig()->render('ui/option.html.twig', [
            'value' => '" onmouseover="alert(1)',
            'label' => '<script>alert(1)</script>',
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringContainsString('&quot; onmouseover=&quot;', $html);
    }

    public function testCustomPanelDoesNotEvaluateUnusedActions(): void
    {
        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('unused_actions', static function (): never {
            throw new \LogicException('Unused panel actions were evaluated.');
        }));
        $html = $twig->createTemplate(<<<'TWIG'
{% embed 'ui/panel.html.twig' %}
{% block actions %}{{ unused_actions() }}{% endblock %}
{% block content %}<form method="post"><input type="hidden" name="_token" value="csrf"></form>{% endblock %}
{% endembed %}
TWIG)->render([]);

        self::assertStringContainsString('name="_token" value="csrf"', $html);
        self::assertStringNotContainsString('panel__head', $html);
    }

    public function testButtonEscapesAttributesAndRejectsInvalidAttributeNames(): void
    {
        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('icon', static fn (): string => ''));
        $html = $twig->render('ui/button.html.twig', [
            'label' => '<script>unsafe</script>',
            'attrs' => ['title' => '" unsafe="yes', 'bad"name' => 'unsafe'],
        ]);

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('bad"name', $html);
        self::assertStringContainsString('title="&quot; unsafe=&quot;yes"', $html);
    }

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(dirname(__DIR__, 4) . '/resources/views'));
    }
}
