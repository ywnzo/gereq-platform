<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Auth\PasswordHasher;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\I18n\LocaleCatalog;
use Gereq\Setting\SettingRecord;
use Gereq\Setting\Settings;
use Gereq\Setup\SetupState;
use Gereq\User\Entity\User;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class SetupController extends Controller
{
    /** @var list<string> */
    private const STEPS = ['welcome', 'account', 'company', 'shop', 'done'];

    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly Authenticator $auth,
        private readonly SetupState $setup,
        private readonly Settings $settings,
        private readonly EntityManagerInterface $em,
        private readonly PasswordHasher $hasher,
        private readonly LocaleCatalog $locales,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(Request $request, ?string $step = null): Response
    {
        if ($this->setup->isComplete()) {
            return $this->redirect('admin.dashboard');
        }

        $step = $this->normalizeStep($step ?? 'welcome');
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return $this->redirect('admin.login');
        }

        return $this->wizard($step, [
            'user' => $user,
            'locales' => $this->locales->all(),
            'values' => $this->currentValues($user),
            'stepIndex' => array_search($step, self::STEPS, true),
        ]);
    }

    public function saveWelcome(Request $request): Response
    {
        $name = trim((string) $request->request->get('app_name', ''));
        if ($name === '') {
            $this->flash('error', $this->translator->trans('setup.error.app_name'));

            return $this->redirect('admin.setup.step', ['step' => 'welcome']);
        }
        $this->settings->set('app.name', $name, SettingRecord::TYPE_STRING, 'core');
        // Derive short lockup name when not customized yet.
        $existingBrand = trim((string) $this->settings->get('brand.name', ''));
        if ($existingBrand === '') {
            $short = trim((string) preg_replace('/\s+Platform$/iu', '', $name));
            $parts = preg_split('/\s+/u', $short !== '' ? $short : $name) ?: [];
            $this->settings->set('brand.name', $parts[0] ?? $name, SettingRecord::TYPE_STRING, 'core');
        }
        $existingSite = trim((string) $this->settings->get('brand.site_name', ''));
        if ($existingSite === '') {
            $this->settings->set('brand.site_name', $name, SettingRecord::TYPE_STRING, 'core');
        }
        $locale = (string) $request->request->get('locale', 'cs');
        if ($this->locales->isSupported($locale)) {
            $this->settings->set('app.locale', $locale, SettingRecord::TYPE_STRING, 'core');
        }
        $theme = (string) $request->request->get('theme', 'system');
        if (in_array($theme, ['system', 'light', 'dark'], true)) {
            $this->settings->set('admin.theme', $theme, SettingRecord::TYPE_STRING, 'core');
        }

        return $this->redirect('admin.setup.step', ['step' => 'account']);
    }

    public function saveAccount(Request $request): Response
    {
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return $this->redirect('admin.login');
        }

        $name = trim((string) $request->request->get('name', ''));
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');
        $confirm = (string) $request->request->get('password_confirmation', '');

        if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->flash('error', $this->translator->trans('setup.error.account'));

            return $this->redirect('admin.setup.step', ['step' => 'account']);
        }

        $owner = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($owner instanceof User && $owner->id() !== $user->id()) {
            $this->flash('error', $this->translator->trans('setup.error.email_taken'));

            return $this->redirect('admin.setup.step', ['step' => 'account']);
        }

        $user->setName($name);
        $user->setEmail($email);

        $min = (int) config('security.password_min_length', 10);
        if ($password !== '') {
            if (strlen($password) < $min || $password !== $confirm) {
                $this->flash('error', $this->translator->trans('setup.error.password'));

                return $this->redirect('admin.setup.step', ['step' => 'account']);
            }
            $user->setPasswordHash($this->hasher->hash($password));
        }

        $this->em->flush();

        return $this->redirect('admin.setup.step', ['step' => 'company']);
    }

    public function saveCompany(Request $request): Response
    {
        $map = [
            'seller_name' => 'invoices.seller_name',
            'seller_ico' => 'invoices.seller_ico',
            'seller_dic' => 'invoices.seller_dic',
            'seller_address' => 'invoices.seller_address',
            'seller_email' => 'invoices.seller_email',
            'order_prefix' => 'orders.number_prefix',
            'invoice_prefix' => 'invoices.number_prefix',
        ];
        foreach ($map as $field => $key) {
            $value = trim((string) $request->request->get($field, ''));
            $this->settings->set($key, $value, SettingRecord::TYPE_STRING, 'orders');
        }

        return $this->redirect('admin.setup.step', ['step' => 'shop']);
    }

    public function saveShop(Request $request): Response
    {
        $store = trim((string) $request->request->get('store_name', ''));
        if ($store === '') {
            $store = (string) $this->settings->get('app.name', 'GEREQ Shop');
        }
        $this->settings->set('shop.store_name', $store, SettingRecord::TYPE_STRING, 'shop');
        $currency = strtoupper(trim((string) $request->request->get('currency', 'CZK')));
        if (strlen($currency) === 3) {
            $this->settings->set('shop.default_currency', $currency, SettingRecord::TYPE_STRING, 'shop');
        }
        $checkout = $request->request->get('checkout_enabled', '1') === '1';
        $this->settings->set('shop.checkout_enabled', $checkout, SettingRecord::TYPE_BOOLEAN, 'shop');

        return $this->redirect('admin.setup.step', ['step' => 'done']);
    }

    public function finish(): Response
    {
        $this->setup->markComplete();
        $this->flash('success', $this->translator->trans('setup.flash.done'));

        return $this->redirect('admin.dashboard');
    }

    private function normalizeStep(string $step): string
    {
        return in_array($step, self::STEPS, true) ? $step : 'welcome';
    }

    /** @return array<string, mixed> */
    private function currentValues(User $user): array
    {
        return [
            'app_name' => (string) $this->settings->get('app.name', config('app.name', 'GEREQ Platform')),
            'locale' => (string) $this->settings->get('app.locale', 'cs'),
            'theme' => (string) $this->settings->get('admin.theme', 'system'),
            'name' => $user->name(),
            'email' => $user->email(),
            'seller_name' => (string) $this->settings->get('invoices.seller_name', ''),
            'seller_ico' => (string) $this->settings->get('invoices.seller_ico', ''),
            'seller_dic' => (string) $this->settings->get('invoices.seller_dic', ''),
            'seller_address' => (string) $this->settings->get('invoices.seller_address', ''),
            'seller_email' => (string) $this->settings->get('invoices.seller_email', ''),
            'order_prefix' => (string) $this->settings->get('orders.number_prefix', 'ORD-'),
            'invoice_prefix' => (string) $this->settings->get('invoices.number_prefix', 'INV-'),
            'store_name' => (string) $this->settings->get('shop.store_name', ''),
            'currency' => (string) $this->settings->get('shop.default_currency', 'CZK'),
            'checkout_enabled' => (bool) $this->settings->get('shop.checkout_enabled', true),
        ];
    }

    /** @param array<string, mixed> $data */
    private function wizard(string $step, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render('admin/setup/wizard.html.twig', array_merge($data, [
            'pageTitle' => $this->translator->trans('setup.title'),
            'step' => $step,
            'steps' => self::STEPS,
            'user' => $user,
        ]));
    }
}
