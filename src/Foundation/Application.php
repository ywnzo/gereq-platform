<?php

declare(strict_types=1);

namespace Gereq\Foundation;

use DI\Container;
use DI\ContainerBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Admin\Menu\Menu;
use Gereq\Admin\Resource\ResourceRegistrar;
use Gereq\Admin\Ui\Icons;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasswordHasher;
use Gereq\Cache\CacheFactory;
use Gereq\Config\Repository;
use Gereq\Database\EntityManagerFactory;
use Gereq\Debug\DebugBar;
use Gereq\Debug\QueryCollector;
use Gereq\Debug\Renderer;
use Gereq\Event\EventBus;
use Gereq\File\FileManager;
use Gereq\Hook\HookManager;
use Gereq\Http\ControllerDispatcher;
use Gereq\Http\ExceptionHandler;
use Gereq\Http\FormState;
use Gereq\Http\Kernel;
use Gereq\Http\Router;
use Gereq\Module\CapabilityRegistry;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleManager;
use Gereq\Notification\Notifier;
use Gereq\Queue\Queue;
use Gereq\Security\ContentSecurityPolicy;
use Gereq\Security\Csrf;
use Gereq\Security\RateLimiter;
use Gereq\Setting\Settings;
use Gereq\User\PermissionRegistry;
use Gereq\Validation\ValidationRegistry;
use Gereq\Validation\Validator;
use Gereq\View\AssetManager;
use Gereq\View\TwigFactory;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mailer\Transport;
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

        $container->get(CoreRegistrar::class)->register();
        if ((bool) $config->get('app.debug', false)) {
            $container->get(DebugBar::class);
        }
        $container->get(ModuleManager::class)->boot($container->get(ModuleContext::class));
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
     * @return array<string, mixed>
     */
    private static function definitions(string $basePath, Repository $config): array
    {
        return [
            Repository::class => $config,
            'basePath' => $basePath,
            RequestStack::class => \DI\create(RequestStack::class),
            Router::class => \DI\create(Router::class),
            HookManager::class => \DI\create(HookManager::class),
            ValidationRegistry::class => \DI\autowire(),
            Validator::class => \DI\autowire(),
            FormState::class => \DI\autowire(),
            EventBus::class => \DI\create(EventBus::class),
            Menu::class => \DI\create(Menu::class),
            CapabilityRegistry::class => \DI\create(CapabilityRegistry::class),
            PermissionRegistry::class => \DI\create(PermissionRegistry::class),
            Dashboard::class => \DI\create(Dashboard::class),
            CommandPalette::class => \DI\create(CommandPalette::class),
            ResourceRegistrar::class => \DI\create(ResourceRegistrar::class),
            Icons::class => \DI\create(Icons::class),
            PasswordHasher::class => \DI\create(PasswordHasher::class),
            ContentSecurityPolicy::class => \DI\create(ContentSecurityPolicy::class),
            AssetManager::class => \DI\factory(static fn (): AssetManager => new AssetManager($basePath)),
            QueryCollector::class => \DI\create(QueryCollector::class),
            Renderer::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            DebugBar::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            EntityManagerInterface::class => \DI\factory(static function (Repository $config, QueryCollector $queries) use ($basePath): EntityManagerInterface {
                return (new EntityManagerFactory($config, $basePath, $queries))->create();
            }),
            CacheItemPoolInterface::class => \DI\factory(static function (Repository $config) use ($basePath): CacheItemPoolInterface {
                return (new CacheFactory($config, $basePath))->create();
            }),
            Queue::class => \DI\factory(static fn (Repository $config): Queue => new Queue($config, $basePath)),
            RateLimiter::class => \DI\factory(static fn (): RateLimiter => new RateLimiter($basePath . '/storage/cache/rate')),
            MailerInterface::class => \DI\factory(static function (Repository $config): MailerInterface {
                return new Mailer(Transport::fromDsn((string) $config->get('mail.dsn', 'null://null')));
            }),
            Csrf::class => \DI\autowire(),
            Authenticator::class => \DI\autowire(),
            Auditor::class => \DI\autowire(),
            Settings::class => \DI\autowire(),
            Notifier::class => \DI\autowire(),
            FileManager::class => \DI\factory(static function (EntityManagerInterface $em, Repository $config) use ($basePath): FileManager {
                return new FileManager($em, $config, $basePath);
            }),
            ModuleManager::class => \DI\factory(static function (EntityManagerInterface $em, PermissionRegistry $permissions, Auditor $auditor) use ($basePath): ModuleManager {
                return new ModuleManager($basePath, $em, $permissions, $auditor);
            }),
            TwigFactory::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            Environment::class => \DI\factory(static fn (TwigFactory $factory): Environment => $factory->create()),
            Kernel::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            ExceptionHandler::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            ControllerDispatcher::class => \DI\autowire(),
            ContainerInterface::class => \DI\get(Container::class),
            ModuleContext::class => \DI\autowire(),
            \Gereq\Console\Command\CacheClearCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeModuleCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeResourceCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
        ];
    }
}
