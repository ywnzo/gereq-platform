<?php

declare(strict_types=1);

namespace Modules\Emailing\Http;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Admin\CommandPalette\CommandPalette;
use Gereq\Admin\Menu\Menu;
use Gereq\Audit\Auditor;
use Gereq\Auth\Authenticator;
use Gereq\Http\Controller;
use Gereq\Http\Router;
use Gereq\Queue\Queue;
use Modules\Emailing\Application\CredentialVault;
use Modules\Emailing\Application\Jobs\SyncAccountJob;
use Modules\Emailing\Application\MailHostNormalizer;
use Modules\Emailing\Application\SmtpSender;
use Modules\Emailing\Domain\MailAccount;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class AccountController extends Controller
{
    public function __construct(
        Environment $twig,
        Router $router,
        RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
        private readonly Menu $menu,
        private readonly CommandPalette $palette,
        private readonly Auditor $auditor,
        private readonly TranslatorInterface $translator,
        private readonly CredentialVault $vault,
        private readonly SmtpSender $smtp,
        private readonly Queue $queue,
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        $accounts = $this->em->getRepository(MailAccount::class)->findBy([], ['name' => 'ASC']);

        return $this->page('@emailing/accounts/index.html.twig', [
            'pageTitle' => $this->translator->trans('emailing.accounts.title'),
            'accounts' => $accounts,
        ]);
    }

    public function create(): Response
    {
        return $this->page('@emailing/accounts/form.html.twig', [
            'pageTitle' => $this->translator->trans('emailing.accounts.new'),
            'account' => null,
            'protocols' => MailAccount::PROTOCOLS,
            'encryptions' => MailAccount::ENCRYPTIONS,
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $account = new MailAccount((string) $data['name'], (string) $data['email']);
        $this->applyAccount($account, $data, true);
        if (!empty($data['is_default'])) {
            $this->clearDefaults();
            $account->setDefault(true);
        }
        $this->em->persist($account);
        $this->em->flush();
        $this->auditor->log('emailing.account.created', 'emailing_account', $account->id());
        $this->flash('success', $this->translator->trans('emailing.flash.account_saved'));

        return $this->redirect('admin.emailing.accounts.index');
    }

    public function edit(string $id): Response
    {
        $account = $this->find($id);

        return $this->page('@emailing/accounts/form.html.twig', [
            'pageTitle' => $this->translator->trans('emailing.accounts.edit'),
            'account' => $account,
            'protocols' => MailAccount::PROTOCOLS,
            'encryptions' => MailAccount::ENCRYPTIONS,
        ]);
    }

    public function update(Request $request, string $id): Response
    {
        $account = $this->find($id);
        $data = $this->validated($request);
        $this->applyAccount($account, $data, false);
        if (!empty($data['is_default'])) {
            $this->clearDefaults();
            $account->setDefault(true);
        } else {
            $account->setDefault(false);
        }
        $this->em->flush();
        $this->auditor->log('emailing.account.updated', 'emailing_account', $account->id());
        $this->flash('success', $this->translator->trans('emailing.flash.account_saved'));

        return $this->redirect('admin.emailing.accounts.index');
    }

    public function destroy(string $id): Response
    {
        $account = $this->find($id);
        $this->em->remove($account);
        $this->em->flush();
        $this->auditor->log('emailing.account.deleted', 'emailing_account', $id);
        $this->flash('success', $this->translator->trans('emailing.flash.account_deleted'));

        return $this->redirect('admin.emailing.accounts.index');
    }

    public function sync(string $id): Response
    {
        $account = $this->find($id);
        $this->queue->dispatch(new SyncAccountJob($account->id()));
        $this->flash('success', $this->translator->trans('emailing.flash.sync_queued'));

        return $this->redirectBack('admin.emailing.accounts.index');
    }

    public function test(string $id): Response
    {
        $account = $this->find($id);
        try {
            $this->smtp->testConnection($account);
            $this->flash('success', $this->translator->trans('emailing.flash.smtp_ok'));
        } catch (\Throwable $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirectBack('admin.emailing.accounts.index');
    }

    private function find(string $id): MailAccount
    {
        $account = $this->em->find(MailAccount::class, $id);
        if (!$account instanceof MailAccount) {
            $this->abort(404, $this->translator->trans('emailing.error.account_not_found'));
        }

        return $account;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $this->validate($request, [
            'name' => 'required|min:2|max:190',
            'email' => 'required|email|max:190',
            'smtp_host' => 'required|max:190',
            'smtp_port' => 'required',
            'smtp_encryption' => 'required|in:tls,ssl,none',
            'smtp_username' => 'nullable|max:190',
            'smtp_password' => 'nullable|max:500',
            'incoming_protocol' => 'required|in:imap,pop3',
            'incoming_host' => 'nullable|max:190',
            'incoming_port' => 'nullable',
            'incoming_encryption' => 'required|in:tls,ssl,none',
            'incoming_username' => 'nullable|max:190',
            'incoming_password' => 'nullable|max:500',
            'is_default' => 'nullable',
            'is_active' => 'nullable',
            'signature_html' => 'nullable|max:50000',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function applyAccount(MailAccount $account, array $data, bool $creating): void
    {
        $account->setName((string) $data['name']);
        $account->setEmail((string) $data['email']);
        $smtpPass = trim((string) ($data['smtp_password'] ?? ''));
        $smtpEnc = $smtpPass !== '' ? $this->vault->encrypt($smtpPass) : ($creating ? $this->vault->encrypt('') : null);
        $account->setSmtp(
            MailHostNormalizer::normalize((string) $data['smtp_host']),
            (int) $data['smtp_port'],
            (string) $data['smtp_encryption'],
            (string) ($data['smtp_username'] ?? ''),
            $smtpEnc,
        );

        $inPass = trim((string) ($data['incoming_password'] ?? ''));
        if ($inPass === '' && $smtpPass !== '') {
            $inPass = $smtpPass;
        }
        $inEnc = $inPass !== '' ? $this->vault->encrypt($inPass) : ($creating ? $this->vault->encrypt('') : null);
        $account->setIncoming(
            (string) $data['incoming_protocol'],
            MailHostNormalizer::normalize((string) ($data['incoming_host'] ?? '')),
            (int) ($data['incoming_port'] ?? 993),
            (string) $data['incoming_encryption'],
            (string) ($data['incoming_username'] ?? $data['smtp_username'] ?? ''),
            $inEnc,
        );
        $account->setActive(in_array((string) ($data['is_active'] ?? '0'), ['1', 'true', 'on', 'yes'], true));
        $account->setSignatureHtml(isset($data['signature_html']) ? (string) $data['signature_html'] : null);
    }

    private function clearDefaults(): void
    {
        foreach ($this->em->getRepository(MailAccount::class)->findBy(['isDefault' => true]) as $row) {
            /** @var MailAccount $row */
            $row->setDefault(false);
        }
    }

    /** @param array<string, mixed> $data */
    private function page(string $view, array $data): Response
    {
        $user = $this->auth->user();

        return $this->render($view, array_merge($data, [
            'menu' => $this->menu->tree($user, $this->router, $this->request()),
            'commands' => $this->palette->all($user),
            'user' => $user,
        ]));
    }
}
