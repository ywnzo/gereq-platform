<?php

declare(strict_types=1);

namespace Modules\Cms\Tests;

use Gereq\Admin\Form\Forms;
use Gereq\Hook\HookManager;
use Gereq\Tests\Support\PassthroughTranslator;
use Gereq\Validation\ValidationRegistry;
use Modules\Cms\Domain\NavLink;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class LinkModalTemplateTest extends TestCase
{
    public function testCreateAndEditFormsHaveIndependentFieldsAndMenuDefaults(): void
    {
        $twig = $this->twig();
        $link = new NavLink('Existing link', '/example');
        $link->setMenuLocations(['footer']);
        $create = $twig->render('@cms/links/_modal_form.html.twig', [
            'form_id' => 'cms-link-create-form', 'modal_id' => 'cms-link-create',
            'action' => '/create', 'item' => null, 'failed' => false,
        ]);
        $edit = $twig->render('@cms/links/_modal_form.html.twig', [
            'form_id' => 'cms-link-edit-test-form', 'modal_id' => 'cms-link-edit-test',
            'action' => '/edit', 'item' => $link, 'failed' => false,
        ]);
        self::assertStringContainsString('id="cms-link-create-title"', $create);
        self::assertStringContainsString('id="cms-link-edit-test-title"', $edit);
        self::assertStringContainsString('value="Existing link"', $edit);
        self::assertStringContainsString('value="footer" data-reset-checked="1" checked', $edit);
        self::assertStringContainsString('value="footer" data-reset-checked="0"', $create);
    }

    public function testFailedFormRestoresOnlyItsOwnInput(): void
    {
        $twig = $this->twig(['title' => 'Unfinished', 'url' => '', 'status' => 'draft', 'menu_locations' => ['header']]);
        $link = new NavLink('Existing link', '/example');
        $failed = $twig->render('@cms/links/_modal_form.html.twig', [
            'form_id' => 'cms-link-create-form', 'modal_id' => 'cms-link-create',
            'action' => '/create', 'item' => null, 'failed' => true,
        ]);
        $other = $twig->render('@cms/links/_modal_form.html.twig', [
            'form_id' => 'cms-link-edit-test-form', 'modal_id' => 'cms-link-edit-test',
            'action' => '/edit', 'item' => $link, 'failed' => false,
        ]);
        self::assertStringContainsString('value="Unfinished"', $failed);
        self::assertStringContainsString('value="header" data-reset-checked="0" checked', $failed);
        self::assertStringContainsString('value="Existing link"', $other);
        self::assertStringNotContainsString('value="Unfinished"', $other);
    }

    public function testListProvidesCreateAndOffPageEditModals(): void
    {
        $twig = $this->twig();
        $onPage = new NavLink('Visible', '/visible');
        $offPage = new NavLink('Other', '/other');
        $user = new class {
            public function can(string $permission): bool
            {
                return $permission === 'cms.manage';
            }
        };
        $html = $twig->render('@cms/links/index.html.twig', [
            'user' => $user, 'items' => [$onPage], 'drawer_item' => $offPage,
            'open_drawer' => 'edit-' . $offPage->id(), 'search' => '', 'status' => '',
            'statuses' => NavLink::STATUSES, 'total' => 1, 'page' => 1, 'perPage' => 20, 'pages' => 1,
        ]);
        self::assertStringContainsString('data-modal-hash="cms-link-create"', $html);
        self::assertStringContainsString('href="#cms-link-edit-' . $onPage->id() . '"', $html);
        self::assertStringContainsString('data-modal-hash="cms-link-edit-' . $offPage->id() . '"', $html);
        self::assertStringContainsString('x-init="show"', $html);
        self::assertStringNotContainsString('data-drawer-open=', $html);
    }

    /** @param array<string, mixed> $old */
    private function twig(array $old = []): Environment
    {
        $root = dirname(__DIR__, 3);
        $loader = new FilesystemLoader($root . '/resources/views');
        $loader->addPath(dirname(__DIR__) . '/Resources/views', 'cms');
        $templates = new ChainLoader([new ArrayLoader(['admin/layout.html.twig' => '{% block content %}{% endblock %}']), $loader]);
        $twig = new Environment($templates, ['strict_variables' => true]);
        $forms = new Forms(new ValidationRegistry(new HookManager(), new PassthroughTranslator()), $twig);
        $twig->addFunction(new TwigFunction('url', static fn (): string => '/fixture'));
        $twig->addFunction(new TwigFunction('form_errors', static fn (): array => []));
        $twig->addFunction(new TwigFunction('forms', static fn (): Forms => $forms));
        $twig->addFunction(new TwigFunction('old', static fn (string $key, mixed $default = null, ?string $form = null): mixed => $form === 'cms.link.save' ? ($old[$key] ?? $default) : $default));
        $twig->addFunction(new TwigFunction('errors', static fn (): array => []));
        $twig->addFunction(new TwigFunction('error', static fn (): ?string => null));
        $twig->addFunction(new TwigFunction('field_required', static fn (string $name, mixed $form, mixed $required): bool => (bool) $required));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('icon', static fn (): string => ''));
        $twig->addFilter(new TwigFilter('trans', static fn (string $value): string => $value));
        return $twig;
    }
}
