<?php

declare(strict_types=1);

namespace Modules\Ai\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Queue;
use Modules\Ai\Application\Jobs\IngestSourceJob;
use Modules\Ai\Application\Jobs\ReembedKnowledgeBaseJob;
use Modules\Ai\Application\Vector\PgVectorStore;
use Modules\Ai\Application\Vector\SqlVectorStore;
use Modules\Ai\Domain\AiChunk;
use Modules\Ai\Domain\AiKnowledgeBase;
use Modules\Ai\Domain\AiSource;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class KnowledgeController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Queue $queue,
        private readonly SqlVectorStore $sqlStore,
        private readonly PgVectorStore $pgStore,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(AiKnowledgeBase::class)->findBy([], ['name' => 'ASC']);
        $sources = [];
        foreach ($items as $kb) {
            $sources[$kb->id()] = $this->em->getRepository(AiSource::class)->findBy(
                ['knowledgeBaseId' => $kb->id()],
                ['updatedAt' => 'DESC'],
                50,
            );
        }

        return $this->page('@ai/knowledge/index.html.twig', [
            'pageTitle' => $this->translator->trans('ai.knowledge.title'),
            'items' => $items,
            'sources' => $sources,
            'visibilities' => AiKnowledgeBase::VISIBILITIES,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'ai.knowledge.save');
        $slug = strtolower(trim((string) $data['slug']));
        $slug = preg_replace('/[^a-z0-9\-]+/', '-', $slug) ?? $slug;
        $kb = new AiKnowledgeBase(trim($slug, '-'), (string) $data['name'], (string) $data['visibility']);
        $kb->setDescription($request->request->get('description') ? (string) $request->request->get('description') : null);
        $this->em->persist($kb);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('ai.flash.kb_saved'));

        return $this->redirect('admin.ai.knowledge.index');
    }

    public function addSource(Request $request, string $id): Response
    {
        $kb = $this->em->find(AiKnowledgeBase::class, $id);
        if (!$kb instanceof AiKnowledgeBase) {
            $this->abort(404);
        }
        $type = (string) $request->request->get('source_type', 'manual_text');
        $title = trim((string) $request->request->get('title', 'Manual'));
        $text = (string) $request->request->get('text', '');
        $ref = (string) $request->request->get('source_ref', '');
        if ($type === 'manual_text') {
            $ref = bin2hex(random_bytes(8));
        }
        $source = new AiSource($kb->id(), $type, $ref !== '' ? $ref : bin2hex(random_bytes(8)), $title !== '' ? $title : 'Source', [
            'text' => $text,
            'visibility' => $kb->visibility(),
        ]);
        $this->em->persist($source);
        $this->em->flush();
        $this->queue->dispatch(new IngestSourceJob($source->id()));
        $this->flash('success', $this->translator->trans('ai.flash.source_queued'));

        return $this->redirect('admin.ai.knowledge.source.edit', ['id' => $source->id()]);
    }

    public function editSource(string $id): Response
    {
        $source = $this->findSource($id);
        $kb = $this->em->find(AiKnowledgeBase::class, $source->knowledgeBaseId());

        return $this->page('@ai/knowledge/source.html.twig', [
            'pageTitle' => $source->title(),
            'source' => $source,
            'kb' => $kb instanceof AiKnowledgeBase ? $kb : null,
            'text' => (string) ($source->acl()['text'] ?? ''),
        ]);
    }

    public function updateSource(Request $request, string $id): Response
    {
        $source = $this->findSource($id);
        $title = trim((string) $request->request->get('title', ''));
        if ($title === '') {
            $this->flash('error', $this->translator->trans('ai.error.source_title'));

            return $this->redirect('admin.ai.knowledge.source.edit', ['id' => $source->id()]);
        }
        $source->setTitle($title);

        if ($source->sourceType() === 'manual_text') {
            $text = (string) $request->request->get('text', '');
            $acl = $source->acl();
            $acl['text'] = $text;
            $source->setAcl($acl);
            $source->markPending();
            $this->em->flush();
            $this->queue->dispatch(new IngestSourceJob($source->id()));
            $this->flash('success', $this->translator->trans('ai.flash.source_updated'));
        } else {
            $this->em->flush();
            $this->flash('success', $this->translator->trans('ai.flash.source_saved'));
        }

        return $this->redirect('admin.ai.knowledge.source.edit', ['id' => $source->id()]);
    }

    public function reingestSource(string $id): Response
    {
        $source = $this->findSource($id);
        $source->markPending();
        $this->em->flush();
        $this->queue->dispatch(new IngestSourceJob($source->id()));
        $this->flash('success', $this->translator->trans('ai.flash.source_queued'));

        return $this->redirect('admin.ai.knowledge.source.edit', ['id' => $source->id()]);
    }

    public function destroySource(string $id): Response
    {
        $source = $this->findSource($id);
        $this->sqlStore->deleteBySource($source->id());
        $this->pgStore->deleteBySource($source->id());
        foreach ($this->em->getRepository(AiChunk::class)->findBy(['sourceId' => $source->id()]) as $chunk) {
            $this->em->remove($chunk);
        }
        $this->em->remove($source);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('ai.flash.source_deleted'));

        return $this->redirect('admin.ai.knowledge.index');
    }

    public function reindex(string $id): Response
    {
        $kb = $this->em->find(AiKnowledgeBase::class, $id);
        if (!$kb instanceof AiKnowledgeBase) {
            $this->abort(404);
        }
        $this->queue->dispatch(new ReembedKnowledgeBaseJob($kb->id()));
        $this->flash('success', $this->translator->trans('ai.flash.reindex_queued'));

        return $this->redirect('admin.ai.knowledge.index');
    }

    private function findSource(string $id): AiSource
    {
        $source = $this->em->find(AiSource::class, $id);
        if (!$source instanceof AiSource) {
            $this->abort(404);
        }

        return $source;
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
