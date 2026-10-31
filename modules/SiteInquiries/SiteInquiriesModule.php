<?php

declare(strict_types=1);

namespace Modules\SiteInquiries;

use Gereq\Admin\Menu\MenuItem;
use Gereq\Event\EventDefinition;
use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\SiteInquiries\Http\InquiryController;
use Modules\SiteInquiries\Http\Public\PublicInquiryController;

final class SiteInquiriesModule implements ModuleInterface
{
    public function id(): string
    {
        return 'site_inquiries';
    }

    public function version(): string
    {
        return '0.11.1';
    }

    public function register(ModuleContext $context): void
    {
        $context->dashboard()->addWidget($context->get(\Modules\SiteInquiries\Application\Widgets\InquiryCountWidget::class));
        $context->dashboard()->addWidget($context->get(\Modules\SiteInquiries\Application\Widgets\RecentInquiriesWidget::class));
        $context->publishing($this->id())
            ->category('site_inquiries.contact')
            ->labelKey('site_inquiries.settings.title')
            ->sort(45)
            ->adapter(\Modules\SiteInquiries\Publish\ContactPublishAdapter::class);

        $context->permissions()->register(
            'site_inquiries.view',
            'perm.site_inquiries.view',
            'perm.group.site_inquiries',
        );
        $context->permissions()->register(
            'site_inquiries.manage',
            'perm.site_inquiries.manage',
            'perm.group.site_inquiries',
        );

        foreach ([
            'site_inquiries.contact_phone' => ['', 'site_inquiries.settings.contact_phone'],
            'site_inquiries.contact_phone_tel' => ['', 'site_inquiries.settings.contact_phone_tel'],
            'site_inquiries.contact_email' => ['', 'site_inquiries.settings.contact_email'],
            'site_inquiries.contact_person' => ['', 'site_inquiries.settings.contact_person'],
            'site_inquiries.contact_ico' => ['', 'site_inquiries.settings.contact_ico'],
            'site_inquiries.contact_dic' => ['', 'site_inquiries.settings.contact_dic'],
            'site_inquiries.contact_address' => [
                '',
                'site_inquiries.settings.contact_address',
            ],
            'site_inquiries.tagline' => [
                '',
                'site_inquiries.settings.tagline',
            ],
            'site_inquiries.notify_email' => [
                '',
                'site_inquiries.settings.notify_email',
            ],
        ] as $key => [$default, $label]) {
            $context->settings()->define(
                $key,
                SettingRecord::TYPE_STRING,
                $label,
                $default,
                $this->id(),
                [],
                false,
            );
        }

        $context->router()->group('/admin/site-inquiries', function (Router $routes): void {
            $routes->get('', [InquiryController::class, 'index'])
                ->name('admin.site_inquiries.index')
                ->permission('site_inquiries.view');
            $routes->post('/bulk', [InquiryController::class, 'bulk'])
                ->name('admin.site_inquiries.bulk')
                ->permission('site_inquiries.manage');
            $routes->get('/settings', [InquiryController::class, 'settings'])
                ->name('admin.site_inquiries.settings')
                ->permission('site_inquiries.manage');
            $routes->post('/settings', [InquiryController::class, 'updateSettings'])
                ->name('admin.site_inquiries.settings.update')
                ->permission('site_inquiries.manage');
            $routes->post('/{id}/notification/retry', [InquiryController::class, 'retryNotification'])->name('admin.site_inquiries.notification.retry')->permission('site_inquiries.manage');
            $routes->get('/{id}', [InquiryController::class, 'show'])->name('admin.site_inquiries.show')->permission('site_inquiries.view');
            $routes->post('/{id}/resolve', [InquiryController::class, 'resolve'])
                ->name('admin.site_inquiries.resolve')
                ->permission('site_inquiries.manage');
            $routes->post('/{id}/delete', [InquiryController::class, 'destroy'])
                ->name('admin.site_inquiries.destroy')
                ->permission('site_inquiries.manage');
        }, ['middleware' => ['auth'], 'module' => 'site_inquiries']);

        $context->validation()->ruleset('site_inquiries.bulk', [
            'action' => 'required|in:resolve,reopen,delete',
            'confirmed' => 'nullable',
        ]);

        $options = [PublicInquiryController::class, 'options'];
        $context->router()->add('/api/public/site', ['OPTIONS'], $options)->name('api.public.site.options');
        $context->router()->add('/api/public/site/inquiries', ['OPTIONS'], $options)
            ->name('api.public.site.inquiries.options');
        $context->router()->get('/api/public/site', [PublicInquiryController::class, 'site'])
            ->name('api.public.site');
        $context->router()->post('/api/public/site/inquiries', [PublicInquiryController::class, 'store'])
            ->name('api.public.site.inquiries.store');

        $context->platformEvents()->register(
            (new EventDefinition('site_inquiry.created', $this->id()))
                ->description('Poptávka z veřejného webu')
                ->webhookEnabled(true),
        );

        $context->validation()->ruleset('site_inquiries.public', [
            'name' => 'required|min:2|max:180',
            'email' => 'required|email|max:180',
            'phone' => 'required|max:40',
            'details' => 'required|min:5|max:5000',
            'date' => 'nullable|max:32',
            'from' => 'nullable|max:190',
            'to' => 'nullable|max:190',
            'website_url' => 'nullable|max:190',
        ]);
    }

    public function boot(ModuleContext $context): void
    {
        $context->hooks()->addFilter('admin.layout.end', static function (string $html) use ($context): string {
            if (!$context->get(\Gereq\Auth\Authenticator::class)->user()?->can('site_inquiries.view')) {
                return $html;
            }
            return $html . $context->get(\Twig\Environment::class)->render('@siteinquiries/_modal.html.twig');
        });
        $context->schedule()->call(static fn () => $context->get(\Modules\SiteInquiries\Application\InquiryNotificationOutbox::class)->process())
            ->everyMinute()
            ->name('site_inquiries.notifications')
            ->description('Deliver pending inquiry notification emails with bounded retries.')
            ->withoutOverlapping(15);
        $context->get(\Doctrine\ORM\EntityManagerInterface::class)->getEventManager()->addEventListener(
            ['postRemove'],
            new \Modules\SiteInquiries\Application\InquiryAttachmentCleanup($context->cms()),
        );
        $context->cms()->registerFormAction(
            'site_inquiries.create',
            'site_inquiries.form_action',
            'site_inquiries.public',
            ['name' => 'text', 'email' => 'email', 'phone' => 'tel', 'details' => 'textarea'],
            static fn (array $values): array => $context->get(\Modules\SiteInquiries\Application\InquiryIntake::class)->handle(
                $values['name'],
                $values['email'],
                $values['phone'],
                \Modules\SiteInquiries\Application\InquiryIntake::formDetails($values),
                ($values['date'] ?? '') !== '' ? $values['date'] : null,
                ($values['from'] ?? '') !== '' ? $values['from'] : null,
                ($values['to'] ?? '') !== '' ? $values['to'] : null,
                notify: false,
            ),
            $context->get(\Modules\SiteInquiries\Application\InquiryFormOperations::class),
            static fn (array $result): string => $context->get(\Modules\SiteInquiries\Application\InquiryIntake::class)->complete((string) $result['id']),
        );

        $context->menu()->add(new MenuItem(
            id: 'site_inquiries',
            label: 'nav.site_inquiries',
            icon: 'inbox',
            route: 'admin.site_inquiries.index',
            permission: 'site_inquiries.view',
            order: 45,
            group: 'Obsah',
        ));
        $context->menu()->add(new MenuItem(
            id: 'site_inquiries.settings',
            label: 'nav.site_inquiries.settings',
            icon: 'settings',
            route: 'admin.site_inquiries.settings',
            permission: 'site_inquiries.manage',
            parent: 'site_inquiries',
            order: 20,
            group: 'Obsah',
        ));
    }
}
