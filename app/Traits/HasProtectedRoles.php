<?php

namespace App\Traits;

use App\Enums\Role;

trait HasProtectedRoles
{
    protected function getProtectedRoles(): array
    {
        return [
            Role::AMMINISTRATORE->value,
            Role::FORNITORE->value,
            Role::COLLABORATORE->value,
            Role::UTENTE->value
        ];
    }

    protected function isProtectedRole(string $roleName): bool
    {
        $protectedRoles = array_map('strtolower', $this->getProtectedRoles());
        return in_array(strtolower(trim($roleName)), $protectedRoles);
    }

 
    protected function getProtectedRolesForValidation(): string
    {
        return implode(',', $this->getProtectedRoles());
    }
}