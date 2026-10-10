<?php

declare(strict_types=1);

namespace Modules\Events\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Validation\ValidationException;
use Modules\Events\Application\EventCategories;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventCategoryController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly EventCategories $categories,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->categories->all();
        $counts = [];
        foreach ($items as $item) {
            $counts[$item->id()] = $this->categories->eventCount($item->slug());
        }

        return $this->page('@events/categories/index.html.twig', [
            'pageTitle' => $this->translator->trans('events.categories.title'),
            'items' => $items,
            'counts' => $counts,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@events/categories/form.html.twig', [
            'pageTitle' => $this->translator->trans('events.categories.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'events.category.save');
        try {
            $item = $this->categories->create($data);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(
                ['slug' => $this->translator->trans($e->getMessage())],
                $data,
                'events.category.save',
            );
        }
        $this->categories->discardStaging($item->id());
        $this->auditor->log('events.category.created', 'event_category', $item->id());
        $this->forgetForm('events.category.save');
        $this->flash('success', $this->translator->trans('events.categories.flash.created'));

        return $this->redirect('admin.events.categories');
    }

    public function edit(string $id): Response
    {
        $item = $this->categories->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.categories.error.not_found'));
        }

        return $this->page('@events/categories/form.html.twig', [
            'pageTitle' => $this->translator->trans('events.categories.edit'),
            'item' => $item,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $item = $this->categories->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.categories.error.not_found'));
        }
        $data = $this->validate($request, 'events.category.save');
        try {
            $this->categories->save($item, $data);
        } catch (\InvalidArgumentException $e) {
            throw new ValidationException(
                ['slug' => $this->translator->trans($e->getMessage())],
                $data,
                'events.category.save',
            );
        }
        $this->categories->discardStaging($item->id());
        $this->auditor->log('events.category.updated', 'event_category', $item->id());
        $this->forgetForm('events.category.save');
        $this->flash('success', $this->translator->trans('events.categories.flash.saved'));

        return $this->redirect('admin.events.categories');
    }

    public function destroy(string $id): Response
    {
        $item = $this->categories->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.categories.error.not_found'));
        }
        $used = $this->categories->eventCount($item->slug());
        if ($used > 0) {
            $this->flash('error', $this->translator->trans('events.categories.error.in_use', ['%count%' => (string) $used]));

            return $this->redirect('admin.events.categories');
        }
        $this->categories->delete($item);
        $this->auditor->log('events.category.deleted', 'event_category', $id);
        $this->flash('success', $this->translator->trans('events.categories.flash.deleted'));

        return $this->redirect('admin.events.categories');
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
