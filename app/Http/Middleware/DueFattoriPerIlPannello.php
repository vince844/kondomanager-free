<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Http\Middleware;

use App\Enums\Permission;
use App\Enums\Role;
use App\Models\User;
use App\Settings\GeneralSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Symfony\Component\HttpFoundation\Response;

/**
 * La verifica in due passaggi obbligatoria per chi entra nel pannello.
 *
 * Con l'impostazione generale `due_fattori_obbligatoria_pannello` accesa, chi entra nel pannello
 * (amministratore, collaboratore, o chi ha «Accesso pannello amministratore») e non ha ancora
 * confermato la verifica in due passaggi viene mandato alla pagina della verifica, con una frase
 * che dice perché. Il condòmino e il fornitore entrano nel portale come sempre.
 *
 * Sta nel gruppo `web` e non sulle singole rotte, come `CheckSuspendedUser`: un gruppo di rotte
 * dimenticato lascerebbe passare. Restano aperte le rotte che servono ad attivarla o a uscire:
 * la pagina della verifica stessa, la conferma della password che la protegge, l'uscita, il
 * profilo, la password, la verifica dell'email e l'aggiornamento del programma.
 *
 * La regola: ogni rotta che la pagina della verifica chiama, compresa la conferma della password,
 * resta aperta. La conferma sono tre rotte, la pagina (`password.confirm`), l'invio
 * (`password.confirm.store`, dal modulo e dalla finestra sulla stessa pagina) e lo stato
 * (`password.confirm.stato`): con l'invio chiuso, chi deve attivare la verifica viene rimandato
 * alla pagina della password senza fine e non entra più.
 *
 * L'impostazione si legge con un ripiego: prima che l'aggiornamento la scriva (la sua migrazione
 * delle impostazioni non è ancora passata) vale spenta, e l'accesso non si rompe.
 */
class DueFattoriPerIlPannello
{
    /** Le rotte che restano aperte a chi non ha ancora attivato la verifica. */
    public const ROTTE_APERTE = [
        'two-factor.*',
        'password.confirm',
        'password.confirm.store',
        'password.confirm.stato',
        'logout',
        'verification.*',
        'profile.edit',
        'profile.update',
        'password.edit',
        'password.update',
        'appearance',
        'system.upgrade.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! self::obbligatoria()) {
            return $next($request);
        }

        if (! is_null($user->two_factor_confirmed_at) || ! self::entraNelPannello($user)) {
            return $next($request);
        }

        if ($request->routeIs(...self::ROTTE_APERTE) || $request->is('system/upgrade*')) {
            return $next($request);
        }

        $frase = __('settings.two_factor.obbligatoria_per_il_pannello');

        // Una chiamata dalla pagina che aspetta JSON riceve la frase, non una pagina da seguire.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['message' => $frase], 403);
        }

        // La pagina della verifica dice la stessa frase da sé (`obbligatoria` nelle sue props).
        return redirect()->route('two-factor.show');
    }

    /** L'impostazione accesa; spenta se non si può leggere. */
    public static function obbligatoria(): bool
    {
        try {
            return (bool) app(GeneralSettings::class)->due_fattori_obbligatoria_pannello;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function entraNelPannello(User $user): bool
    {
        if ($user->hasAnyRole([Role::AMMINISTRATORE->value, Role::COLLABORATORE->value])) {
            return true;
        }

        try {
            return $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /** Con l'impostazione accesa, chi entra nel pannello non può togliersi la verifica. */
    public static function disattivazioneBloccata(User $user): bool
    {
        return self::obbligatoria() && self::entraNelPannello($user);
    }
}
