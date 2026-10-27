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

    /**
     * È il ruolo dei nuovi iscritti, il nome salvato nelle impostazioni generali (Coda 258)?
     *
     * Il confronto non guarda le maiuscole, come il MySQL di serie quando la registrazione cerca il ruolo per
     * nome. Lo usano l'eliminazione (rifiutata), la rinomina (le impostazioni seguono il nome) e l'elenco dei
     * ruoli (la riga lo dice prima di «Elimina»).
     */
    protected function isRuoloDeiNuoviIscritti(string $roleName): bool
    {
        return mb_strtolower($roleName) === mb_strtolower((string) app(\App\Settings\GeneralSettings::class)->default_user_role);
    }

 
    protected function getProtectedRolesForValidation(): string
    {
        return implode(',', $this->getProtectedRoles());
    }
}