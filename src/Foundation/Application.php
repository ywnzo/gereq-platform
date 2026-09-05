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
use Gereq\I18n\LocaleCatalog;
use Gereq\I18n\LocaleResolver;
use Gereq\I18n\TranslatorFactory;
use Gereq\Api\ApiRegistry;
use Gereq\Api\Auth\ApiTokenService;
use Gereq\Api\IdempotencyService;
use Gereq\Api\OpenApi\OpenApiGenerator;
use Gereq\Event\EventRegistry;
use Gereq\Module\CapabilityRegistry;
use Gereq\Module\ModuleContext;
use Gereq\Module\ModuleManager;
use Gereq\Notification\Notifier;
use Gereq\Queue\Queue;
use Gereq\Security\ContentSecurityPolicy;
use Gereq\Security\Csrf;
use Gereq\Security\RateLimiter;
use Gereq\Setting\Settings;
use Gereq\Setup\SetupState;
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

        $container->get(CoreRegistrar::class)->register();
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
            SetupState::class => \DI\autowire(),
            EventBus::class => \DI\autowire(),
            EventRegistry::class => \DI\create(EventRegistry::class),
            ApiRegistry::class => \DI\autowire(),
            ApiTokenService::class => \DI\autowire(),
            IdempotencyService::class => \DI\autowire(),
            OpenApiGenerator::class => \DI\autowire(),
            Menu::class => \DI\create(Menu::class),
            CapabilityRegistry::class => \DI\create(CapabilityRegistry::class),
            PermissionRegistry::class => \DI\create(PermissionRegistry::class),
            Dashboard::class => \DI\autowire(Dashboard::class),
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
            \Gereq\Security\HtmlSanitizer::class => \DI\autowire(),
            Settings::class => \DI\autowire(),
            LocaleCatalog::class => \DI\autowire(),
            LocaleResolver::class => \DI\autowire(),
            TranslatorInterface::class => \DI\factory(static function (TranslatorFactory $factory): TranslatorInterface {
                return $factory->create();
            }),
            TranslatorFactory::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            Notifier::class => \DI\autowire(),
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
            ) use ($basePath): ModuleManager {
                return new ModuleManager($basePath, $em, $permissions, $auditor, $translator);
            }),
            TwigFactory::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            \Gereq\Admin\Http\SettingsController::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Gereq\Http\BrandAssetController::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            Environment::class => \DI\factory(static fn (TwigFactory $factory): Environment => $factory->create()),
            Kernel::class => \DI\autowire(),
            \Gereq\Http\Session\SessionHandlerFactory::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            ExceptionHandler::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath)
                ->constructorParameter('debugBar', \DI\get(DebugBar::class)),
            ControllerDispatcher::class => \DI\autowire(),
            ContainerInterface::class => \DI\get(Container::class),
            ModuleContext::class => \DI\autowire(),
            \Gereq\Console\Command\InstallCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\CacheClearCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeModuleCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\MakeResourceCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\ServeDockerCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Console\Command\TwigTypesCommand::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Gereq\Ares\AresClient::class => \DI\autowire(),
            \Gereq\Ares\AresService::class => \DI\autowire(),
            \Modules\Currency\Application\RateProviderInterface::class => \DI\get(\Modules\Currency\Infrastructure\CnbRateProvider::class),
            \Modules\Currency\Infrastructure\CnbRateProvider::class => \DI\autowire(),
            \Modules\Currency\Application\SyncExchangeRates::class => \DI\autowire(),
            \Modules\Notifications\Application\NotifierBridge::class => \DI\autowire(),
            \Modules\Notifications\Application\NotificationsProvider::class => \DI\autowire(),
            \Modules\Warehouse\Application\StockService::class => \DI\autowire(),
            \Modules\Warehouse\Application\InventoryProvider::class => \DI\autowire(),
            \Modules\Orders\Application\NumberSequencer::class => \DI\autowire(),
            \Modules\Orders\Application\OrdersProvider::class => \DI\autowire(),
            \Modules\Projects\Application\ProjectsProvider::class => \DI\autowire(),
            \Modules\Calendar\Application\CalendarProvider::class => \DI\autowire(),
            \Modules\Shop\Application\CartService::class => \DI\autowire(),
            \Modules\Shop\Application\CheckoutService::class => \DI\autowire(),
            \Modules\Shop\Application\ShopProvider::class => \DI\autowire(),
            \Modules\Shop\Application\ShippingCarrierRegistry::class => \DI\autowire(),
            \Modules\Shop\Application\Carriers\ManualCarrier::class => \DI\autowire(),
            \Modules\Shop\Application\Carriers\PacketaCarrier::class => \DI\autowire(),
            \Modules\Payments\Application\PaymentsProvider::class => \DI\autowire(),
            \Modules\Payments\Application\PaymentGatewayRegistry::class => \DI\autowire(),
            \Modules\Payments\Application\Gateways\ManualBankGateway::class => \DI\autowire(),
            \Modules\Payments\Application\Gateways\ComgateGateway::class => \DI\autowire(),
            \Modules\Payments\Application\ComgateInboundProvider::class => \DI\autowire(),
            \Modules\Payments\Application\PaymentService::class => \DI\autowire(),
            \Modules\Webhooks\Application\InboundWebhookRegistry::class => \DI\autowire(),
            \Modules\Coupons\Application\CouponsProvider::class => \DI\autowire(),
            \Modules\Customers\Application\StoreAuthService::class => \DI\autowire(),
            \Modules\Customers\Application\StoreTokenService::class => \DI\autowire(),
            \Modules\Customers\Application\StoreCookieBridge::class => \DI\autowire(),
            \Modules\Orders\Application\InvoiceIssuingService::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Modules\Orders\Application\OrderMailer::class => \DI\autowire(),
            \Modules\Payments\Application\ImapBankMatcher::class => \DI\autowire(),
            \Modules\Payments\Application\Gateways\GoPayGateway::class => \DI\autowire(),
            \Modules\Payments\Application\Gateways\StripeGateway::class => \DI\autowire(),
            \Modules\Shop\Application\Carriers\PplCarrier::class => \DI\autowire(),
            \Modules\CustomerUploads\Application\CustomerUploadService::class => \DI\autowire()
                ->constructorParameter('basePath', $basePath),
            \Modules\Bundles\Application\BundlesProvider::class => \DI\autowire(),
            \Modules\Loyalty\Application\LoyaltyProvider::class => \DI\autowire(),
            \Modules\Feeds\Application\Drivers\GoogleMerchantDriver::class => \DI\autowire(),
            \Modules\Feeds\Application\Drivers\HeurekaDriver::class => \DI\autowire(),
            \Modules\Feeds\Application\Drivers\CustomDriver::class => \DI\autowire(),
            \Modules\Feeds\Application\FeedsProvider::class => \DI\autowire(),
            \Modules\Media\Application\MediaProvider::class => \DI\autowire(),
            \Modules\Media\Http\MediaController::class => \DI\autowire()->constructorParameter('basePath', $basePath),
            \Modules\Cms\Application\CmsProvider::class => \DI\autowire(),
            \Modules\Returns\Application\ReturnsProvider::class => \DI\autowire(),
            \Modules\Reviews\Application\ReviewsProvider::class => \DI\autowire(),
            \Modules\Newsletter\Application\NewsletterProvider::class => \DI\autowire(),
        ];
    }
}
