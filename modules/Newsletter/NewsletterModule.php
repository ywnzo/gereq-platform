<?php

declare(strict_types=1);

namespace Modules\Newsletter;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\PaletteCommand;
use Gereq\Admin\Menu\MenuItem;
use Gereq\Api\ApiModule;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Modules\Newsletter\Application\NewsletterProvider;
use Modules\Newsletter\Http\Api\NewsletterApiController;
use Modules\Newsletter\Http\CampaignController;
use Modules\Newsletter\Http\SubscriberController;
use Modules\Newsletter\Application\Widgets\NewsletterShortcutWidget;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class NewsletterModule implements ModuleInterface
{
    public function id(): string
    {
        return 'newsletter';
    }

    public function version(): string
    {
        return '1.0.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('newsletter.view', 'perm.newsletter.view', 'perm.group.newsletter');
        $context->permissions()->register('newsletter.manage', 'perm.newsletter.manage', 'perm.group.newsletter');
        $context->capabilities()->register('newsletter', $this->id(), new NewsletterProvider($context->get(EntityManagerInterface::class)));

        $context->router()->group('/admin/newsletter/subscribers', function (Router $routes): void {
            $routes->get('', [SubscriberController::class, 'index'])->name('admin.newsletter.subscribers.index')->permission('newsletter.view');
            $routes->get('/create', [SubscriberController::class, 'create'])->name('admin.newsletter.subscribers.create')->permission('newsletter.manage');
            $routes->post('', [SubscriberController::class, 'store'])->name('admin.newsletter.subscribers.store')->permission('newsletter.manage');
            $routes->post('/{id}/unsubscribe', [SubscriberController::class, 'unsubscribe'])->name('admin.newsletter.subscribers.unsubscribe')->permission('newsletter.manage');
            $routes->post('/{id}/delete', [SubscriberController::class, 'destroy'])->name('admin.newsletter.subscribers.destroy')->permission('newsletter.manage');
        }, ['middleware' => ['auth']]);

        $context->router()->group('/admin/newsletter/campaigns', function (Router $routes): void {
            $routes->get('', [CampaignController::class, 'index'])->name('admin.newsletter.campaigns.index')->permission('newsletter.view');
            $routes->get('/create', [CampaignController::class, 'create'])->name('admin.newsletter.campaigns.create')->permission('newsletter.manage');
            $routes->post('', [CampaignController::class, 'store'])->name('admin.newsletter.campaigns.store')->permission('newsletter.manage');
            $routes->get('/{id}/edit', [CampaignController::class, 'edit'])->name('admin.newsletter.campaigns.edit')->permission('newsletter.manage');
            $routes->post('/{id}', [CampaignController::class, 'update'])->name('admin.newsletter.campaigns.update')->permission('newsletter.manage');
            $routes->post('/{id}/send', [CampaignController::class, 'markSent'])->name('admin.newsletter.campaigns.send')->permission('newsletter.manage');
            $routes->post('/{id}/delete', [CampaignController::class, 'destroy'])->name('admin.newsletter.campaigns.destroy')->permission('newsletter.manage');
        }, ['middleware' => ['auth']]);

        $context->api()->registerKnownScope('newsletter.read');
        $context->api()->registerKnownScope('newsletter.write');
        $context->api()->module('newsletter', function (ApiModule $api): void {
            $api->post('/subscribe', [NewsletterApiController::class, 'subscribe'])->scope('newsletter.write');
            $api->post('/unsubscribe', [NewsletterApiController::class, 'unsubscribe'])->scope('newsletter.write');
        });

        foreach ([
            'newsletter.subscribed' => 'Nový odběratel newsletteru',
            'newsletter.unsubscribed' => 'Odhlášení z newsletteru',
            'newsletter.campaign_created' => 'Kampaň vytvořena',
            'newsletter.campaign_sent' => 'Kampaň označena jako odeslaná',
        ] as $type => $desc) {
            $context->platformEvents()->register(
                (new EventDefinition($type, $this->id()))->description($desc)->webhookEnabled(true)
            );
        }

        $v = $context->validation();
        $v->ruleset('newsletter.subscriber.save', [
            'email' => 'required|email|max:190',
            'name' => 'nullable|max:190',
            'source' => 'nullable|max:64',
        ]);
        $context->forms()->entity('newsletter.subscriber.save', \Modules\Newsletter\Domain\Subscriber::class);
        $v->ruleset('newsletter.subscribe', [
            'email' => 'required|email|max:190',
            'name' => 'nullable|max:190',
            'source' => 'nullable|max:64',
        ]);
        $v->ruleset('newsletter.unsubscribe', [
            'email' => 'required|email|max:190',
        ]);
        $v->ruleset('newsletter.campaign.save', [
            'subject' => 'required|min:1|max:190',
            'body_html' => 'required|min:1|max:500000',
        ]);
        $context->forms()->entity('newsletter.campaign.save', \Modules\Newsletter\Domain\Campaign::class);
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new NewsletterShortcutWidget(
            $context->get(Environment::class),
            $context->get(TranslatorInterface::class),
            $context->get(Router::class),
        ));
        $context->menu()->add(new MenuItem(
            id: 'newsletter',
            label: 'nav.newsletter',
            icon: 'mail',
            route: 'admin.newsletter.subscribers.index',
            permission: 'newsletter.view',
            order: 55,
            group: 'Marketing',
        ));
        $context->menu()->add(new MenuItem(
            id: 'newsletter.subscribers',
            label: 'nav.newsletter.subscribers',
            route: 'admin.newsletter.subscribers.index',
            permission: 'newsletter.view',
            parent: 'newsletter',
            order: 56,
        ));
        $context->menu()->add(new MenuItem(
            id: 'newsletter.campaigns',
            label: 'nav.newsletter.campaigns',
            route: 'admin.newsletter.campaigns.index',
            permission: 'newsletter.view',
            parent: 'newsletter',
            order: 57,
        ));

        $context->commandPalette()->add(new PaletteCommand(
            id: 'newsletter.subscribers',
            label: 'palette.newsletter.subscribers',
            url: '/admin/newsletter/subscribers',
            icon: 'mail',
            permission: 'newsletter.view',
            keywords: ['newsletter', 'odběratel', 'subscriber', 'email'],
        ));
        $context->commandPalette()->add(new PaletteCommand(
            id: 'newsletter.campaigns',
            label: 'palette.newsletter.campaigns',
            url: '/admin/newsletter/campaigns',
            icon: 'mail',
            permission: 'newsletter.view',
            keywords: ['kampaň', 'campaign', 'newsletter'],
        ));
    }
}
