<?php

declare(strict_types=1);

namespace Modules\Documents\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\Documents\Domain\Document;
use Modules\Documents\Domain\DocumentLink;
use Modules\Documents\Domain\Events\DocumentDeleted;
use Modules\Documents\Domain\Events\DocumentLinked;
use Modules\Documents\Domain\Events\DocumentUploaded;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class DocumentController extends Controller
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
        private readonly EventBus $events,
        private readonly TranslatorInterface $translator,
        private readonly FileManager $files,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(Request $request): Response
    {
        $list = \Gereq\Http\ListQuery::fromRequest(
            $request,
            sortable: ['title', 'mime', 'size', 'createdAt'],
            defaultSort: 'createdAt',
            defaultDir: 'desc',
        );

        $qb = $this->em->getRepository(Document::class)->createQueryBuilder('d');

        if ($list->search !== '') {
            $qb->andWhere('d.title LIKE :q OR d.originalName LIKE :q OR d.mime LIKE :q')
                ->setParameter('q', '%' . $list->search . '%');
        }

        $qb->orderBy('d.' . $list->sort, $list->direction());

        $total = (int) (clone $qb)->select('COUNT(d.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($list->offset())->setMaxResults($list->perPage)->getQuery()->getResult();

        return $this->page('@documents/index.html.twig', array_merge($list->viewData($total), [
            'pageTitle' => $this->translator->trans('documents.title'),
            'items' => $items,
        ]));
    }

    public function create(Request $request): Response
    {
        return $this->page('@documents/form.html.twig', [
            'pageTitle' => $this->translator->trans('documents.upload'),
            'entityType' => (string) $request->query->get('entity_type', ''),
            'entityId' => (string) $request->query->get('entity_id', ''),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, 'documents.save');
        $uploaded = $request->files->get('file');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            $this->flash('error', $this->translator->trans('documents.error.file_required'));

            return $this->redirect('admin.documents.upload');
        }

        try {
            $stored = $this->files->store($uploaded, $this->auth->user());
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());

            return $this->redirect('admin.documents.upload');
        }

        $title = trim((string) $data['title']);
        if ($title === '') {
            $title = $stored->originalName();
        }

        $document = new Document(
            $title,
            $stored->path(),
            $stored->originalName(),
            $stored->mime(),
            $stored->size(),
            $this->auth->user()?->id(),
        );
        $this->em->persist($document);
        $this->em->flush();

        $payload = $this->publicDocument($document);
        $this->events->dispatch(DocumentUploaded::make($document->id(), $payload));
        $this->auditor->log('document.uploaded', 'document', $document->id(), null, [
            'title' => $document->title(),
            'path' => $document->path(),
        ]);

        $entityType = trim((string) ($data['entity_type'] ?? ''));
        $entityId = trim((string) ($data['entity_id'] ?? ''));
        if ($entityType !== '' && $entityId !== '') {
            $this->attachLink($document, $entityType, $entityId);
        }

        $this->forgetForm('documents.save');
        $this->flash('success', $this->translator->trans('documents.flash.uploaded', ['%title%' => $document->title()]));

        return $this->redirect('admin.documents.show', ['id' => $document->id()]);
    }

    public function show(string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $links = $this->em->getRepository(DocumentLink::class)->findBy(
            ['documentId' => $document->id()],
            ['createdAt' => 'DESC'],
        );

        return $this->page('@documents/show.html.twig', [
            'pageTitle' => $document->title(),
            'item' => $document,
            'links' => $links,
        ]);
    }

    public function link(Request $request, string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $data = $this->validate($request, 'documents.link');
        $entityType = trim((string) $data['entity_type']);
        $entityId = trim((string) $data['entity_id']);

        $existing = $this->em->getRepository(DocumentLink::class)->findOneBy([
            'documentId' => $document->id(),
            'entityType' => $entityType,
            'entityId' => $entityId,
        ]);
        if ($existing === null) {
            $this->attachLink($document, $entityType, $entityId);
            $this->flash('success', $this->translator->trans('documents.flash.linked'));
        } else {
            $this->flash('success', $this->translator->trans('documents.flash.already_linked'));
        }

        $this->forgetForm('documents.link');

        return $this->redirect('admin.documents.show', ['id' => $id]);
    }

    public function destroy(string $id): Response
    {
        $document = $this->find($id);
        if ($document === null) {
            $this->abort(404, $this->translator->trans('documents.error.not_found'));
        }

        $title = $document->title();
        $payload = $this->publicDocument($document);

        $links = $this->em->getRepository(DocumentLink::class)->findBy(['documentId' => $id]);
        foreach ($links as $link) {
            $this->em->remove($link);
        }

        $this->em->remove($document);
        $this->em->flush();
        $this->events->dispatch(DocumentDeleted::make($id, $payload));
        $this->auditor->log('document.deleted', 'document', $id);
        $this->flash('success', $this->translator->trans('documents.flash.deleted', ['%title%' => $title]));

        return $this->redirect('admin.documents.index');
    }

    private function attachLink(Document $document, string $entityType, string $entityId): void
    {
        $link = new DocumentLink($document->id(), $entityType, $entityId);
        $this->em->persist($link);
        $this->em->flush();

        $payload = array_merge($this->publicDocument($document), [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'link_id' => $link->id(),
        ]);
        $this->events->dispatch(DocumentLinked::make($document->id(), $payload));
        $this->auditor->log('document.linked', 'document', $document->id(), null, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    private function find(string $id): ?Document
    {
        $document = $this->em->find(Document::class, $id);

        return $document instanceof Document ? $document : null;
    }

    /** @return array<string, mixed> */
    private function publicDocument(Document $document): array
    {
        return [
            'id' => $document->id(),
            'title' => $document->title(),
            'path' => $document->path(),
            'original_name' => $document->originalName(),
            'mime' => $document->mime(),
            'size' => $document->size(),
            'uploaded_by' => $document->uploadedBy(),
            'created_at' => $document->createdAt()->format(\DateTimeInterface::ATOM),
        ];
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
