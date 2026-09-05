<?php

declare(strict_types=1);

namespace Modules\Projects\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\User\Entity\User;
use Modules\Projects\Domain\Project;
use Modules\Projects\Domain\ProjectTask;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class TaskController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly CapabilityRegistry $capabilities,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function store(Request $request, string $id): Response
    {
        $project = $this->project($id);
        $data = $this->validate($request, 'projects.task.save');
        $task = new ProjectTask($id, trim((string) $data['title']));
        $task->setStatus((string) ($data['status'] ?? 'todo'));
        $assignee = trim((string) ($data['assignee_user_id'] ?? ''));
        $task->setAssigneeUserId($assignee !== '' ? $assignee : null);
        $due = trim((string) ($data['due_at'] ?? ''));
        $task->setDueAt($due !== '' ? new \DateTimeImmutable($due) : null);
        $this->em->persist($task);
        $this->em->flush();
        $payload = $this->serialize($task);
        $this->events->emit('task.created', ['type' => 'project_task', 'id' => $task->id()], $payload);
        if ($assignee !== '') {
            $this->events->emit('task.assigned', ['type' => 'project_task', 'id' => $task->id()], $payload);
            $this->notify($assignee, $task, $project);
        }
        $this->forgetForm('projects.task.save');
        $this->flash('success', $this->translator->trans('projects.flash.task_created'));

        return $this->redirect('admin.projects.show', ['id' => $id]);
    }

    public function update(Request $request, string $id, string $taskId): Response
    {
        $project = $this->project($id);
        $task = $this->em->find(ProjectTask::class, $taskId);
        if (!$task instanceof ProjectTask || $task->projectId() !== $id) {
            $this->abort(404, $this->translator->trans('projects.error.task_not_found'));
        }
        $data = $this->validate($request, 'projects.task.save');
        $prevAssignee = $task->assigneeUserId();
        $prevStatus = $task->status();
        $task->setTitle(trim((string) $data['title']));
        $task->setStatus((string) ($data['status'] ?? $task->status()));
        $assignee = trim((string) ($data['assignee_user_id'] ?? ''));
        $task->setAssigneeUserId($assignee !== '' ? $assignee : null);
        $due = trim((string) ($data['due_at'] ?? ''));
        $task->setDueAt($due !== '' ? new \DateTimeImmutable($due) : null);
        $this->em->flush();
        $payload = $this->serialize($task);
        $this->events->emit('task.updated', ['type' => 'project_task', 'id' => $task->id()], $payload);
        if ($task->status() === 'done' && $prevStatus !== 'done') {
            $this->events->emit('task.completed', ['type' => 'project_task', 'id' => $task->id()], $payload);
        }
        if ($assignee !== '' && $assignee !== $prevAssignee) {
            $this->events->emit('task.assigned', ['type' => 'project_task', 'id' => $task->id()], $payload);
            $this->notify($assignee, $task, $project);
        }
        $this->forgetForm('projects.task.save');
        $this->flash('success', $this->translator->trans('projects.flash.task_saved'));

        return $this->redirect('admin.projects.show', ['id' => $id]);
    }

    public function destroy(string $id, string $taskId): Response
    {
        $this->project($id);
        $task = $this->em->find(ProjectTask::class, $taskId);
        if (!$task instanceof ProjectTask || $task->projectId() !== $id) {
            $this->abort(404, $this->translator->trans('projects.error.task_not_found'));
        }
        $this->em->remove($task);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('projects.flash.task_deleted'));

        return $this->redirect('admin.projects.show', ['id' => $id]);
    }

    private function project(string $id): Project
    {
        $p = $this->em->find(Project::class, $id);
        if (!$p instanceof Project) {
            $this->abort(404, $this->translator->trans('projects.error.not_found'));
        }

        return $p;
    }

    private function notify(string $userId, ProjectTask $task, Project $project): void
    {
        if (!$this->capabilities->has('notifications')) {
            return;
        }

        try {
            $provider = $this->capabilities->get('notifications');
            if (!method_exists($provider, 'bridge')) {
                return;
            }

            $bridge = $provider->bridge();
            if (!is_object($bridge) || !method_exists($bridge, 'notifyUser')) {
                return;
            }

            $user = $this->em->find(User::class, $userId);
            if (!$user instanceof User) {
                return;
            }

            $bridge->notifyUser(
                $user,
                'task.assigned',
                $this->translator->trans('projects.notify.assigned_title'),
                $this->translator->trans('projects.notify.assigned_body', [
                    '%task%' => $task->title(),
                    '%project%' => $project->name(),
                ]),
                ['task_id' => $task->id(), 'project_id' => $project->id()],
            );
        } catch (\Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function serialize(ProjectTask $t): array
    {
        return [
            'id' => $t->id(),
            'project_id' => $t->projectId(),
            'title' => $t->title(),
            'status' => $t->status(),
            'assignee_user_id' => $t->assigneeUserId(),
            'due_at' => $t->dueAt()?->format('Y-m-d'),
            'created_at' => $t->createdAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $t->updatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
