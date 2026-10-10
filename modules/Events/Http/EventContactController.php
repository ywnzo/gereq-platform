<?php

declare(strict_types=1);

namespace Modules\Events\Http;

use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Events\Application\EventInbox;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class EventContactController extends Controller
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
        private readonly EventInbox $inbox,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest(
            $request,
            sortable: ['createdAt', 'name', 'email', 'topic'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
        );
        $result = $this->inbox->search($list->search, $list->page, $list->perPage);

        return $this->page('@events/contacts/index.html.twig', array_merge($list->viewData($result['total']), [
            'pageTitle' => $this->translator->trans('events.contacts.title'),
            'items' => $result['items'],
        ]));
    }

    public function destroy(string $id): Response
    {
        $item = $this->inbox->find($id);
        if ($item === null) {
            $this->abort(404, $this->translator->trans('events.contacts.not_found'));
        }
        $this->inbox->delete($item);
        $this->auditor->log('events.contact.deleted', 'event_contact', $id);
        $this->flash('success', $this->translator->trans('events.contacts.flash.deleted'));

        return $this->redirect('admin.events.messages');
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
