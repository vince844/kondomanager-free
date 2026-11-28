<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Controllers\Impostazioni;

use App\Enums\Permission;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RegistroModifiche\AccessoAlRegistro;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Gate;

class ImpostazioniController extends Controller
{
    /**
     * Le schede della pagina e ciò che ognuna chiede: lo stesso controllo della rotta che la scheda apre. È l'elenco solo: la
     * pagina si apre a chi può aprire almeno una scheda e riceve le chiavi di quelle da mostrare; la voce «Impostazioni» del
     * menu (`AppHeader.vue`, `permessiDelleImpostazioni`) chiede gli stessi permessi, e una prova lo confronta con questo elenco.
     * Le rotte delle schede non dipendono da qui: la pagina mostra, non apre.
     *
     * Un permesso è il permesso della rotta; il ruolo è il ruolo della rotta. «Vede il registro delle modifiche» vale come nella
     * sua pagina, mai per i ruoli del portale (`AccessoAlRegistro::vedeIlRegistro`).
     *
     * @var array<string, Permission|Role>
     */
    public const SCHEDE = [
        'generali'      => Permission::MANAGE_GENERAL_SETTINGS,
        'registro'      => Permission::VIEW_REGISTRO_MODIFICHE,
        'stampe'        => Permission::MANAGE_GENERAL_SETTINGS,
        'assemblee'     => Role::AMMINISTRATORE,
        'utenti'        => Permission::VIEW_USERS,
        'posta'         => Permission::MANAGE_GENERAL_SETTINGS,
        'cron'          => Permission::MANAGE_GENERAL_SETTINGS,
        'errori'        => Permission::MANAGE_GENERAL_SETTINGS,
        'backup'        => Permission::MANAGE_GENERAL_SETTINGS,
        'installazione' => Permission::MANAGE_GENERAL_SETTINGS,
        'aggiornamento' => Role::AMMINISTRATORE,
    ];

    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): Response
    {
        $schede = self::schedeDi($request->user());

        Gate::allowIf(fn () => $schede !== [], __('errors.403_message'));

        $backupsEnabled = (bool) config('backup.enabled', true);

        try {
            $backupRunning = $backupsEnabled && \App\Models\Backup::running()->exists();
        } catch (\Throwable) {
            // La hub non deve mai rompersi per lo stato dei backup
            $backupRunning = false;
        }

        return Inertia::render('impostazioni/impostazioni', [
            'schede'          => $schede,
            'backups_enabled' => $backupsEnabled,
            'backup_running'  => $backupRunning,
        ]);
    }

    /**
     * Le chiavi delle schede che l'utente può aprire, nell'ordine dell'elenco.
     *
     * @return list<string>
     */
    public static function schedeDi(?User $utente): array
    {
        if (! $utente instanceof User) {
            return [];
        }

        return array_keys(array_filter(self::SCHEDE, fn (Permission|Role $requisito) => match (true) {
            $requisito instanceof Role => $utente->hasRole($requisito->value),
            $requisito === Permission::VIEW_REGISTRO_MODIFICHE => AccessoAlRegistro::vedeIlRegistro($utente),
            default => $utente->checkPermissionTo($requisito->value),
        }));
    }
}
