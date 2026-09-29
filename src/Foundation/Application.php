<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use DI\Container;
use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\Resource\ResourceRegistrar;
use Gereq\Audit\Auditor;
use Gereq\Cache\CacheFactory;
use Gereq\Config\Repository;
use Gereq\Database\EntityManagerFactory;
use Gereq\Database\DatabaseBootstrap;
use Gereq\Debug\DebugBar;
use Gereq\Debug\QueryCollector;
use Gereq\Debug\Renderer;
use Gereq\File\FileManager;
use Gereq\Http\ExceptionHandler;
use Gereq\Http\Kernel;
use Gereq\Http\Router;
use Gereq\I18n\TranslatorFactory;
use Gereq\Api\ApiRegistry;
use Gereq\Hook\HookManager;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleManager;
use Gereq\Notifications\NotificationsRegistrar;
use Gereq\Queue\Queue;
use Gereq\Currency\CurrencyRegistrar;
use Gereq\Customers\CustomersRegistrar;
use Gereq\Media\MediaRegistrar;
use Gereq\Webhooks\WebhooksRegistrar;
use Gereq\Security\RateLimiter;
use Gereq\User\PermissionRegistry;
use Gereq\View\RenderEngine;
use Gereq\View\TwigFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class Application
{
    private function __construct(
        private readonly string $basePath,
        private readonly Container $container,
    ) {
        Gereq::set($this);
    }

    public static function create(string $basePath): self
    {
        $basePath = rtrim($basePath, '/');
        self::loadEnv($basePath);
        $config = self::loadConfig($basePath);

        $builder = new ContainerBuilder();
        $builder->useAutowiring(true);
        $builder->useAttributes(true);
        $builder->addDefinitions(self::definitions($basePath, $config));

        $container = $builder->build();
        $app = new self($basePath, $container);

        // Create missing server DB (if needed) + bare schema/admin before module boot.
        $container->get(DatabaseBootstrap::class)->ensureBareInstallIfEmpty();

        $container->get(CoreRegistrar::class)->register();
        $container->get(NotificationsRegistrar::class)->register();
        $container->get(WebhooksRegistrar::class)->register();
        $container->get(MediaRegistrar::class)->register();
        $container->get(CurrencyRegistrar::class)->register();
        $container->get(CustomersRegistrar::class)->register();
        if ((bool) $config->get('app.debug', false)) {
            $container->get(DebugBar::class);
        }
        $container->get(ModuleManager::class)->boot($container->get(ModuleContext::class));
        $container->get(ApiRegistry::class)->bind($container->get(Router::class));
        $container->get(ResourceRegistrar::class)->bindRoutes($container->get(Router::class));

        return $app;
    }

    public function basePath(string $path = ''): string
    {
        return $path === '' ? $this->basePath : $this->basePath . '/' . ltrim($path, '/');
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function make(string $id): mixed
    {
        return $this->container->get($id);
    }

    public function config(): Repository
    {
        return $this->container->get(Repository::class);
    }

    public function run(): void
    {
        $this->container->get(ExceptionHandler::class)->register();
        $request = Request::createFromGlobals();
        $response = $this->container->get(Kernel::class)->handle($request);
        $response->send();
    }

    public function runConsole(): int
    {
        return $this->container->get(\Gereq\Console\ConsoleKernel::class)->run();
    }

    private static function loadEnv(string $basePath): void
    {
        $file = $basePath . '/.env';
        if (is_file($file)) {
            $dotenv = \Dotenv\Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        }
    }

    private static function loadConfig(string $basePath): Repository
    {
        $items = [];
        foreach (glob($basePath . '/config/*.php') ?: [] as $file) {
            $items[basename($file, '.php')] = require $file;
        }

        return new Repository($items);
    }

    /**
     * Explicit bindings only — plain classes are resolved via autowiring.
     *
     * @return array<string, mixed>
     */
    private static function definitions(string $basePath, Repository $config): array
    {
        return [
            Repository::class => $config,
            'basePath' => $basePath,
            ContainerInterface::class => \DI\get(Container::class),

            // Interfaces / custom factories
            CacheItemPoolInterface::class => \DI\factory(static function (Repository $config) use ($basePath): CacheItemPoolInterface {
                return (new CacheFactory($config, $basePath))->create();
            }),
            EntityManagerInterface::class => \DI\factory(static function (
                Repository $config,
                QueryCollector $queries,
                CacheItemPoolInterface $cache,
            ) use ($basePath): EntityManagerInterface {
                return (new EntityManagerFactory($config, $basePath, $queries))->create($cache);
            }),
            MailerInterface::class => \DI\factory(static function (Repository $config): MailerInterface {
                return new Mailer(Transport::fromDsn((string) $config->get('mail.dsn', 'null://null')));
            }),
            TranslatorInterface::class => \DI\factory(static function (TranslatorFactory $factory): TranslatorInterface {
                return $factory->create();
            }),
            Environment::class => \DI\factory(static fn (TwigFactory $factory): Environment => $factory->create()),
            \Gereq\Currency\Application\RateProviderInterface::class => \DI\get(\Gereq\Currency\Infrastructure\CnbRateProvider::class),
            \Modules\Translate\Application\TranslateProviderInterface::class => \DI\get(\Modules\Translate\Application\MicrosoftTranslateProvider::class),

            // Scalars / config-driven construction
            RenderEngine::class => \DI\factory(static function (Repository $config, ModuleManager $modules) use ($basePath): RenderEngine {
                return new RenderEngine($basePath, $config, $modules);
            }),
            QueryCollector::class => \DI\factory(static function (Repository $config): QueryCollector {
                return new QueryCollector((int) $config->get('app.n_plus_one_threshold', 2));
            }),
            Queue::class => \DI\factory(static fn (Repository $config): Queue => new Queue($config, $basePath)),
            RateLimiter::class => \DI\factory(static fn (): RateLimiter => new RateLimiter($basePath . '/storage/cache/rate')),
            \Gereq\Search\SelfRepairSearch::class => \DI\factory(static function (Repository $config): \Gereq\Search\SelfRepairSearch {
                $normalizer = new \Gereq\Search\TextNormalizer();
                $expander = new \Gereq\Search\TypoExpander(
                    $normalizer,
                    (int) $config->get('search.typo_max_variants', 14),
                    (int) $config->get('search.min_token_length', 3),
                );

                return new \Gereq\Search\SelfRepairSearch($normalizer, $expander);
            }),
            FileManager::class => \DI\factory(static function (
                EntityManagerInterface $em,
                Repository $config,
                TranslatorInterface $translator,
            ) use ($basePath): FileManager {
                return new FileManager($em, $config, $basePath, $translator);
            }),
            ModuleManager::class => \DI\factory(static function (
                EntityManagerInterface $em,
                PermissionRegistry $permissions,
                Auditor $auditor,
                TranslatorInterface $translator,
                HookManager $hooks,
            ) use ($basePath): ModuleManager {
                return new ModuleManager($basePath, $em, $permissions, $auditor, $translator, $hooks);
            }),
            \Gereq\Module\ModuleVersionStore::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Module\ModuleVersionActivator::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            DatabaseBootstrap::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),

            // Autowire with scalar constructor params
            Renderer::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            DebugBar::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('auth', \DI\get(\Gereq\Auth\Authenticator::class))
                ->constructorParameter('staging', \DI\get(\Gereq\Publish\StagingStore::class))
                ->constructorParameter('contentVersion', \DI\get(\Gereq\Publish\ContentVersion::class)),
            TranslatorFactory::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            TwigFactory::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            \Gereq\Admin\Http\SettingsController::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Http\BrandAssetController::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Http\Session\SessionHandlerFactory::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            ExceptionHandler::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            \Gereq\Console\Command\InstallCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\CacheClearCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeModuleCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeResourceCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeDockerCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\NnCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\TestCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ImportSqliteCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\TwigTypesCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\EnvInitCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\EnvValidateCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Modules\Fleet\Application\VehicleCatalogClient::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Modules\Orders\Application\InvoiceIssuingService::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Modules\CustomerUploads\Application\CustomerUploadService::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Media\Http\MediaController::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Modules\Ai\Application\AiIntegrationGate::class => \DI\get(\Modules\Ai\Application\ModuleAiIntegrationGate::class),
        ];
    }
}
