<?php

declare(strict_types=1);

namespace Modules\CarService\Application;

use Doctrine\ORM\EntityManagerInterface;
use Gereq\Auth\Authenticator;
use Gereq\User\Entity\User;
use Modules\CarService\Domain\ServiceBranch;
use Modules\CarService\Domain\UserBranchPreference;
use Modules\CarService\Domain\ServiceOrder;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Globálně vybraná pobočka — session přepínač + výchozí hodnota z profilu uživatele.
 *
 * Session:
 * - klíč chybí → použije se default uživatele (nebo všechny)
 * - klíč = '' → explicitně všechny pobočky
 * - klíč = uuid → konkrétní pobočka
 */
final class BranchContext
{
    public const SESSION_KEY = 'carservice.selected_branch_id';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $em,
        private readonly Authenticator $auth,
    ) {
    }

    public function branchId(): ?string
    {
        $session = $this->requestStack->getSession();
        if (!$session->has(self::SESSION_KEY)) {
            return $this->defaultBranchId($this->auth->user());
        }

        $id = trim((string) $session->get(self::SESSION_KEY, ''));
        if ($id === '') {
            return null;
        }

        return $this->activeBranchId($id) ?? $this->clearInvalidSession();
    }

    public function branch(): ?ServiceBranch
    {
        $id = $this->branchId();
        if ($id === null) {
            return null;
        }
        $branch = $this->em->find(ServiceBranch::class, $id);

        return $branch instanceof ServiceBranch ? $branch : null;
    }

    public function setBranchId(?string $id): void
    {
        $session = $this->requestStack->getSession();
        $id = $id !== null ? trim($id) : '';
        if ($id === '') {
            // Explicitní „všechny pobočky“ — nesmí se smazat klíč (jinak by se vrátil user default).
            $session->set(self::SESSION_KEY, '');

            return;
        }
        if ($this->activeBranchId($id) === null) {
            $session->set(self::SESSION_KEY, '');

            return;
        }
        $session->set(self::SESSION_KEY, $id);
    }

    public function defaultBranchId(?User $user = null): ?string
    {
        $user ??= $this->auth->user();
        if (!$user instanceof User) {
            return null;
        }
        $pref = $this->em->find(UserBranchPreference::class, $user->id());
        if (!$pref instanceof UserBranchPreference) {
            return null;
        }
        $id = $pref->defaultBranchId();
        if ($id === null || $id === '') {
            return null;
        }

        return $this->activeBranchId($id);
    }

    public function setDefaultBranchId(User $user, ?string $branchId): void
    {
        $branchId = $branchId !== null ? trim($branchId) : '';
        if ($branchId !== '' && $this->activeBranchId($branchId) === null) {
            $branchId = '';
        }

        $pref = $this->em->find(UserBranchPreference::class, $user->id());
        if ($branchId === '') {
            if ($pref instanceof UserBranchPreference) {
                $this->em->remove($pref);
            }

            return;
        }

        if (!$pref instanceof UserBranchPreference) {
            $pref = new UserBranchPreference($user->id());
            $this->em->persist($pref);
        }
        $pref->setDefaultBranchId($branchId);
    }

    /**
     * @return list<array{value: string, label: string, description?: string, search?: string}>
     */
    public function selectOptions(string $allLabel): array
    {
        $options = [
            ['value' => '', 'label' => $allLabel, 'search' => $allLabel],
        ];
        foreach ($this->em->getRepository(ServiceBranch::class)->findBy(['active' => true], ['name' => 'ASC']) as $branch) {
            if (!$branch instanceof ServiceBranch) {
                continue;
            }
            $label = $branch->selectLabel();
            $options[] = [
                'value' => $branch->id(),
                'label' => $label,
                'description' => $branch->city() ?: '',
                'search' => $label . ' ' . ($branch->city() ?: '') . ' ' . ($branch->street() ?: ''),
            ];
        }

        return $options;
    }

    public function hasBranches(): bool
    {
        return $this->em->getRepository(ServiceBranch::class)->count(['active' => true]) > 0;
    }

    /**
     * Edit / tracker: uživatel s výchozí pobočkou smí jen zakázky své pobočky.
     * Superadmin / administrator a uživatelé bez výchozí pobočky — bez omezení.
     */
    public function canMutateOrder(?User $user, ServiceOrder $order): bool
    {
        if (!$user instanceof User) {
            return false;
        }
        if ($user->isAdministrator()) {
            return true;
        }
        $home = $this->defaultBranchId($user);
        if ($home === null) {
            return true;
        }

        return $order->branchId() === $home;
    }

    /**
     * Viditelnost ve zvoleném kontextu pobočky (session / default).
     * Když je aktivní konkrétní pobočka, cizí záznamy nejsou dostupné (ani adminovi — přepne na „Všechny“).
     */
    public function canAccessScoped(?string $entityBranchId): bool
    {
        $scope = $this->branchId();
        if ($scope === null) {
            return true;
        }

        return $entityBranchId === $scope;
    }

    private function activeBranchId(string $id): ?string
    {
        $branch = $this->em->find(ServiceBranch::class, $id);
        if (!$branch instanceof ServiceBranch || !$branch->isActive()) {
            return null;
        }

        return $id;
    }

    private function clearInvalidSession(): ?string
    {
        $session = $this->requestStack->getSession();
        $session->remove(self::SESSION_KEY);

        return $this->defaultBranchId($this->auth->user());
    }
}
