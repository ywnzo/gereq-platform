<?php

declare(strict_types=1);

namespace Modules\CarService\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Auth\Authenticator;
use Gereq\File\FileManager;
use Gereq\File\StoredFile;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Modules\CarService\Application\BranchContext;
use Modules\CarService\Domain\ServiceBranch;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class BranchController extends Controller
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
        private readonly FileManager $files,
        private readonly BranchContext $branchContext,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $items = $this->em->getRepository(ServiceBranch::class)->findBy([], ['name' => 'ASC']);

        return $this->page('@carservice/branches/index.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.branches.title'),
            'items' => $items,
        ]);
    }

    public function select(Request $request): Response
    {
        $branchId = trim((string) $request->request->get('branch_id', ''));
        $this->branchContext->setBranchId($branchId !== '' ? $branchId : null);

        $redirect = trim((string) $request->request->get('_redirect', ''));
        if ($redirect !== '' && str_starts_with($redirect, '/') && !str_starts_with($redirect, '//')) {
            return new \Symfony\Component\HttpFoundation\RedirectResponse($redirect);
        }

        return $this->redirect('admin.carservice.index');
    }

    public function create(): Response
    {
        return $this->page('@carservice/branches/form.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.branches.new'),
            'item' => null,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $branch = new ServiceBranch($data['name']);
        $this->apply($branch, $data);
        $this->storeLogo($request, $branch);
        $this->em->persist($branch);
        $this->em->flush();
        $this->forgetForm('carservice.branch');
        $this->flash('success', $this->translator->trans('carservice.flash.branch_saved'));

        return $this->redirect('admin.carservice.branches');
    }

    public function edit(string $id): Response
    {
        $branch = $this->em->find(ServiceBranch::class, $id);
        if (!$branch instanceof ServiceBranch) {
            $this->abort(404, $this->translator->trans('carservice.error.branch_not_found'));
        }

        return $this->page('@carservice/branches/form.html.twig', [
            'pageTitle' => $this->translator->trans('carservice.branches.edit'),
            'item' => $branch,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $branch = $this->em->find(ServiceBranch::class, $id);
        if (!$branch instanceof ServiceBranch) {
            $this->abort(404, $this->translator->trans('carservice.error.branch_not_found'));
        }
        $data = $this->validated($request);
        $this->apply($branch, $data);
        $this->storeLogo($request, $branch);
        $this->em->flush();
        $this->forgetForm('carservice.branch');
        $this->flash('success', $this->translator->trans('carservice.flash.branch_saved'));

        return $this->redirect('admin.carservice.branches');
    }

    public function destroy(string $id): Response
    {
        $branch = $this->em->find(ServiceBranch::class, $id);
        if (!$branch instanceof ServiceBranch) {
            $this->abort(404, $this->translator->trans('carservice.error.branch_not_found'));
        }
        $this->em->remove($branch);
        $this->em->flush();
        $this->flash('success', $this->translator->trans('carservice.flash.branch_deleted'));

        return $this->redirect('admin.carservice.branches');
    }

    public function logo(string $id): Response
    {
        $branch = $this->em->find(ServiceBranch::class, $id);
        if (!$branch instanceof ServiceBranch || $branch->logoFileId() === null) {
            $this->abort(404, $this->translator->trans('carservice.error.branch_logo_missing'));
        }
        $file = $this->em->find(StoredFile::class, $branch->logoFileId());
        if (!$file instanceof StoredFile) {
            $this->abort(404, $this->translator->trans('carservice.error.branch_logo_missing'));
        }
        try {
            $contents = $this->files->filesystem()->read($file->path());
        } catch (\Throwable) {
            $this->abort(404, $this->translator->trans('carservice.error.branch_logo_missing'));
        }

        return new Response($contents, 200, [
            'Content-Type' => $file->mime(),
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $this->validate($request, 'carservice.branch');
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $this->abort(422, $this->translator->trans('carservice.error.branch_name_required'));
        }
        $employees = trim((string) ($data['employee_count'] ?? '0'));

        return [
            'name' => $name,
            'street' => trim((string) ($data['street'] ?? '')) ?: null,
            'city' => trim((string) ($data['city'] ?? '')) ?: null,
            'zip' => trim((string) ($data['zip'] ?? '')) ?: null,
            'country' => strtoupper(trim((string) ($data['country'] ?? 'CZ'))) ?: 'CZ',
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'employee_count' => $employees !== '' ? max(0, (int) $employees) : 0,
            'note' => trim((string) ($data['note'] ?? '')) ?: null,
            'active' => $request->request->has('active'),
        ];
    }

    /** @param array<string, mixed> $data */
    private function apply(ServiceBranch $branch, array $data): void
    {
        $branch->setName((string) $data['name']);
        $branch->setStreet($data['street']);
        $branch->setCity($data['city']);
        $branch->setZip($data['zip']);
        $branch->setCountry((string) $data['country']);
        $branch->setPhone($data['phone']);
        $branch->setEmail($data['email']);
        $branch->setEmployeeCount((int) $data['employee_count']);
        $branch->setNote($data['note']);
        $branch->setActive((bool) $data['active']);
    }

    private function storeLogo(Request $request, ServiceBranch $branch): void
    {
        $uploaded = $request->files->get('logo');
        if (!$uploaded instanceof UploadedFile || !$uploaded->isValid()) {
            return;
        }
        $stored = $this->files->store($uploaded, $this->auth->user());
        $branch->setLogoFileId($stored->id());
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
