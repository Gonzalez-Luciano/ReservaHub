<?php

namespace Tests\Unit\Enums;

use App\Enums\Role;
use Tests\TestCase;

class RoleTest extends TestCase
{
    public function test_business_staff_includes_owner_admin_employee_only(): void
    {
        $this->assertSame(
            [Role::Owner, Role::Admin, Role::Employee],
            Role::businessStaff()
        );
        $this->assertNotContains(Role::Customer, Role::businessStaff());
    }

    public function test_managers_includes_owner_and_admin_only(): void
    {
        $this->assertSame(
            [Role::Owner, Role::Admin],
            Role::managers()
        );
        $this->assertNotContains(Role::Employee, Role::managers());
        $this->assertNotContains(Role::Customer, Role::managers());
    }
}
