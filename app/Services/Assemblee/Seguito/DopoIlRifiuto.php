<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee\Seguito;

use App\Enums\Assemblee\StatoCosaDaFare;
use App\Enums\Assemblee\TipoCosaDaFare;
use App\Helpers\DateHelper;
use App\Models\Assemblee\CosaDaFareDellaDelibera;
use App\Models\Assemblee\MemoDellAssemblea;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\User;
use App\Services\Assemblee\ChiusuraDeiCompiti;
use App\Services\Assemblee\MemoriaDelCondominio;
use App\Services\Assemblee\SchedaDelCondominio;
use App\Services\RegistroModifiche\GestiDelRegistro;
use App\Services\RegistroModifiche\RegistroModifiche;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * La risposta a una delibera respinta (R7, decisione 522 (3)): **il programma non decide le conseguenze**. «Riproponi in assemblea» crea un memo con la stessa
 * origine e la frase «respinta il 15/12/2026»; «Chiudi con il motivo» chiude anche i compiti dell'origine (lo sforo della fattura) con la stessa frase. Il memo
 * del punto resta chiuso. Sull'accordo, «Chiudi l'accordo» è la strada che c'è già, nella sua scheda.
 *
 * **Il motivo è un testo della persona** (può nominare qualcuno): sta nella cosa da fare, fra i suoi dati della persona nel registro, come il motivo del memo;
 * il perché della riga sulla delibera e le frasi che chiudono i compiti non lo ripetono.
 */
final class DopoIlRifiuto
{
    /** «Riproponi in assemblea»: un memo nuovo con la stessa origine; la risposta è data. */
    public static function riproponi(CosaDaFareDellaDelibera $cosa, User $utente): MemoDellAssemblea
    {
        self::controlla($cosa);
        $d = $cosa->delibera()->with(['punto', 'assemblea.condominio'])->firstOrFail();
        $c = ContestoDellaDelibera::per($d);
        $origine = $c->origine();
        $testo = trim((string) ($d->punto?->testo ?? $d->testo)) . ' (respinta il ' . $c->giorno() . ')';

        return RegistroModifiche::durante(GestiDelRegistro::respintaRisposta($d, 'Respinta: riproposta in assemblea', null),
            function () use ($cosa, $d, $origine, $testo, $utente) {
                $dati = ['testo' => $testo];
                if ($origine !== null) {
                    $dati += ['origine_tipo' => SchedaDelCondominio::tipoDi($origine), 'origine_id' => (int) $origine->getKey()];
                }
                $memo = MemoriaDelCondominio::salva($d->assemblea->condominio, $dati, $utente);
                $cosa->update(['stato' => StatoCosaDaFare::FATTA->value, 'fatta_il' => DateHelper::oggiUtente(), 'fatta_da' => $utente->id,
                    'oggetto_tipo' => 'memo_assemblea', 'oggetto_id' => (int) $memo->id, 'nota' => 'Riproposta in assemblea']);
                ChiusuraDeiCompiti::chiudi(CoseDaFare::compito($cosa), 'Chiuso: riproposta in assemblea.');

                return $memo;
            });
    }

    /** «Chiudi con il motivo»: la risposta è data; i compiti dell'origine (lo sforo della fattura) si chiudono con la stessa frase. */
    public static function chiudi(CosaDaFareDellaDelibera $cosa, string $motivo, User $utente): CosaDaFareDellaDelibera
    {
        self::controlla($cosa);
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Scrivi perché chiudi: resta nel registro delle modifiche.']);
        }
        $d = $cosa->delibera()->with(['punto', 'assemblea'])->firstOrFail();
        $origine = ContestoDellaDelibera::per($d)->origine();
        $frase = 'Chiuso dopo la delibera respinta del ' . $d->deliberata_il?->format('d/m/Y') . '.';

        return RegistroModifiche::durante(GestiDelRegistro::respintaRisposta($d, 'Respinta: chiusa', null),
            function () use ($cosa, $motivo, $utente, $origine, $frase) {
                $cosa->update(['stato' => StatoCosaDaFare::NON_SERVE->value, 'fatta_il' => DateHelper::oggiUtente(), 'fatta_da' => $utente->id,
                    'chiusa_motivo' => $motivo]);
                ChiusuraDeiCompiti::chiudi(CoseDaFare::compito($cosa), 'Chiuso: risposta data alla delibera respinta.');
                if ($origine instanceof FatturaPassiva) {
                    ChiusuraDeiCompiti::delloSforo($origine, $frase);
                }

                return $cosa->refresh();
            });
    }

    private static function controlla(CosaDaFareDellaDelibera $cosa): void
    {
        if ($cosa->tipo !== TipoCosaDaFare::DOPO_IL_RIFIUTO || ! $cosa->eDaFare()) {
            throw ValidationException::withMessages(['cosa' => 'Questa delibera respinta ha già la sua risposta.']);
        }
    }
}
