<?php

declare(strict_types=1);

namespace Modules\CRM\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Automation\WorkflowVariableRegistry;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CRM\Domain\Workflow;
use Modules\CRM\Domain\WorkflowRun;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class WorkflowController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly WorkflowVariableRegistry $workflowVariables,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(Workflow::class)->findBy([], ['updatedAt' => 'DESC']);

        return $this->page('@crm/workflows/index.html.twig', [
            'pageTitle' => $this->translator->trans('crm.workflows.title'),
            'items' => $items,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@crm/workflows/form.html.twig', [
            'pageTitle' => $this->translator->trans('crm.workflows.new'),
            'workflow' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|min:2|max:190',
            'description' => 'nullable|max:2000',
            'is_active' => 'nullable',
        ]);
        $workflow = new Workflow((string) $data['name']);
        $workflow->setDescription(isset($data['description']) ? (string) $data['description'] : null);
        $workflow->setActive(in_array((string) ($data['is_active'] ?? '0'), ['1', 'true', 'on', 'yes'], true));
        $this->em->persist($workflow);
        $this->em->flush();
        $this->auditor->log('crm.workflow.created', 'crm_workflow', $workflow->id());
        $this->flash('success', $this->translator->trans('crm.flash.workflow_saved'));

        return $this->redirect('admin.crm.workflows.edit', ['id' => $workflow->id()]);
    }

    public function edit(string $id): Response
    {
        $workflow = $this->find($id);
        $runs = $this->em->getRepository(WorkflowRun::class)->findBy(
            ['workflowId' => $workflow->id()],
            ['createdAt' => 'DESC'],
            30,
        );

        return $this->page('@crm/workflows/edit.html.twig', [
            'pageTitle' => $workflow->name(),
            'workflow' => $workflow,
            'runs' => $runs,
            'graphJson' => json_encode($workflow->graph(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'variablesJson' => json_encode($this->workflowVariables->toArray(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'saveUrl' => $this->router->url('admin.crm.workflows.graph', ['id' => $workflow->id()]),
            'metaUrl' => $this->router->url('admin.crm.workflows.update', ['id' => $workflow->id()]),
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $workflow = $this->find($id);
        $data = $this->validate($request, [
            'name' => 'required|min:2|max:190',
            'description' => 'nullable|max:2000',
            'is_active' => 'nullable',
        ]);
        $workflow->setName((string) $data['name']);
        $workflow->setDescription(isset($data['description']) ? (string) $data['description'] : null);
        $workflow->setActive(in_array((string) ($data['is_active'] ?? '0'), ['1', 'true', 'on', 'yes'], true));
        $this->em->flush();
        $this->auditor->log('crm.workflow.updated', 'crm_workflow', $workflow->id());
        $this->flash('success', $this->translator->trans('crm.flash.workflow_saved'));

        return $this->redirect('admin.crm.workflows.edit', ['id' => $workflow->id()]);
    }

    public function saveGraph(Request $request, string $id): Response
    {
        $workflow = $this->find($id);
        $raw = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($raw)) {
            return new JsonResponse(['error' => ['message' => 'Neplatný JSON']], 422);
        }
        $workflow->setGraph([
            'nodes' => is_array($raw['nodes'] ?? null) ? $raw['nodes'] : [],
            'edges' => is_array($raw['edges'] ?? null) ? $raw['edges'] : [],
        ]);
        $this->em->flush();
        $this->auditor->log('crm.workflow.graph', 'crm_workflow', $workflow->id());

        return new JsonResponse(['data' => ['saved' => true, 'updated_at' => $workflow->updatedAt()->format(\DateTimeInterface::ATOM)]]);
    }

    public function destroy(string $id): Response
    {
        $workflow = $this->find($id);
        $runs = $this->em->getRepository(WorkflowRun::class)->findBy(['workflowId' => $id]);
        foreach ($runs as $run) {
            $this->em->remove($run);
        }
        $this->em->remove($workflow);
        $this->em->flush();
        $this->auditor->log('crm.workflow.deleted', 'crm_workflow', $id);
        $this->flash('success', $this->translator->trans('crm.flash.workflow_deleted'));

        return $this->redirect('admin.crm.workflows.index');
    }

    public function toggle(string $id): Response
    {
        $workflow = $this->find($id);
        $workflow->setActive(!$workflow->isActive());
        $this->em->flush();

        return $this->toggleResponse($workflow->isActive(), 'admin.crm.workflows.index');
    }

    private function find(string $id): Workflow
    {
        $workflow = $this->em->find(Workflow::class, $id);
        if (!$workflow instanceof Workflow) {
            $this->abort(404, $this->translator->trans('crm.error.workflow_not_found'));
        }

        return $workflow;
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
