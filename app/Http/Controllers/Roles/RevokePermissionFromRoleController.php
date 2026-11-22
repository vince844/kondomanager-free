<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Roles;

use App\Services\RegistroModifiche\AttiSulleUtenze;
use App\Http\Controllers\Controller;
use App\Traits\HasProtectedRoles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Revoca un permesso a un ruolo.
 *
 * ## La porta peggiore delle tre chiuse nella beta.55
 *
 * `RoleController` protegge i quattro ruoli di sistema — non si modificano, non si cancellano, e
 * il frontend lo spiega con `is_protected`. Questa rotta vive in un controller a parte, **e non
 * passava da nessuna guardia**: un condòmino autenticato poteva svuotare il ruolo `amministratore`
 * un permesso alla volta, fino a un'installazione in cui nessuno può più fare niente.
 *
 * È il difetto della beta.44 visto da un'altra angolazione: la stessa domanda — *questo ruolo si
 * può toccare?* — aveva **due risposte in due posti**, e quello senza risposta era raggiungibile.
 */
class RevokePermissionFromRoleController extends Controller
{
    use HasProtectedRoles;

    public function __invoke(Role $role, Permission $permission): RedirectResponse
    {
        Gate::authorize('update', Role::class);

        if ($this->isProtectedRole($role->name)) {
            abort(403, __('ruoli.cannot_edit_default_role', ['role' => $role->name]));
        }

        // Stessa asimmetria dell'utente: chi non ha un permesso non può toglierlo a un ruolo,
        // perché non saprebbe rimetterlo.
        if (! Auth::user()?->hasPermissionTo($permission->name)) {
            abort(403, __('validation.custom.permissions.not_allowed'));
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($role, $permission) {
            $permessiPrima = AttiSulleUtenze::permessiDelRuolo($role);
            $role->revokePermissionTo($permission);
            AttiSulleUtenze::ruolo($role, 'modificata', $permessiPrima);
        });

        return back();
    }
}
