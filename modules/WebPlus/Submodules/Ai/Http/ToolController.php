<?php

declare(strict_types=1);

namespace Modules\WebPlus\Submodules\Ai\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Gereq\Setting\Settings;
use Modules\WebPlus\Submodules\Ai\Application\AiProvider;
use Modules\WebPlus\Submodules\Ai\Domain\AgentTool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class ToolController extends Controller
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
        private readonly AiProvider $provider,
        private readonly Settings $settings,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $this->provider->ensureDefaults();

        $list = ListQuery::fromRequest(
            $request,
            sortable: ['name', 'title', 'sortOrder', 'updatedAt'],
            defaultSort: 'sortOrder',
            defaultDir: 'asc',
            filterKeys: ['enabled'],
        );
        $enabled = $list->filter('enabled');

        $qb = $this->em->getRepository(AgentTool::class)->createQueryBuilder('t');
        $list->applySearch($qb, 't', ['name', 'title', 'description']);
        if ($enabled === '1') {
            $qb->andWhere('t.enabled = true');
        } elseif ($enabled === '0') {
            $qb->andWhere('t.enabled = false');
        }
        $qb->orderBy('t.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(t.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@webplus_ai/tools/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('webplus_ai.tools.title'),
            'items' => $items,
            'enabled' => $enabled,
            'moduleEnabled' => $this->provider->isEnabled(),
            'catalogPath' => '/tools.json',
        ]));
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        if ($this->provider->findByName($data['name']) instanceof AgentTool) {
            $this->abort(422, $this->translator->trans('webplus_ai.error.name_taken'));
        }

        $tool = new AgentTool($data['name'], $data['title'], $data['description']);
        $this->applyData($tool, $data);
        $this->em->persist($tool);
        $this->em->flush();

        $this->auditor->log('webplus_ai.tool.created', newValues:['id' => $tool->id(), 'name' => $tool->name()]);
        $this->flash('success', $this->translator->trans('webplus_ai.flash.created'));

        return $this->redirect('admin.webplus_ai.tools.edit', ['id' => $tool->id()]);
    }

    public function edit(string $id): Response
    {
        $tool = $this->provider->find($id);
        if ($tool === null) {
            $this->abort(404, $this->translator->trans('webplus_ai.error.not_found'));
        }

        return $this->formPage($tool);
    }

    public function update(Request $request, string $id): Response
    {
        $tool = $this->provider->find($id);
        if ($tool === null) {
            $this->abort(404, $this->translator->trans('webplus_ai.error.not_found'));
        }

        $data = $this->validated($request);
        if (!$tool->isBuiltin()) {
            $other = $this->provider->findByName($data['name']);
            if ($other instanceof AgentTool && $other->id() !== $tool->id()) {
                $this->abort(422, $this->translator->trans('webplus_ai.error.name_taken'));
            }
            $tool->setName($data['name']);
        }

        $this->applyData($tool, $data);
        $this->em->flush();

        $this->auditor->log('webplus_ai.tool.updated', newValues: ['id' => $tool->id(), 'name' => $tool->name()]);
        $this->flash('success', $this->translator->trans('webplus_ai.flash.saved'));

        return $this->redirect('admin.webplus_ai.tools.edit', ['id' => $tool->id()]);
    }

    public function toggle(string $id): Response
    {
        $tool = $this->provider->find($id);
        if ($tool === null) {
            $this->abort(404, $this->translator->trans('webplus_ai.error.not_found'));
        }
        $tool->setEnabled(!$tool->isEnabled());
        $this->em->flush();
        $this->flash('success', $this->translator->trans('webplus_ai.flash.saved'));

        return $this->redirect('admin.webplus_ai.tools.index');
    }

    public function destroy(string $id): Response
    {
        $tool = $this->provider->find($id);
        if ($tool === null) {
            $this->abort(404, $this->translator->trans('webplus_ai.error.not_found'));
        }
        if ($tool->isBuiltin()) {
            $this->abort(422, $this->translator->trans('webplus_ai.error.builtin_locked'));
        }
        $this->em->remove($tool);
        $this->em->flush();
        $this->auditor->log('webplus_ai.tool.deleted', oldValues: ['id' => $id]);
        $this->flash('success', $this->translator->trans('webplus_ai.flash.deleted'));

        return $this->redirect('admin.webplus_ai.tools.index');
    }

    public function settings(): Response
    {
        return $this->page('@webplus_ai/settings.html.twig', [
            'pageTitle' => $this->translator->trans('webplus_ai.settings.title'),
            'enabled' => $this->provider->isEnabled(),
            'catalogPath' => '/tools.json',
            'catalogApi' => '/api/public/webplus-ai/tools.json',
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $enabled = in_array((string) $request->request->get('webplus_ai_enabled', '0'), ['1', 'true', 'on'], true);
        $this->settings->set('webplus_ai.enabled', $enabled);
        $this->flash('success', $this->translator->trans('webplus_ai.flash.saved'));

        return $this->redirect('admin.webplus_ai.settings');
    }

    private function formPage(?AgentTool $tool): Response
    {
        return $this->page('@webplus_ai/tools/form.html.twig', [
            'pageTitle' => $tool
                ? $this->translator->trans('webplus_ai.tools.edit')
                : $this->translator->trans('webplus_ai.tools.new'),
            'item' => $tool,
            'inputSchemaJson' => $tool
                ? json_encode($tool->inputSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : "{\n  \"type\": \"object\",\n  \"properties\": {}\n}",
            'outputSchemaJson' => $tool && $tool->outputSchema() !== null
                ? json_encode($tool->outputSchema(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : '',
        ]);
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

    /**
     * @return array{
     *   name: string,
     *   title: string,
     *   description: string,
     *   enabled: bool,
     *   mutating: bool,
     *   sort_order: int,
     *   input_schema: array<string, mixed>,
     *   output_schema: array<string, mixed>|null
     * }
     */
    private function validated(Request $request): array
    {
        $name = trim((string) $request->request->get('name', ''));
        $title = trim((string) $request->request->get('title', ''));
        $description = trim((string) $request->request->get('description', ''));
        if ($name === '' || $title === '' || $description === '') {
            $this->abort(422, $this->translator->trans('webplus_ai.error.required'));
        }

        $inputRaw = trim((string) $request->request->get('input_schema', ''));
        $outputRaw = trim((string) $request->request->get('output_schema', ''));
        $input = $this->decodeJsonObject($inputRaw, required: true);
        $output = $outputRaw !== '' ? $this->decodeJsonObject($outputRaw, required: false) : null;

        return [
            'name' => $name,
            'title' => $title,
            'description' => $description,
            'enabled' => in_array((string) $request->request->get('enabled', '1'), ['1', 'true', 'on'], true),
            'mutating' => in_array((string) $request->request->get('mutating', '0'), ['1', 'true', 'on'], true),
            'sort_order' => (int) $request->request->get('sort_order', 0),
            'input_schema' => $input,
            'output_schema' => $output,
        ];
    }

    /**
     * @param array{
     *   name: string,
     *   title: string,
     *   description: string,
     *   enabled: bool,
     *   mutating: bool,
     *   sort_order: int,
     *   input_schema: array<string, mixed>,
     *   output_schema: array<string, mixed>|null
     * } $data
     */
    private function applyData(AgentTool $tool, array $data): void
    {
        $tool->setTitle($data['title']);
        $tool->setDescription($data['description']);
        $tool->setInputSchema($data['input_schema']);
        $tool->setOutputSchema($data['output_schema']);
        $tool->setEnabled($data['enabled']);
        $tool->setMutating($data['mutating']);
        $tool->setSortOrder($data['sort_order']);
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $raw, bool $required): array
    {
        if ($raw === '') {
            if ($required) {
                $this->abort(422, $this->translator->trans('webplus_ai.error.invalid_json'));
            }

            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->abort(422, $this->translator->trans('webplus_ai.error.invalid_json'));
        }
        if (!is_array($decoded)) {
            $this->abort(422, $this->translator->trans('webplus_ai.error.invalid_json'));
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
