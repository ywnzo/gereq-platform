<?php

declare(strict_types=1);

namespace Modules\Authorization;

use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Auth\Authenticator;
use Gereq\Customers\Domain\Customer;
use Gereq\Event\EventEnvelope;
use Gereq\Hook\HookPriority;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Authorization\Application\OAuthProviderRegistry;
use Modules\Authorization\Application\StoreOAuthService;
use Modules\Authorization\Http\AuthorizationAdminController;
use Modules\Authorization\Http\Public\PublicOAuthController;
use Modules\Authorization\Http\StoreOAuthController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AuthorizationModule implements ModuleInterface
{
    public function id(): string
    {
        return 'authorization';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('authorization.view', 'perm.authorization.view', 'perm.group.authorization');
        $context->permissions()->register('authorization.manage', 'perm.authorization.manage', 'perm.group.authorization');

        $registry = $context->get(OAuthProviderRegistry::class);
        $context->capabilities()->register('oauth', $this->id(), $registry);

        $context->router()->group('/admin/authorization', function (Router $routes): void {
            $routes->get('', [AuthorizationAdminController::class, 'index'])
                ->name('admin.authorization.index')
                ->permission('authorization.view');
        }, ['middleware' => ['auth']]);

        $oauth = [PublicOAuthController::class, 'options'];
        $context->router()->add('/api/public/auth/providers', ['OPTIONS'], $oauth)->name('api.public.auth.providers.options');
        $context->router()->add('/api/public/auth/{provider}', ['OPTIONS'], $oauth)->name('api.public.auth.start.options');
        $context->router()->add('/api/public/auth/{provider}/callback', ['OPTIONS'], $oauth)->name('api.public.auth.callback.options');
        $context->router()->get('/api/public/auth/providers', [PublicOAuthController::class, 'providers'])
            ->name('api.public.auth.providers');
        $context->router()->get('/api/public/auth/{provider}', [PublicOAuthController::class, 'start'])
            ->name('api.public.auth.start');
        $context->router()->get('/api/public/auth/{provider}/callback', [PublicOAuthController::class, 'callback'])
            ->name('api.public.auth.callback');
        $context->router()->get('/api/v1/store/auth/oauth', [StoreOAuthController::class, 'index'])
            ->name('api.store.auth.oauth');
        $context->router()->delete('/api/v1/store/auth/oauth/{provider}', [StoreOAuthController::class, 'destroy'])
            ->name('api.store.auth.oauth.destroy');
    }

    public function boot(ModuleContext $context): void
    {
        $context->menu()->add(new MenuItem(
            id: 'authorization',
            label: 'nav.authorization',
            icon: 'verified_user',
            route: 'admin.authorization.index',
            permission: 'authorization.view',
            order: 72,
            group: 'Systém',
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'authorization',
            label: 'palette.authorization',
            url: '/admin/authorization',
            icon: 'verified_user',
            permission: 'authorization.view',
            keywords: ['oauth', 'google', 'facebook', 'github', 'login', 'autorizace'],
        ));

        $accounts = $context->get(StoreOAuthService::class);
        $twig = $context->get(Environment::class);
        $translator = $context->get(TranslatorInterface::class);
        $auth = $context->get(Authenticator::class);
        $context->hooks()->addFilter(
            'customers.show.sections',
            static function (array $sections, mixed $customer) use ($accounts, $twig, $translator, $auth): array {
                $user = $auth->user();
                if ($user === null || !$user->can('customers.view') || !$customer instanceof Customer) {
                    return $sections;
                }
                $identities = [];
                $chips = '';
                foreach ($accounts->identities($customer->id()) as $row) {
                    $label = ucfirst($row->provider());
                    $identities[] = [
                        'provider' => $row->provider(),
                        'email' => $row->email(),
                    ];
                    $chips .= '<span class="badge badge--ok">OAuth · ' . htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
                }
                $facts = '<div><dt>' . htmlspecialchars($translator->trans('authorization.customer.tab'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    . '</dt><dd>'
                    . ($identities === []
                        ? htmlspecialchars($translator->trans('authorization.customer.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        : implode(', ', array_map(static fn (array $row): string => htmlspecialchars(ucfirst($row['provider']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $identities)))
                    . '</dd></div>';
                $sections[] = [
                    'id' => 'oauth',
                    'label' => 'authorization.customer.tab',
                    'order' => 18,
                    'overview_html' => $chips,
                    'facts_html' => $facts,
                    'html' => $twig->render('@authorization/partials/customer_oauth.html.twig', [
                        'identities' => $identities,
                    ]),
                ];

                return $sections;
            },
            HookPriority::NORMAL,
        );
        $context->hooks()->addAction(
            'platform.event.dispatched',
            static function (mixed $envelope) use ($accounts): void {
                if (!$envelope instanceof EventEnvelope || $envelope->type !== 'customer.store_deleted') {
                    return;
                }
                $customerId = (string) ($envelope->data['customer_id'] ?? '');
                if ($customerId !== '') {
                    $accounts->purgeCustomer($customerId);
                }
            },
            HookPriority::NORMAL,
        );
    }
}
