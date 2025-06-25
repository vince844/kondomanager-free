<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use App\Enums\Permission as PermissionEnum;
use App\Enums\Role as RoleEnum;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Create or update permissions
        foreach (PermissionEnum::cases() as $enumCase) {
            Permission::updateOrCreate(
                ['name' => $enumCase->value],
                ['description' => $enumCase->description()]
            );
        }

        // Create or update roles and assign permissions
        foreach (RoleEnum::cases() as $roleEnum) {
            $role = Role::updateOrCreate(
                ['name' => $roleEnum->value],
                ['description' => $roleEnum->description()]
            );

            $role->syncPermissions(array_map(
                fn($permission) => $permission->value,
                $roleEnum->permissions()
            ));
        }
        
        // Clear cache again (optional)
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
}
