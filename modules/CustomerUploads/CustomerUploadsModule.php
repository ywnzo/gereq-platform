<?php

declare(strict_types=1);

namespace Modules\CustomerUploads;

use Gereq\Http\Router;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleInterface;
use Gereq\Setting\SettingRecord;
use Modules\CustomerUploads\Application\CustomerUploadService;
use Modules\CustomerUploads\Http\Api\UploadApiController;
use Modules\CustomerUploads\Http\Public\PublicStoreUploadController;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Doctrine\ORM\EntityManagerInterface;
use Modules\CustomerUploads\Application\Widgets\CustomerUploadsStatsWidget;

final class CustomerUploadsModule implements ModuleInterface
{
    public function id(): string
    {
        return 'customer_uploads';
    }

    public function version(): string
    {
        return '1.1.0';
    }

    public function register(ModuleContext $context): void
    {
        $context->permissions()->register('uploads.view', 'perm.uploads.view', 'perm.group.uploads');
        $context->permissions()->register('uploads.manage', 'perm.uploads.manage', 'perm.group.uploads');

        $context->capabilities()->register(
            'customer_uploads',
            $this->id(),
            $context->get(CustomerUploadService::class),
        );

        $context->settings()->define('uploads.max_bytes', SettingRecord::TYPE_INTEGER, 'uploads.settings.max_bytes', 26214400, $this->id());
        $context->settings()->define('uploads.session_ttl_seconds', SettingRecord::TYPE_INTEGER, 'uploads.settings.session_ttl', 86400, $this->id());
        $context->settings()->define('uploads.retention_days', SettingRecord::TYPE_INTEGER, 'uploads.settings.retention_days', 7, $this->id());

        $context->router()->group('/api/v1/uploads', function (Router $routes): void {
            $routes->get('/session', [UploadApiController::class, 'session'])->name('api.uploads.session');
            $routes->post('/files', [UploadApiController::class, 'store'])->name('api.uploads.store');
            $routes->post('/consent', [UploadApiController::class, 'consent'])->name('api.uploads.consent');
            $routes->post('/link', [UploadApiController::class, 'link'])->name('api.uploads.link');
            $routes->get('/files/{id}', [UploadApiController::class, 'file'])->name('api.uploads.file');
        });

        $public = [PublicStoreUploadController::class, 'options'];
        $context->router()->add('/api/public/store/uploads/session', ['OPTIONS'], $public)->name('api.public.store.uploads.session.options');
        $context->router()->add('/api/public/store/uploads/files', ['OPTIONS'], $public)->name('api.public.store.uploads.files.options');
        $context->router()->add('/api/public/store/uploads/files/{id}', ['OPTIONS'], $public)->name('api.public.store.uploads.file.options');
        $context->router()->get('/api/public/store/uploads/session', [PublicStoreUploadController::class, 'session'])->name('api.public.store.uploads.session');
        $context->router()->post('/api/public/store/uploads/files', [PublicStoreUploadController::class, 'store'])->name('api.public.store.uploads.store');
        $context->router()->get('/api/public/store/uploads/files/{id}', [PublicStoreUploadController::class, 'file'])->name('api.public.store.uploads.file');
    }

    public function boot(ModuleContext $context): void
    {
        $context->dashboard()->addWidget(new CustomerUploadsStatsWidget(
            $context->get(Environment::class),
            $context->get(EntityManagerInterface::class),
            $context->get(TranslatorInterface::class),
        ));
    }
}
