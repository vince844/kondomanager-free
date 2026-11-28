<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Segnalazioni;

use App\Enums\Permission;
use App\Models\Segnalazione;
use App\Models\User;
use App\Notifications\Segnalazioni\SegnalazioneAssegnataNotification;
use App\Notifications\Segnalazioni\SegnalazioneNonPiuAssegnataNotification;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class AssegnazioneSegnalazioni
{
    /**
     * Chi può assegnare: ha «Assegna segnalazioni» E «Accesso pannello amministratore».
     */
    public static function puoAssegnare(User $user): bool
    {
        return self::ha($user, Permission::ASSIGN_SEGNALAZIONI->value)
            && self::ha($user, Permission::ACCESS_ADMIN_PANEL->value);
    }

    /**
     * Chi può ricevere: ha «Accesso pannello amministratore» e «Visualizza segnalazioni», e non è sospeso. Senza il secondo permesso la scheda gli
     * risponde 403 e la segnalazione non è nei suoi elenchi: assegnargliela non serve a nessuno (Coda 696).
     */
    public static function puoRicevere(User $user): bool
    {
        return self::ha($user, Permission::ACCESS_ADMIN_PANEL->value)
            && self::ha($user, Permission::VIEW_SEGNALAZIONI->value)
            && $user->suspended_at === null;
    }

    private static function ha(User $user, string $permesso): bool
    {
        try {
            return $user->hasPermissionTo($permesso);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    /**
     * Gli utenti che possono ricevere, ordinati per nome senza distinguere maiuscole e minuscole.
     *
     * @return array<int, array{id: int, name: string}>
     */
    public static function assegnabili(): array
    {
        return User::permission(Permission::ACCESS_ADMIN_PANEL->value)
            ->whereNull('suspended_at')
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(fn (User $u) => static::puoRicevere($u))
            ->sortBy(fn (User $u) => mb_strtolower($u->name), SORT_NATURAL)
            ->values()
            ->map(fn (User $u) => [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
            ])
            ->all();
    }

    /**
     * I nomi per il filtro dell'elenco: chi può ricevere, più chi segue ancora almeno una
     * segnalazione pur non potendo più ({@see puoRicevere()}: sospeso, senza pannello o senza «Visualizza segnalazioni»).
     *
     * @return array<int, array{id: int, name: string, attivo: bool}>
     */
    public static function assegnatari(): array
    {
        // I permessi di tutti insieme: `puoRicevere()` li legge per ognuno.
        $attivi = User::permission(Permission::ACCESS_ADMIN_PANEL->value)
            ->whereNull('suspended_at')
            ->with(['roles.permissions', 'permissions'])
            ->get()
            ->filter(fn (User $u) => static::puoRicevere($u))
            ->values();

        $assegnatiIds = Segnalazione::whereNotNull('assigned_to')
            ->distinct()
            ->pluck('assigned_to');

        $giaPresentiIds = $attivi->pluck('id')->all();

        $nonAttiviConSegnalazioni = User::whereIn('id', $assegnatiIds)
            ->whereNotIn('id', $giaPresentiIds)
            ->get();

        return $attivi->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'attivo' => true,
        ])->concat($nonAttiviConSegnalazioni->map(fn (User $u) => [
            'id' => (int) $u->id,
            'name' => (string) $u->name,
            'attivo' => false,
        ]))
        ->sortBy(fn (array $u) => mb_strtolower($u['name']), SORT_NATURAL)
        ->values()
        ->all();
    }

    /**
     * Ciò che serve al riquadro «Assegnata a» per la scheda e la pagina di modifica.
     *
     * @return array{puo_assegnare: bool, io: int, assegnabili: array<int, array{id: int, name: string}>}
     */
    public static function perLaPagina(User $user): array
    {
        $puo = static::puoAssegnare($user);

        return [
            'puo_assegnare' => $puo,
            'io'            => (int) $user->id,
            'assegnabili'   => $puo ? static::assegnabili() : [],
        ];
    }

    /**
     * Assegna, riassegna o toglie l'assegnazione e manda gli avvisi.
     */
    public static function assegna(Segnalazione $segnalazione, ?User $aChi, User $da): void
    {
        $vecchioId = $segnalazione->assigned_to;
        $nuovoId = $aChi?->id;

        if ($vecchioId === $nuovoId || ($vecchioId !== null && (int) $vecchioId === (int) $nuovoId)) {
            return;
        }

        $campi = [
            'assigned_to' => $nuovoId,
            'assegnata_da' => $nuovoId !== null ? $da->id : null,
            'assegnata_il' => $nuovoId !== null ? now() : null,
        ];

        if ($nuovoId !== null && $segnalazione->stato === 'aperta') {
            if (app(GeneralSettings::class)->segnalazioni_assegnazione_porta_in_lavorazione) {
                $campi['stato'] = 'in lavorazione';
            }
        }

        $segnalazione->forceFill($campi)->save();

        try {
            static::inviaNotifiche($segnalazione, $vecchioId ? (int) $vecchioId : null, $aChi, $da);
        } catch (\Throwable $e) {
            Log::error('Errore durante l\'invio delle notifiche di assegnazione per la segnalazione ID ' . $segnalazione->id . ': ' . $e->getMessage());
        }
    }

    private static function inviaNotifiche(Segnalazione $segnalazione, ?int $vecchioId, ?User $aChi, User $da): void
    {
        $tipoPreferenza = 'ticket_assigned';

        if ($aChi !== null && $aChi->id !== $da->id) {
            $preferenza = DB::table('notification_preferences')
                ->where('user_id', $aChi->id)
                ->where('type', $tipoPreferenza)
                ->value('enabled');

            if ((bool) $preferenza) {
                Notification::send($aChi, new SegnalazioneAssegnataNotification($segnalazione, (string) $da->name));
            }
        }

        if ($vecchioId !== null && $vecchioId !== $da->id) {
            $vecchioUser = User::find($vecchioId);
            // Fase 1-bis della beta.12 (S3): la regola di chi riceve vale anche per chi lascia. Chi è sospeso, o non ha più il pannello, non riceve
            // più gli avvisi (decisione 295 (4)): niente titolo della segnalazione a chi non entra più nel programma.
            if ($vecchioUser && static::puoRicevere($vecchioUser)) {
                $preferenza = DB::table('notification_preferences')
                    ->where('user_id', $vecchioUser->id)
                    ->where('type', $tipoPreferenza)
                    ->value('enabled');

                if ((bool) $preferenza) {
                    $presaPerSe = $aChi !== null && $aChi->id === $da->id;
                    Notification::send(
                        $vecchioUser,
                        new SegnalazioneNonPiuAssegnataNotification(
                            $segnalazione,
                            (string) $da->name,
                            $aChi ? (string) $aChi->name : null,
                            $presaPerSe
                        )
                    );
                }
            }
        }
    }
}

