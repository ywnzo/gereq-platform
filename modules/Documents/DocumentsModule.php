<?php

declare(strict_types=1);

namespace Modules\Documents;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Auth\Authenticator;
use Gereq\Event\EventDefinition;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Customers\Domain\Customer;
use Modules\Documents\Application\DocumentsProvider;
use Modules\Documents\Domain\Document;
use Modules\Documents\Domain\DocumentLink;
use Modules\Documents\Http\Api\DocumentApiController;
use Modules\Documents\Http\DocumentController;
use Twig\Environment;

final class DocumentsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'documents';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('documents.view', 'perm.documents.view', 'perm.group.documents');
        $context->permissions()->register('documents.manage', 'perm.documents.manage', 'perm.group.documents');

        $provider = new DocumentsProvider($context->get(EntityManagerInterface::class));
        $context->capabilities()->register('documents', $this->id(), $provider);

        $context->router()->group('/admin/documents', function (Router $routes): void {
            $routes->get('', [DocumentController::class, 'index'])
                ->name('admin.documents.index')
                ->permission('documents.view');
            $routes->get('/upload', [DocumentController::class, 'create'])
                ->name('admin.documents.upload')
                ->permission('documents.manage');
            $routes->post('', [DocumentController::class, 'store'])
                ->name('admin.documents.store')
                ->permission('documents.manage');
            $routes->get('/{id}', [DocumentController::class, 'show'])
                ->name('admin.documents.show')
                ->permission('documents.view');
            $routes->post('/{id}/link', [DocumentController::class, 'link'])
                ->name('admin.documents.link')
                ->permission('documents.manage');
            $routes->post('/{id}/delete', [DocumentController::class, 'destroy'])
                ->name('admin.documents.destroy')
                ->permission('documents.manage');
        }, ['middleware' => ['auth']]);

        $this->registerApi($context);
        $this->registerPlatformEvents($context);
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'documents',
            label: 'nav.documents',
            icon: 'file',
            route: 'admin.documents.index',
            permission: 'documents.view',
            order: 35,
            group: 'Hlavní',
        ));
        $context->menu()->add(new MenuItem(
            id: 'documents.upload',
            label: 'nav.documents.upload',
            route: 'admin.documents.upload',
            permission: 'documents.manage',
            parent: 'documents',
            order: 36,
            hidden: true,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'documents.index',
            label: 'palette.documents.index',
            url: '/admin/documents',
            icon: 'file',
            permission: 'documents.view',
            keywords: ['dokumenty', 'soubory', 'documents', 'files'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'documents.upload',
            label: 'palette.documents.upload',
            url: '/admin/documents/upload',
            icon: 'plus',
            permission: 'documents.manage',
            group: 'Akce',
        ));

        $this->registerValidation($context);
        $this->registerHooks($context);
    }

    private function registerApi(ModuleContext $context): void
    {
        $context->api()->registerKnownScope('documents.read');
        $context->api()->registerKnownScope('documents.write');

        $context->api()->module('documents', function (ApiModule $api): void {
            $api->get('/', [DocumentApiController::class, 'index'])->scope('documents.read');
            $api->post('/', [DocumentApiController::class, 'store'])->scope('documents.write');
            $api->get('/{id}', [DocumentApiController::class, 'show'])->scope('documents.read');
            $api->delete('/{id}', [DocumentApiController::class, 'destroy'])->scope('documents.write');
        });
    }

    private function registerPlatformEvents(ModuleContext $context): void
    {
        $events = [
            'document.uploaded' => 'Dokument nahrán',
            'document.linked' => 'Dokument propojen s entitou',
            'document.deleted' => 'Dokument smazán',
        ];

        foreach ($events as $type => $description) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))
                    ->description($description)
                    ->webhookEnabled(true)
            );
        }
    }

    private function registerValidation(ModuleContext $context): void
    {
        $validation = $context->validation();

        $validation->ruleset('documents.save', [
            'title' => 'required|min:1|max:190',
            'entity_type' => 'nullable|max:80',
            'entity_id' => 'nullable|max:36',
        ]);

        $validation->ruleset('documents.link', [
            'entity_type' => 'required|max:80',
            'entity_id' => 'required|max:36',
        ]);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);
        $twig = $context->get(Environment::class);
        $auth = $context->get(Authenticator::class);

        $context->hooks()->addFilter(
            'customers.show.sections',
            static function (array $sections, Customer $customer) use ($em, $twig, $auth): array {
                if ($auth->user() === null || !$auth->user()->can('documents.view')) {
                    return $sections;
                }

                $links = $em->getRepository(DocumentLink::class)->findBy(
                    ['entityType' => 'customer', 'entityId' => $customer->id()],
                    ['createdAt' => 'DESC'],
                    20,
                );

                $documents = [];
                foreach ($links as $link) {
                    /** @var DocumentLink $link */
                    $doc = $em->find(Document::class, $link->documentId());
                    if ($doc instanceof Document) {
                        $documents[] = $doc;
                    }
                }

                $sections[] = [
                    'id' => 'documents',
                    'label' => 'documents.partial.title',
                    'order' => 40,
                    'html' => $twig->render('@documents/partials/customer_documents.html.twig', [
                        'customer' => $customer,
                        'documents' => $documents,
                        'user' => $auth->user(),
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );

        $context->hooks()->addAction(
            'customers.deleting',
            static function (Customer $customer) use ($em): void {
                $links = $em->getRepository(DocumentLink::class)->findBy([
                    'entityType' => 'customer',
                    'entityId' => $customer->id(),
                ]);
                foreach ($links as $link) {
                    $em->remove($link);
                }
            },
            HookPriority::EARLY,
        );
    }
}
