<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Support\Comunicazioni;

use App\Services\RegistroModifiche\Gesto;
use App\Services\RegistroModifiche\RegistroModifiche;
use App\Enums\Permission;
use App\Events\Notifiche\DestinatariDaAvvisare;
use App\Models\Anagrafica;
use App\Models\Comunicazione;
use App\Models\User;
use App\Notifications\Comunicazioni\ComunicazioneProgrammataPubblicataNotification;
use App\Services\Notifiche\DestinatariNotifica;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class PubblicazioneProgrammata
{
    public static function fuso(): string
    {
        return (string) config('app.user_timezone', 'Europe/Rome');
    }

    public static function daModulo(?string $valore): ?Carbon
    {
        if (empty($valore) || ! is_string($valore)) {
            return null;
        }

        if (! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $valore)) {
            return null;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d\TH:i', $valore, static::fuso());
            if (! $parsed) {
                return null;
            }

            return $parsed->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function perModulo(?DateTimeInterface $utc): ?string
    {
        if ($utc === null) {
            return null;
        }

        return Carbon::instance($utc)->setTimezone(static::fuso())->format('Y-m-d\TH:i');
    }

    public static function puoProgrammare(User $user, ?Comunicazione $comunicazione = null): bool
    {
        if (! $user->hasPermissionTo(Permission::ACCESS_ADMIN_PANEL->value) || ! $user->hasPermissionTo(Permission::PUBLISH_COMUNICAZIONI->value)) {
            return false;
        }

        if ($comunicazione !== null && ! (bool) $comunicazione->is_approved) {
            return false;
        }

        return true;
    }

    public static function pianificatoreAttivo(): bool
    {
        return Cache::has('system_cron_heartbeat');
    }

    public static function pubblica(Comunicazione $comunicazione): bool
    {
        $adesso = now();
        $oraScelta = $comunicazione->programmata_per;
        $programmataDaId = $comunicazione->programmata_da;

        // Il registro delle modifiche (la bozza, scena 2): «Pubblicata in bacheca», firmata dal contesto (dal pianificatore «Il programma»), nella stessa
        // transazione della pubblicazione.
        $righe = DB::transaction(function () use ($comunicazione, $adesso, $oraScelta, $programmataDaId) {
            $righe = DB::table('comunicazioni')
                ->where('id', $comunicazione->id)
                ->where('is_published', 0)
                ->where('is_approved', 1)
                ->whereNotNull('programmata_per')
                ->where('programmata_per', '<=', $adesso)
                ->update([
                    'is_published'    => 1,
                    'pubblicata_il'   => $adesso,
                    'programmata_per' => null,
                    'programmata_da'  => null,
                ]);
            if ($righe > 0) {
                // «Avvisa i condòmini» di una delibera: pubblicata adesso, la sua cosa da fare è fatta e il compito si chiude.
                \App\Services\Assemblee\Seguito\AvvisoAiCondomini::dopoLaPubblicazione($comunicazione->newQuery()->findOrFail($comunicazione->id));
                $chi = $programmataDaId === null ? null : \App\Models\User::query()->whereKey($programmataDaId)->value('name');
                $quando = $oraScelta === null ? null : \App\Services\RegistroModifiche\FormatoDelValore::testo($oraScelta, 'data_ora');
                RegistroModifiche::gesto(Gesto::sulModello($comunicazione)->riepilogo('Pubblicata in bacheca'
                    . ($chi !== null ? '. Programmata da ' . $chi . ($quando !== null ? ' per il ' . str_replace(' ', ' alle ', $quando) : '') : '')));
            }

            return $righe;
        });

        if ($righe === 0) {
            return false;
        }

        try {
            $comunicazioneRiletta = Comunicazione::findOrFail($comunicazione->id);

            $destinatari = app(DestinatariNotifica::class)->perModello($comunicazioneRiletta);
            $dellAutore = $comunicazioneRiletta->created_by === null
                ? collect()
                : Anagrafica::where('user_id', $comunicazioneRiletta->created_by)->pluck('id');

            $ids = $destinatari->diff($dellAutore)->values()->all();
            if ($ids !== []) {
                DestinatariDaAvvisare::dispatch($comunicazioneRiletta, $ids, 'nuovo');
            }

            if ($programmataDaId !== null && $oraScelta !== null) {
                $utenteProgrammatore = User::find($programmataDaId);
                if ($utenteProgrammatore && $utenteProgrammatore->hasPermissionTo(Permission::PUBLISH_COMUNICAZIONI->value)) {
                    $preferenzaAccesa = DB::table('notification_preferences')
                        ->where('user_id', $utenteProgrammatore->id)
                        ->where('type', 'scheduled_communication_published')
                        ->value('enabled');

                    if ((bool) $preferenzaAccesa) {
                        Notification::send($utenteProgrammatore, new ComunicazioneProgrammataPubblicataNotification(
                            $comunicazioneRiletta,
                            $oraScelta,
                            $adesso
                        ));
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Errore durante l\'invio degli avvisi per la comunicazione pubblicata ID ' . $comunicazione->id . ': ' . $e->getMessage());
        }

        return true;
    }

    public static function pubblicaScadute(): int
    {
        $comunicazioni = Comunicazione::query()
            ->where('is_published', false)
            ->where('is_approved', true)
            ->whereNotNull('programmata_per')
            ->where('programmata_per', '<=', now())
            ->orderBy('programmata_per')
            ->orderBy('id')
            ->get();

        $uscite = 0;
        foreach ($comunicazioni as $comunicazione) {
            if (static::pubblica($comunicazione)) {
                $uscite++;
            }
        }

        return $uscite;
    }
}
