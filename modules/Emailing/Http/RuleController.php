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
use Modules\Emailing\Domain\MailAccount;
use Modules\Emailing\Domain\MailFolder;
use Modules\Emailing\Domain\MailLabel;
use Modules\Emailing\Domain\MailRule;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

final class RuleController extends Controller
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
    ) {
        parent::__construct($twig, $router, $requestStack);
    }

    public function index(): Response
    {
        /** @var list<MailRule> $rules */
        $rules = $this->em->getRepository(MailRule::class)->findBy([], ['priority' => 'ASC', 'name' => 'ASC']);
        $accounts = [];
        foreach ($this->em->getRepository(MailAccount::class)->findBy([], ['name' => 'ASC']) as $account) {
            /** @var MailAccount $account */
            $accounts[$account->id()] = $account;
        }

        return $this->page('@emailing/rules/index.html.twig', [
            'pageTitle' => $this->translator->trans('emailing.rules.title'),
            'rules' => $rules,
            'accounts' => $accounts,
        ]);
    }

    public function create(): Response
    {
        return $this->formPage(null);
    }

    public function store(Request $request): Response
    {
        $data = $this->validated($request);
        $rule = new MailRule((string) $data['name']);
        $this->applyRule($rule, $data);
        $this->em->persist($rule);
        $this->em->flush();
        $this->auditor->log('emailing.rule.created', 'emailing_rule', $rule->id());
        $this->flash('success', $this->translator->trans('emailing.flash.rule_saved'));

        return $this->redirect('admin.emailing.rules.index');
    }

    public function edit(string $id): Response
    {
        return $this->formPage($this->find($id));
    }

    public function update(Request $request, string $id): Response
    {
        $rule = $this->find($id);
        $data = $this->validated($request);
        $this->applyRule($rule, $data);
        $this->em->flush();
        $this->auditor->log('emailing.rule.updated', 'emailing_rule', $rule->id());
        $this->flash('success', $this->translator->trans('emailing.flash.rule_saved'));

        return $this->redirect('admin.emailing.rules.index');
    }

    public function destroy(string $id): Response
    {
        $rule = $this->find($id);
        $this->em->remove($rule);
        $this->em->flush();
        $this->auditor->log('emailing.rule.deleted', 'emailing_rule', $id);
        $this->flash('success', $this->translator->trans('emailing.flash.rule_deleted'));

        return $this->redirect('admin.emailing.rules.index');
    }

    private function formPage(?MailRule $rule): Response
    {
        $accounts = $this->em->getRepository(MailAccount::class)->findBy(['isActive' => true], ['name' => 'ASC']);
        $folders = [];
        $labels = [];
        foreach ($accounts as $account) {
            /** @var MailAccount $account */
            $folders[$account->id()] = $this->em->getRepository(MailFolder::class)->findBy(
                ['accountId' => $account->id()],
                ['name' => 'ASC'],
            );
            $labels[$account->id()] = $this->em->getRepository(MailLabel::class)->findBy(
                ['accountId' => $account->id()],
                ['name' => 'ASC'],
            );
        }

        return $this->page('@emailing/rules/form.html.twig', [
            'pageTitle' => $rule
                ? $this->translator->trans('emailing.rules.edit')
                : $this->translator->trans('emailing.rules.new'),
            'rule' => $rule,
            'accounts' => $accounts,
            'foldersByAccount' => $folders,
            'labelsByAccount' => $labels,
            'conditionFields' => [
                'from' => 'emailing.rules.field.from',
                'from_name' => 'emailing.rules.field.from_name',
                'subject' => 'emailing.rules.field.subject',
                'to' => 'emailing.rules.field.to',
                'folder_role' => 'emailing.rules.field.folder_role',
                'has_attachments' => 'emailing.rules.field.has_attachments',
                'keyword' => 'emailing.rules.field.keyword',
            ],
            'conditionOps' => [
                'eq' => 'emailing.rules.op.eq',
                'neq' => 'emailing.rules.op.neq',
                'contains' => 'emailing.rules.op.contains',
                'not_contains' => 'emailing.rules.op.not_contains',
                'starts_with' => 'emailing.rules.op.starts_with',
            ],
            'actionTypes' => [
                'mark_read' => 'emailing.rules.action.mark_read',
                'flag' => 'emailing.rules.action.flag',
                'pin' => 'emailing.rules.action.pin',
                'apply_label' => 'emailing.rules.action.apply_label',
                'move' => 'emailing.rules.action.move',
            ],
        ]);
    }

    private function find(string $id): MailRule
    {
        $rule = $this->em->find(MailRule::class, $id);
        if (!$rule instanceof MailRule) {
            $this->abort(404);
        }

        return $rule;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $this->validate($request, [
            'name' => 'required|min:2|max:190',
            'account_id' => 'nullable|max:36',
            'priority' => 'nullable',
            'is_active' => 'nullable',
            'stop_processing' => 'nullable',
            'conditions' => 'nullable',
            'actions' => 'nullable',
        ]);
    }

    /** @param array<string, mixed> $data */
    private function applyRule(MailRule $rule, array $data): void
    {
        $rule->setName((string) $data['name']);
        $rule->setAccountId(isset($data['account_id']) ? (string) $data['account_id'] : null);
        $rule->setPriority((int) ($data['priority'] ?? 100));
        $rule->setActive(in_array((string) ($data['is_active'] ?? '0'), ['1', 'true', 'on', 'yes'], true));
        $rule->setStopProcessing(in_array((string) ($data['stop_processing'] ?? '0'), ['1', 'true', 'on', 'yes'], true));
        $rule->setConditions($this->parseRows($data['conditions'] ?? [], ['field', 'op', 'value'], 'field'));
        $rule->setActions($this->parseActions($data['actions'] ?? []));
    }

    /**
     * @param mixed $raw
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private function parseRows(mixed $raw, array $keys, string $requiredKey): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (trim((string) ($row[$requiredKey] ?? '')) === '') {
                continue;
            }
            $item = [];
            foreach ($keys as $key) {
                $item[$key] = isset($row[$key]) ? trim((string) $row[$key]) : '';
            }
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    private function parseActions(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = trim((string) ($row['type'] ?? ''));
            if ($type === '') {
                continue;
            }
            $action = ['type' => $type];
            if ($type === 'apply_label') {
                $action['keyword'] = trim((string) ($row['keyword'] ?? $row['value'] ?? ''));
            }
            if ($type === 'move') {
                $action['folder_id'] = trim((string) ($row['folder_id'] ?? ''));
                $action['folder_role'] = trim((string) ($row['folder_role'] ?? ''));
            }
            $out[] = $action;
        }

        return $out;
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
