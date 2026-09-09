<?php

declare(strict_types=1);

namespace Modules\Ai\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Ai\Application\CustomToolCatalog;
use Modules\Ai\Domain\AiCustomTool;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class CustomToolController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(AiCustomTool::class)->findBy([], ['name' => 'ASC']);

        return $this->page('@ai/tools/index.html.twig', [
            'pageTitle' => $this->translator->trans('ai.tools.title'),
            'items' => $items,
        ]);
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'ai.custom_tool.save');
        $tool = new AiCustomTool(
            CustomToolCatalog::normalizeName((string) $data['name']),
            (string) $data['label'],
            (string) $data['description'],
            (string) ($data['handler_type'] ?? 'static'),
        );
        $this->apply($tool, $data, $request);
        $this->em->persist($tool);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('ai.flash.custom_tool_saved'));

        return $this->redirect('admin.ai.tools.edit', ['id' => $tool->id()]);
    }

    public function edit(string $id): Response
    {
        return $this->formPage($this->find($id));
    }

    public function update(Request $request, string $id): Response
    {
        $tool = $this->find($id);
        $data = $this->validate($request, 'ai.custom_tool.save');
        $tool->setName(CustomToolCatalog::normalizeName((string) $data['name']));
        $tool->setLabel((string) $data['label']);
        $tool->setDescription((string) $data['description']);
        $tool->setHandlerType((string) ($data['handler_type'] ?? 'static'));
        $this->apply($tool, $data, $request);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('ai.flash.custom_tool_saved'));

        return $this->redirect('admin.ai.tools.edit', ['id' => $tool->id()]);
    }

    public function destroy(string $id): Response
    {
        $tool = $this->find($id);
        $this->em->remove($tool);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('ai.flash.custom_tool_deleted'));

        return $this->redirect('admin.ai.tools.index');
    }

    private function formPage(?AiCustomTool $tool): Response
    {
        $cfg = $tool?->config() ?? [];
        $schemaJson = json_encode($tool?->parametersSchema() ?? [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
            ],
            'required' => ['query'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        $staticResponse = '';
        if (($tool?->handlerType() ?? 'static') === 'static') {
            $staticResponse = json_encode($cfg['response'] ?? ['ok' => true], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return $this->page('@ai/tools/form.html.twig', [
            'pageTitle' => $tool
                ? $this->translator->trans('ai.tools.edit')
                : $this->translator->trans('ai.tools.new'),
            'item' => $tool,
            'handlers' => AiCustomTool::HANDLERS,
            'schema_json' => is_string($schemaJson) ? $schemaJson : '{}',
            'static_response' => $staticResponse,
            'http_url' => (string) ($cfg['url'] ?? ''),
            'http_method' => (string) ($cfg['method'] ?? 'POST'),
            'http_timeout' => (int) ($cfg['timeout'] ?? 15),
            'selectedSurfaces' => $tool?->surfaces() ?? ['admin'],
        ]);
    }

    /** @param array<string, mixed> $data */
    private function apply(AiCustomTool $tool, array $data, Request $request): void
    {
        $schemaRaw = (string) $request->request->get('parameters_schema', '');
        $schema = json_decode($schemaRaw, true);
        if (!is_array($schema)) {
            $schema = ['type' => 'object', 'properties' => []];
        }
        if (($schema['type'] ?? null) !== 'object') {
            $schema = ['type' => 'object', 'properties' => is_array($schema['properties'] ?? null) ? $schema['properties'] : []];
        }
        $tool->setParametersSchema($schema);

        $handler = $tool->handlerType();
        if ($handler === 'http') {
            $tool->setConfig([
                'url' => trim((string) $request->request->get('http_url', '')),
                'method' => strtoupper(trim((string) $request->request->get('http_method', 'POST'))) ?: 'POST',
                'timeout' => max(1, min(30, (int) $request->request->get('http_timeout', 15))),
            ]);
        } else {
            $raw = (string) $request->request->get('static_response', '');
            $decoded = json_decode($raw, true);
            $tool->setConfig([
                'response' => is_array($decoded) ? $decoded : ['text' => $raw],
                'echo_args' => in_array($request->request->get('echo_args'), [true, 1, '1', 'on'], true),
            ]);
        }

        $surfaces = array_values(array_filter(array_map('strval', (array) $request->request->all('surfaces'))));
        $surfaces = array_values(array_intersect($surfaces, ['admin', 'api', 'public']));
        $tool->setSurfaces($surfaces !== [] ? $surfaces : ['admin']);

        $perm = trim((string) $request->request->get('permission', ''));
        $tool->setPermission($perm !== '' ? $perm : null);
        $tool->setEnabled(in_array($request->request->get('enabled'), [true, 1, '1', 'on', 'yes'], true));
    }

    private function find(string $id): AiCustomTool
    {
        $tool = $this->em->find(AiCustomTool::class, $id);
        if (!$tool instanceof AiCustomTool) {
            $this->abort(404);
        }

        return $tool;
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
