<?php

declare(strict_types=1);

namespace Gereq\Customers;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Customers\Application\CustomerCounters;
use Gereq\Customers\Application\CustomersProvider;
use Gereq\Customers\Application\Widgets\CustomerCreateHeroWidget;
use Gereq\Customers\Application\Widgets\CustomerErasureWidget;
use Gereq\Customers\Application\Widgets\CustomerGrowthChartWidget;
use Gereq\Customers\Application\Widgets\CustomerStatsWidget;
use Gereq\Customers\Application\Widgets\CustomersShortcutWidget;
use Gereq\Customers\Domain\Customer;
use Gereq\Customers\Domain\CustomerAddress;
use Gereq\Customers\Domain\CustomerContact;
use Gereq\Customers\Domain\CustomerNote;
use Gereq\Customers\Domain\CustomerRelation;
use Gereq\Customers\Domain\CustomerTagLink;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Notifications\Application\NotifierBridge;
use Gereq\Publish\Adapter\CustomerPublishAdapter;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomersBootstrap implements ModuleInterface
{
    public function id(): string
    {
        return 'customers';
    }

    public function version(): string
    {
        return '1.99.2';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('customers.view', 'perm.customers.view', 'perm.group.customers');
        $context->permissions()->register('customers.create', 'perm.customers.create', 'perm.group.customers');
        $context->permissions()->register('customers.edit', 'perm.customers.edit', 'perm.group.customers');
        $context->permissions()->register('customers.delete', 'perm.customers.delete', 'perm.group.customers');

        $context->resources()->register(Customer::class);

        $provider = $context->get(CustomersProvider::class);
        $context->capabilities()->register('contacts', $this->id(), $provider);

        $context->publishing($this->id())
            ->category('customers')
            ->labelKey('publish.category.customers')
            ->sort(60)
            ->adapter(CustomerPublishAdapter::class);

        (new CustomersRoutesRegistrar($this->id()))->register($context);
        (new CustomersStoreAuthRegistrar($this->id()))->register($context);
    }

    public function boot(ModuleContext $context): void
    {
        $erasureCount = 0;
        try {
            $erasureCount = $context->get(CustomerCounters::class)->erasure();
        } catch (\Throwable) {
        }
        $erasureBadge = $erasureCount > 0 ? (string) $erasureCount : null;
        $context->menu()->add(new MenuItem(
            id: 'customers',
            label: 'nav.customers',
            icon: 'users',
            route: 'admin.customers.index',
            permission: 'customers.view',
            order: 20,
            group: 'Hlavní',
            badge: $erasureBadge,
        ));
        $context->menu()->add(new MenuItem(
            id: 'customers.create',
            label: 'nav.customers.create',
            route: 'admin.customers.create',
            permission: 'customers.create',
            parent: 'customers',
            order: 21,
            hidden: true,
        ));
        $context->menu()->add(new MenuItem(
            id: 'customers.erasure',
            label: 'nav.customers.erasure',
            url: '/admin/customers?erasure=1',
            permission: 'customers.view',
            parent: 'customers',
            order: 22,
            badge: $erasureBadge,
        ));

        $twig = $context->get(\Twig\Environment::class);
        $translator = $context->get(TranslatorInterface::class);
        $router = $context->get(\Gereq\Http\Router::class);
        $context->dashboard()->addWidget($context->get(CustomerStatsWidget::class));
        $context->dashboard()->addWidget($context->get(CustomerErasureWidget::class));
        $context->dashboard()->addWidget($context->get(CustomerGrowthChartWidget::class));
        $context->dashboard()->addWidget(new CustomerCreateHeroWidget($twig, $translator, $router));
        $context->dashboard()->addWidget(new CustomersShortcutWidget($twig, $translator, $router));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.index',
            label: 'palette.customers.index',
            url: '/admin/customers',
            icon: 'users',
            permission: 'customers.view',
            keywords: ['kontakty', 'firmy', 'customers'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.erasure',
            label: 'palette.customers.erasure',
            url: '/admin/customers?erasure=1',
            icon: 'users',
            permission: 'customers.view',
            keywords: ['gdpr', 'vymaz', 'smazani', 'erasure', 'soukromi'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'customers.create',
            label: 'palette.customers.create',
            url: '/admin/customers/create',
            icon: 'plus',
            permission: 'customers.create',
            group: 'Akce',
        ));

        $customers = $context->get(CustomersProvider::class);
        $context->adminSearch()->add(
            'customers',
            'customer.id, customer.name, customer.email, customer.company',
            static function (string $q, int $limit) use ($customers): array {
                $hits = [];
                foreach ($customers->search($q, $limit) as $contact) {
                    $hits[] = new \Gereq\Admin\Search\AdminSearchHit(
                        id: 'customer.' . $contact->id,
                        label: $contact->name,
                        url: '/admin/customers/' . $contact->id,
                        group: 'nav.customers',
                        subtitle: $contact->email ?: $contact->company,
                        icon: 'users',
                        keywords: array_values(array_filter([$contact->email, $contact->company])),
                    );
                }

                return $hits;
            },
            permission: 'customers.view',
            group: 'nav.customers',
            icon: 'users',
        );

        (new CustomersValidationRegistrar())->register($context);
        $this->registerHooks($context);
    }

    private function registerHooks(ModuleContext $context): void
    {
        $em = $context->get(EntityManagerInterface::class);

        $context->hooks()->addAction(
            'customers.deleting',
            static function (Customer $customer) use ($em): void {
                $id = $customer->id();
                foreach ($em->getRepository(CustomerAddress::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                foreach ($em->getRepository(CustomerContact::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                foreach ($em->getRepository(CustomerNote::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                foreach ($em->getRepository(CustomerTagLink::class)->findBy(['customerId' => $id]) as $row) {
                    $em->remove($row);
                }
                $relations = $em->getRepository(CustomerRelation::class)->createQueryBuilder('r')
                    ->where('r.fromCustomerId = :id OR r.toCustomerId = :id')
                    ->setParameter('id', $id)
                    ->getQuery()
                    ->getResult();
                foreach ($relations as $row) {
                    $em->remove($row);
                }
            },
            HookPriority::EARLY,
        );

        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (mixed $envelope) use ($context): void {
                if (!$envelope instanceof EventEnvelope || $envelope->type !== 'customer.erasure_requested') {
                    return;
                }
                $id = (string) ($envelope->resource['id'] ?? $envelope->data['customer_id'] ?? '');
                $email = (string) ($envelope->data['email'] ?? '');
                $name = (string) ($envelope->data['name'] ?? '');
                $who = $name !== '' ? $name . ($email !== '' ? ' (' . $email . ')' : '') : $email;
                $translator = $context->get(TranslatorInterface::class);
                $context->get(NotifierBridge::class)->notifyAdmins(
                    'customer.erasure_requested',
                    $translator->trans('customers.erasure.notify.title'),
                    $translator->trans('customers.erasure.notify.body', ['%who%' => $who !== '' ? $who : $id]),
                    [
                        'resource' => $envelope->resource,
                        'event_id' => $envelope->id,
                        'url' => $id !== '' ? '/admin/customers/' . $id : '/admin/customers?erasure=1',
                    ],
                );
            },
            HookPriority::NORMAL,
        );
    }
}
