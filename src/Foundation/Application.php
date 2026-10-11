<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use DI\Container;
use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Dotenv\Dotenv;
use Gereq\Admin\Resource\ResourceRegistrar;
use Gereq\Api\ApiRegistry;
use Gereq\Audit\Auditor;
use Gereq\Cache\CacheFactory;
use Gereq\Config\Repository;
use Gereq\Currency\CurrencyRegistrar;
use Gereq\Customers\Application\CustomerScope;
use Gereq\Customers\Application\UnrestrictedCustomerScope;
use Gereq\Customers\CustomersRegistrar;
use Gereq\Database\DatabaseBootstrap;
use Gereq\Database\EntityManagerFactory;
use Gereq\Debug\DebugBar;
use Gereq\Debug\QueryCollector;
use Gereq\Debug\Renderer;
use Gereq\Env\CliEnvFile;
use Gereq\File\FileManager;
use Gereq\Hook\HookManager;
use Gereq\Http\ExceptionHandler;
use Gereq\Http\Kernel;
use Gereq\Http\Router;
use Gereq\I18n\TranslatorFactory;
use Gereq\Media\Http\MediaFilePath;
use Gereq\Media\MediaRegistrar;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleManager;
use Gereq\Notifications\NotificationsRegistrar;
use Gereq\Queue\FailedJobStore;
use Gereq\Queue\Queue;
use Gereq\Queue\QueueRegistrar;
use Gereq\Schedule\Schedule;
use Gereq\Schedule\ScheduleLock;
use Gereq\Schedule\ScheduleRegistrar;
use Gereq\Security\RateLimiter;
use Gereq\User\PermissionRegistry;
use Gereq\View\RenderEngine;
use Gereq\View\TwigFactory;
use Gereq\Webhooks\WebhooksRegistrar;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use function DI\autowire;
use function DI\factory;

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
        $container->get(QueueRegistrar::class)->register();
        $container->get(ScheduleRegistrar::class)->register();
        $container->get(CustomersRegistrar::class)->register();
        $mediaFile = MediaFilePath::fromGlobals();
        if ((bool) $config->get('app.debug', false) && !$mediaFile) {
            $container->get(DebugBar::class);
        }
        // Thumbnails hit /media/{id}/file (and admin equivalent) in parallel; module seed/boot
        // must not run on those requests or PHP-FPM saturates and the grid stays blank.
        if (!$mediaFile) {
            $container->get(ModuleManager::class)->boot($container->get(ModuleContext::class));
            $container->get(ApiRegistry::class)->bind($container->get(Router::class));
            $container->get(ResourceRegistrar::class)->bindRoutes($container->get(Router::class));
        }

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
        $file = CliEnvFile::resolve($basePath);
        if (is_file($file)) {
            $dotenv = Dotenv::createImmutable(dirname($file), basename($file));
            $dotenv->safeLoad();
        }
        if (\PHP_SAPI === 'cli' || \PHP_SAPI === 'phpdbg') {
            $overlay = CliEnvFile::cliOverlayPath($basePath);
            if ($overlay !== null) {
                $dotenv = Dotenv::createMutable(dirname($overlay), basename($overlay));
                $dotenv->load();
            }
        }
        self::hardenProductionEnv();
    }

    /** Never run with APP_DEBUG in production — forces debug off even if .env is wrong. */
    private static function hardenProductionEnv(): void
    {
        $env = strtolower(trim((string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? getenv('APP_ENV') ?: 'production')));
        if (!in_array($env, ['production', 'prod'], true)) {
            return;
        }

        $raw = $_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? getenv('APP_DEBUG');
        $debug = filter_var($raw === false || $raw === null ? false : $raw, FILTER_VALIDATE_BOOLEAN);
        if (!$debug) {
            return;
        }

        $_ENV['APP_DEBUG'] = '0';
        $_SERVER['APP_DEBUG'] = '0';
        putenv('APP_DEBUG=0');
        error_log('GEREQ: APP_DEBUG was enabled while APP_ENV=' . $env . '; forced off.');
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
            CacheFactory::class => autowire()->constructorParameter('basePath', $basePath),
            CacheItemPoolInterface::class => factory(static function (Repository $config) use ($basePath): CacheItemPoolInterface {
                return (new CacheFactory($config, $basePath))->create();
            }),
            EntityManagerInterface::class => factory(static function (
                Repository $config,
                QueryCollector $queries,
                CacheItemPoolInterface $cache,
            ) use ($basePath): EntityManagerInterface {
                return (new EntityManagerFactory($config, $basePath, $queries))->create($cache);
            }),
            MailerInterface::class => factory(static function (Repository $config): MailerInterface {
                return new Mailer(Transport::fromDsn((string) $config->get('mail.dsn', 'null://null')));
            }),
            TranslatorInterface::class => factory(static function (TranslatorFactory $factory): TranslatorInterface {
                return $factory->create();
            }),
            Environment::class => factory(static fn (TwigFactory $factory): Environment => $factory->create()),
            \Gereq\Currency\Application\RateProviderInterface::class => \DI\get(\Gereq\Currency\Infrastructure\CnbRateProvider::class),
            \Modules\Translate\Application\TranslateProviderInterface::class => \DI\get(\Modules\Translate\Application\MicrosoftTranslateProvider::class),

            // Scalars / config-driven construction
            RenderEngine::class => factory(static function (Repository $config, ModuleManager $modules) use ($basePath): RenderEngine {
                return new RenderEngine($basePath, $config, $modules);
            }),
            QueryCollector::class => factory(static function (Repository $config): QueryCollector {
                return new QueryCollector((int) $config->get('app.n_plus_one_threshold', 2));
            }),
            FailedJobStore::class => autowire(),
            \Gereq\Queue\JobRunStore::class => autowire(),
            Queue::class => factory(static function (
                Repository     $config,
                FailedJobStore $failed,
                \Gereq\Queue\JobRunStore $runs,
            ) use ($basePath): Queue {
                return new Queue($config, $basePath, $failed, $runs);
            }),
            Schedule::class => autowire(),
            ScheduleLock::class => factory(static fn (): ScheduleLock => new ScheduleLock($basePath)),
            \Gereq\Schedule\ScheduleState::class => factory(
                static fn (): \Gereq\Schedule\ScheduleState => new \Gereq\Schedule\ScheduleState($basePath),
            ),
            RateLimiter::class => factory(static fn (): RateLimiter => new RateLimiter($basePath . '/storage/cache/rate')),
            \Gereq\Search\SelfRepairSearch::class => factory(static function (Repository $config): \Gereq\Search\SelfRepairSearch {
                $normalizer = new \Gereq\Search\TextNormalizer();
                $expander = new \Gereq\Search\TypoExpander(
                    $normalizer,
                    (int) $config->get('search.typo_max_variants', 14),
                    (int) $config->get('search.min_token_length', 3),
                );

                return new \Gereq\Search\SelfRepairSearch($normalizer, $expander);
            }),
            FileManager::class => factory(static function (
                EntityManagerInterface $em,
                Repository $config,
                TranslatorInterface $translator,
            ) use ($basePath): FileManager {
                return new FileManager($em, $config, $basePath, $translator);
            }),
            ModuleManager::class => factory(static function (
                EntityManagerInterface $em,
                PermissionRegistry $permissions,
                Auditor $auditor,
                TranslatorInterface $translator,
                HookManager $hooks,
            ) use ($basePath): ModuleManager {
                return new ModuleManager($basePath, $em, $permissions, $auditor, $translator, $hooks);
            }),
            \Gereq\Module\ModuleVersionStore::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Module\ModuleVersionActivator::class => autowire()
                ->constructorParameter('basePath', $basePath),
            DatabaseBootstrap::class => autowire()
                ->constructorParameter('basePath', $basePath),

            // Autowire with scalar constructor params
            Renderer::class => autowire()->constructorParameter('basePath', $basePath),
            DebugBar::class => autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('auth', \DI\get(\Gereq\Auth\Authenticator::class))
                ->constructorParameter('staging', \DI\get(\Gereq\Publish\StagingStore::class))
                ->constructorParameter('contentVersion', \DI\get(\Gereq\Publish\ContentVersion::class)),
            TranslatorFactory::class => autowire()->constructorParameter('basePath', $basePath),
            TwigFactory::class => autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            \Gereq\Http\StorefrontRebuild::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Admin\Http\SettingsController::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Http\BrandAssetController::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Media\Application\MediaFileDelivery::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Brand\AdminFavicon::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Http\Session\SessionHandlerFactory::class => autowire()->constructorParameter('basePath', $basePath),
            ExceptionHandler::class => autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            \Gereq\Console\Command\InstallCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\CacheClearCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeModuleCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeResourceCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeDockerCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\NnCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\TestCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ImportSqliteCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\TwigTypesCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\EnvInitCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\EnvValidateCommand::class => autowire()->constructorParameter('basePath', $basePath),
            \Modules\Fleet\Application\VehicleCatalogClient::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Modules\Orders\Application\InvoiceIssuingService::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Modules\CustomerUploads\Application\CustomerUploadService::class => autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Media\Http\MediaController::class => autowire()->constructorParameter('basePath', $basePath),
            \Modules\Ai\Application\AiIntegrationGate::class => \DI\get(\Modules\Ai\Application\ModuleAiIntegrationGate::class),
            CustomerScope::class => factory(static function (ContainerInterface $c): CustomerScope {
                if (!class_exists(\Modules\Listings\Application\BrokerIdentity::class)) {
                    return new UnrestrictedCustomerScope();
                }
                $broker = $c->get(\Modules\Listings\Application\BrokerIdentity::class);
                if ($broker instanceof CustomerScope) {
                    return $broker;
                }

                return new class ($broker) implements CustomerScope {
                    public function __construct(private readonly object $broker)
                    {
                    }

                    public function scopedCustomerIds(mixed $user): ?array
                    {
                        /** @var list<string>|null $ids */
                        $ids = $this->broker->scopedCustomerIds($user);

                        return $ids;
                    }

                    public function applyCustomerScope(\Doctrine\ORM\QueryBuilder $qb, string $alias, mixed $user): void
                    {
                        $this->broker->applyCustomerScope($qb, $alias, $user);
                    }
                };
            }),
        ];
    }
}
