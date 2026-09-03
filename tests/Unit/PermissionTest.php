<?php

declare(strict_types=1);

namespace Gereq\Tests\Unit;

use Gereq\User\Entity\Permission;
use Gereq\User\Entity\Role;
use Gereq\User\Entity\User;
use PHPUnit\Framework\TestCase;

final class PermissionTest extends TestCase
{
    public function testAdministratorCanEverything(): void
    {
        $user = new User('Jiří Novák', 'jiri.novak@gereq.cz', 'hash');
        $role = new Role('administrator', 'Administrátor');
        $user->assignRole($role);

        self::assertTrue($user->can('customers.delete'));
        self::assertTrue($user->isAdministrator());
    }

    public function testRolePermissionIsChecked(): void
    {
        $user = new User('Petra Dvořáková', 'petra.dvorakova@gereq.cz', 'hash');
        $role = new Role('viewer', 'Prohlížeč');
        $role->givePermission(new Permission('customers.view', 'Zobrazit', 'Zákazníci'));
        $user->assignRole($role);

        self::assertTrue($user->can('customers.view'));
        self::assertFalse($user->can('customers.delete'));
    }
}
