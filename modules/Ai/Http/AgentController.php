<?php

declare(strict_types=1);

namespace Modules\Ai\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\ListQuery;
use Gereq\Http\Router;
use Modules\Ai\Application\AgentService;
use Modules\Ai\Application\ToolRegistry;
use Modules\Ai\Domain\AiAgent;
use Modules\Ai\Domain\AiKnowledgeBase;
use Modules\Ai\Domain\AiModel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AgentController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly AgentService $agents,
        private readonly ToolRegistry $tools,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = ListQuery::fromRequest($request, sortable: ['name', 'slug', 'updatedAt'], defaultSort: 'updatedAt', defaultDir: 'desc');
        $qb = $this->em->getRepository(AiAgent::class)->createQueryBuilder('a');
        $list->applySearch($qb, 'a', ['name', 'slug']);
        $qb->orderBy('a.' . $list->sort, $list->direction());
        $total = (int) (clone $qb)->select('COUNT(a.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@ai/agents/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('ai.agents.title'),
            'items' => $items,
        ]));
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'ai.agent.save');
        $agent = new AiAgent(
            $this->slugify((string) $data['slug']),
            (string) $data['name'],
            (string) $data['system_prompt'],
            $this->parseSurfaces($request),
        );
        $this->apply($agent, $data, $request);
        $this->em->persist($agent);
        $this->em->flush();
        $this->syncRelations($agent, $request);
        $this->flash('success', $this->translator->trans('ai.flash.agent_saved'));

        return $this->redirect('admin.ai.agents.edit', ['id' => $agent->id()]);
    }

    public function edit(string $id): Response
    {
        return $this->formPage($this->find($id));
    }

    public function update(Request $request, string $id): Response
    {
        $agent = $this->find($id);
        $data = $this->validate($request, 'ai.agent.save');
        $agent->setSlug($this->slugify((string) $data['slug']));
        $agent->setName((string) $data['name']);
        $agent->setSystemPrompt((string) $data['system_prompt']);
        $agent->setSurfaces($this->parseSurfaces($request));
        $this->apply($agent, $data, $request);
        $this->em->flush();
        $this->syncRelations($agent, $request);
        $this->flash('success', $this->translator->trans('ai.flash.agent_saved'));

        return $this->redirect('admin.ai.agents.edit', ['id' => $agent->id()]);
    }

    public function destroy(string $id): Response
    {
        $agent = $this->find($id);
        $this->agents->syncTools($agent, []);
        $this->agents->syncKnowledge($agent, []);
        $this->agents->syncPeers($agent, []);
        $this->em->remove($agent);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('ai.flash.agent_deleted'));

        return $this->redirect('admin.ai.agents.index');
    }

    private function formPage(?AiAgent $agent): Response
    {
        return $this->page('@ai/agents/form.html.twig', [
            'pageTitle' => $agent
                ? $this->translator->trans('ai.agents.edit')
                : $this->translator->trans('ai.agents.new'),
            'item' => $agent,
            'models' => $this->em->getRepository(AiModel::class)->findBy(['enabled' => true], ['family' => 'ASC', 'modelId' => 'ASC']),
            'tools' => $this->tools->all(),
            'selectedTools' => $agent ? $this->agents->toolAllowlist($agent->id()) : [],
            'knowledgeBases' => $this->em->getRepository(AiKnowledgeBase::class)->findBy([], ['name' => 'ASC']),
            'selectedKb' => $agent ? $this->agents->knowledgeBaseIds($agent->id()) : [],
            'peerAgents' => $this->em->getRepository(AiAgent::class)->findBy([], ['name' => 'ASC']),
            'selectedPeers' => $agent ? $this->agents->peerIds($agent->id()) : [],
            'writeModes' => AiAgent::WRITE_MODES,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function apply(AiAgent $agent, array $data, Request $request): void
    {
        $agent->setDescription($request->request->get('description') ? (string) $request->request->get('description') : null);
        $agent->setPlaybook($request->request->get('playbook') ? (string) $request->request->get('playbook') : null);
        $agent->setWriteMode((string) ($data['write_mode'] ?? 'none') ?: 'none');
        $published = $request->request->get('published');
        $agent->setPublished(in_array($published, [true, 1, '1', 'on', 'yes'], true));
        $agent->setChatModelId($request->request->get('chat_model_id') ? (string) $request->request->get('chat_model_id') : null);
        $agent->setEmbedModelId($request->request->get('embed_model_id') ? (string) $request->request->get('embed_model_id') : null);
        $agent->setTemperature((float) $request->request->get('temperature', 0.3));
        $agent->setRateLimitRpm((int) $request->request->get('rate_limit_rpm', 30));
        $agent->setMaxToolRounds((int) $request->request->get('max_tool_rounds', 8));
    }

    private function syncRelations(AiAgent $agent, Request $request): void
    {
        $tools = array_values(array_filter(array_map('strval', (array) $request->request->all('tools'))));
        $kb = array_values(array_filter(array_map('strval', (array) $request->request->all('knowledge_bases'))));
        $peers = array_values(array_filter(array_map('strval', (array) $request->request->all('peers'))));
        $this->agents->syncTools($agent, $tools);
        $this->agents->syncKnowledge($agent, $kb);
        $this->agents->syncPeers($agent, $peers);
    }

    /** @return list<string> */
    private function parseSurfaces(Request $request): array
    {
        $surfaces = array_values(array_filter(array_map('strval', (array) $request->request->all('surfaces'))));
        $surfaces = array_values(array_intersect($surfaces, ['admin', 'api', 'public']));

        return $surfaces !== [] ? $surfaces : ['admin'];
    }

    private function slugify(string $slug): string
    {
        $slug = strtolower(trim($slug));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? $slug;

        return trim($slug, '-') ?: 'agent';
    }

    private function find(string $id): AiAgent
    {
        $agent = $this->em->find(AiAgent::class, $id);
        if (!$agent instanceof AiAgent) {
            $this->abort(404);
        }

        return $agent;
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
