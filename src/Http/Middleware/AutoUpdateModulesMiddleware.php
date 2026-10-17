<?php

declare(strict_types=1);

namespace Gereq\Http\Middleware;

use Gereq\Auth\Authenticator;
use Gereq\Config\Repository;
use Gereq\Module\ModuleManager;
use Gereq\Module\ModuleUpdater;
use Gereq\Setting\Settings;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AutoUpdateModulesMiddleware implements MiddlewareInterface
{
    public const SETTING_ENABLED = 'admin.modules.auto_update';

    public function __construct(
        private readonly Settings $settings,
        private readonly Repository $config,
        private readonly Authenticator $auth,
        private readonly ModuleManager $modules,
        private readonly ModuleUpdater $updater,
        private readonly string $basePath,
    ) {
    }

    public function process(Request $request, callable $next): Response
    {
        // Env first — short-circuit before Settings::getLive (avoids warming settings in prod).
        $environment = strtolower(trim((string) $this->config->get('app.env', 'production')));
        if (!in_array($environment, ['local', 'dev', 'development', 'testing'], true)) {
            return $next($request);
        }
        if (!$this->settings->getLive(self::SETTING_ENABLED, false)
            || !$this->auth->user()?->can('modules.manage')
        ) {
            return $next($request);
        }

        $lock = fopen($this->basePath . '/storage/framework/modules-auto-update.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open the automatic module update lock.');
        }

        try {
            if (flock($lock, LOCK_EX | LOCK_NB)) {
                try {
                    foreach ($this->modules->sortForEnable($this->modules->enabledIds()) as $id) {
                        if ($this->updater->isUpdateAvailable($id)) {
                            $this->updater->update($id);
                        }
                    }
                } finally {
                    flock($lock, LOCK_UN);
                }
            }
        } finally {
            fclose($lock);
        }

        return $next($request);
    }
}
