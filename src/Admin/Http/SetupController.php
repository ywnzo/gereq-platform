<?php

declare(strict_types=1);

namespace Gereq\Admin\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\Admin\Dashboard\Dashboard;
use Gereq\Audit\Auditor;
use Gereq\Module\ModuleManager;
use Gereq\Setup\SetupModules;
use Gereq\Setup\SetupModuleCatalog;
use Gereq\Setup\SetupFlow;
use Gereq\Setup\SetupProfile;
use Gereq\Validation\ValidationException;
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
        private readonly ModuleManager $modules,
        private readonly SetupModules $setupModules,
        private readonly SetupModuleCatalog $moduleCatalog,
        private readonly SetupProfile $profile,
        private readonly Dashboard $dashboard,
        private readonly Auditor $auditor,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function show(Request $request, ?string $step = null): Response
    {
        if ($this->setup->isComplete()) {
            return $this->redirect('admin.dashboard');
        }

        $step = $this->normalizeStep($step ?? $this->nextStep());
        if ($response = $this->guard($step)) {
            return $response;
        }
        $user = $this->auth->user();
        if (!$user instanceof User) {
            return $this->redirect('admin.login');
        }

        $catalog = $this->moduleCatalog->catalog();

        return $this->wizard($step, [
            'user' => $user,
            'locales' => $this->locales->all(),
            'values' => $this->currentValues($user),
            'stepIndex' => array_search($step, $this->steps(), true),
            'moduleChoices' => $catalog['modules'],
            'moduleCategories' => $catalog['categories'],
            'selectedModules' => $this->settings->get('setup.modules', $this->modules->enabledIds()),
            'widgets' => $this->dashboard->catalog($user),
        ]);
    }

    public function saveWelcome(Request $request): Response
    {
        if ($response = $this->guard('welcome')) {
            return $response;
        }
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

        $this->completeStep('welcome');

        return $this->redirect('admin.setup.step', ['step' => 'account']);
    }

    public function saveAccount(Request $request): Response
    {
        if ($response = $this->guard('account')) {
            return $response;
        }
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

        $min = (int) config('security.password_min_length', 10);
        if ($password !== '') {
            if (strlen($password) < $min || $password !== $confirm) {
                $this->flash('error', $this->translator->trans('setup.error.password'));

                return $this->redirect('admin.setup.step', ['step' => 'account']);
            }
            $user->setPasswordHash($this->hasher->hash($password));
        }

        $user->setName($name);
        $user->setEmail($email);
        $this->em->flush();

        $this->completeStep('account');

        return $this->redirect('admin.setup.step', ['step' => 'company']);
    }

    public function saveCompany(Request $request): Response
    {
        if ($response = $this->guard('company')) {
            return $response;
        }
        try {
            $profile = $this->profile->validate($request->request->all());
        } catch (ValidationException $exception) {
            $this->flash('error', $this->translator->trans('setup.error.operator'));
            $request->getSession()->set('setup.operator_input', array_intersect_key($request->request->all(), array_flip(['operator_type', 'company_name', 'ico', 'dic', 'email', 'first_name', 'last_name', 'address', 'birth_date'])));

            return $this->redirect('admin.setup.step', ['step' => 'company']);
        }
        foreach ($profile as $key => $value) {
            $this->settings->set('operator.' . $key, $value, SettingRecord::TYPE_STRING, 'core');
        }
        $request->getSession()->remove('setup.operator_input');
        $this->auditor->log('setup.operator_saved', 'setup');
        $this->completeStep('company');

        return $this->redirect('admin.setup.step', ['step' => 'modules']);
    }

    public function saveModules(Request $request): Response
    {
        if ($response = $this->guard('modules')) {
            return $response;
        }
        $selected = $request->request->all('modules');
        $completed = $this->settings->get('setup.completed_steps', []);
        $this->settings->set('setup.completed_steps', array_values(array_intersect(['welcome', 'account', 'company'], $completed)), SettingRecord::TYPE_JSON, 'core');
        try {
            foreach ($selected as $id) {
                if (!is_string($id)) {
                    throw new \InvalidArgumentException('setup.error.modules');
                }
            }
            $ids = $this->setupModules->apply(array_values($selected));
        } catch (\Throwable $exception) {
            $this->flash('error', $this->translator->trans('setup.error.modules'));

            return $this->redirect('admin.setup.step', ['step' => 'modules']);
        }
        $this->settings->set('setup.modules', $ids, SettingRecord::TYPE_JSON, 'core');
        $this->completeStep('modules');
        if ($ids === []) {
            return $this->finish();
        }

        // The next request boots the new modules and exposes their dashboard widgets.
        return $this->redirect('admin.setup.step', ['step' => 'dashboard']);
    }

    public function saveDashboard(Request $request): Response
    {
        if ($response = $this->guard('dashboard')) {
            return $response;
        }
        $catalog = $this->dashboard->catalog($this->auth->user());
        $input = json_decode((string) $request->request->get('dashboard_layout', ''), true);
        if (!is_array($input) || !array_is_list($input)) {
            $this->flash('error', $this->translator->trans('setup.error.dashboard'));

            return $this->redirect('admin.setup.step', ['step' => 'dashboard']);
        }
        $byId = [];
        foreach ($input as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)) {
                continue;
            }
            $byId[$item['id']] = $item;
        }
        $layout = [];
        foreach ($catalog as $widget) {
            $item = $byId[$widget['id']] ?? [];
            $layout[] = [
                'id' => $widget['id'],
                'enabled' => filter_var($item['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'order' => is_numeric($item['order'] ?? null) ? (int) $item['order'] : $widget['order'],
                'width' => is_string($item['width'] ?? null) ? $item['width'] : $widget['width'],
            ];
        }
        $this->dashboard->saveLayout($layout);
        $this->completeStep('dashboard');

        return $this->finish();
    }

    public function finish(): Response
    {
        if ($response = $this->guard('finish')) {
            return $response;
        }
        $this->setup->markComplete();
        $this->auditor->log('setup.completed', 'setup');
        $this->flash('success', $this->translator->trans('setup.flash.done'));

        return $this->redirect('admin.dashboard');
    }

    /** @return list<string> */
    private function steps(): array
    {
        return SetupFlow::steps($this->settings->get('setup.modules', []) !== []);
    }

    private function nextStep(): string
    {
        return SetupFlow::next($this->settings->get('setup.completed_steps', []), $this->settings->get('setup.modules', []) !== []);
    }

    private function guard(string $step): ?Response
    {
        $user = $this->auth->user();
        if (!$user instanceof User || !$user->isAdministrator()) {
            $this->abort(403);
        }
        if ($this->setup->isComplete()) {
            return $this->redirect('admin.dashboard');
        }
        $next = $this->nextStep();
        $order = [...$this->steps(), 'finish'];
        $requested = array_search($step, $order, true);
        if ($requested === false || $requested > array_search($next, $order, true)) {
            return $this->redirect('admin.setup.step', ['step' => $next === 'finish' ? 'modules' : $next]);
        }

        return null;
    }

    private function completeStep(string $step): void
    {
        $completed = SetupFlow::complete(
            $this->settings->get('setup.completed_steps', []),
            $step,
            $this->settings->get('setup.modules', []) !== [],
        );
        $this->settings->set('setup.completed_steps', $completed, SettingRecord::TYPE_JSON, 'core');
    }

    private function normalizeStep(string $step): string
    {
        return in_array($step, [...$this->steps(), 'dashboard'], true) ? $step : 'welcome';
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
            'operator' => $this->operatorValues(),
        ];
    }

    /** @return array<string, mixed> */
    private function operatorValues(): array
    {
        $values = [];
        foreach (['type', 'company_name', 'ico', 'dic', 'email', 'first_name', 'last_name', 'address', 'birth_date'] as $key) {
            $values[$key] = $this->settings->get('operator.' . $key, $key === 'type' ? 'business' : '');
        }
        $input = $this->request()?->getSession()->get('setup.operator_input', []);
        if (is_array($input)) {
            foreach ($values as $key => $_) {
                $field = $key === 'type' ? 'operator_type' : $key;
                if (is_string($input[$field] ?? null)) {
                    $values[$key] = $input[$field];
                }
            }
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    private function wizard(string $step, array $data): Response
    {
        $user = $this->auth->user();

        // First-run forms must reflect deployed fields even when production Twig caches are warm.
        $this->twig->setCache(false);

        return $this->render('admin/setup/wizard.html.twig', array_merge($data, [
            'pageTitle' => $this->translator->trans('setup.title'),
            'step' => $step,
            'steps' => $this->steps(),
            'user' => $user,
        ]));
    }
}
