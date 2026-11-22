<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

namespace App\Services\Assemblee;

use App\Enums\Assemblee\StatoAssemblea;
use App\Models\Condominio;
use App\Models\Palazzina;
use App\Models\Scala;
use App\Models\Tabella;
use Illuminate\Support\Facades\DB;

/**
 * Le guardie di cancellazione delle assemblee (R15 del progetto della tappa 2, «L'archivio»), con le frasi che l'amministratore legge al posto di un
 * errore del database. Le chiavi `restrict` di `assemblee`, `delibere` e `preventivo_versioni` fermerebbero comunque la cancellazione: qui si dice perché.
 *
 * - **Il condominio** con un'assemblea non in preparazione, con una delibera o con un preventivo fermato non si elimina. Le assemblee in preparazione se ne
 *   vanno con lui ({@see togliLePreparazioni()}), dentro il gesto dell'eliminazione: i memo tornano con il condominio, che li porta via.
 * - **La palazzina, la scala, la tabella** usate da un'assemblea o da un punto non si eliminano.
 * - **L'esercizio** con un punto che lo porta o con un preventivo fermato non si elimina (le frasi entrano fra i dati che lo trattengono).
 */
final class GuardieDiCancellazione
{
    /** La frase per il condominio, o `null` se si può eliminare. */
    public static function perIlCondominio(Condominio $condominio): ?string
    {
        if (! self::tabelleCi()) {
            return null;
        }
        $pezzi = [];
        $tenute = DB::table('assemblee')->where('condominio_id', $condominio->id)->where('stato', '!=', StatoAssemblea::IN_PREPARAZIONE->value)->count();
        if ($tenute > 0) {
            $pezzi[] = $tenute === 1 ? '1 assemblea convocata, tenuta o registrata a mano' : "{$tenute} assemblee convocate, tenute o registrate a mano";
        }
        $delibere = DB::table('delibere')->where('condominio_id', $condominio->id)->count();
        if ($delibere > 0) {
            $pezzi[] = $delibere === 1 ? '1 delibera' : "{$delibere} delibere";
        }
        $preventivi = DB::table('preventivo_versioni')->where('condominio_id', $condominio->id)->count();
        if ($preventivi > 0) {
            $pezzi[] = $preventivi === 1 ? '1 preventivo fermato per l\'assemblea' : "{$preventivi} preventivi fermati per l'assemblea";
        }

        return $pezzi === [] ? null : "«{$condominio->nome}» ha " . implode(', ', $pezzi) . ', e per questo non può essere eliminato: le assemblee e le delibere '
            . 'restano nell\'archivio delle assemblee.';
    }

    /**
     * Toglie le assemblee in preparazione del condominio (con le sedute, i punti e i loro oggetti, che scendono a cascata), dentro il gesto di chi chiama: il
     * condominio che si elimina e il condominio di prova. Un'assemblea con una delibera non è in preparazione.
     */
    public static function togliLePreparazioni(int $condominioId): int
    {
        if (! self::tabelleCi()) {
            return 0;
        }
        $ids = DB::table('assemblee')->where('condominio_id', $condominioId)->where('stato', StatoAssemblea::IN_PREPARAZIONE->value)->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }
        DB::table('assemblee_memo')->whereIn('assemblea_id', $ids)->update(['assemblea_id' => null, 'punto_id' => null]);
        DB::table('assemblee')->whereIn('id', $ids)->delete();

        return $ids->count();
    }

    /**
     * Toglie tutto ciò che il condominio di prova ha nelle assemblee (anche le riunioni a mano e le delibere): sono dati di prova, scritti da chi lo prova.
     * Dalle foglie alla radice, per le chiavi `restrict`; dentro il gesto del condominio di prova.
     */
    public static function togliTuttoDelCondominioDiProva(int $condominioId): void
    {
        if (! self::tabelleCi()) {
            return;
        }
        $assemblee = DB::table('assemblee')->where('condominio_id', $condominioId)->pluck('id');
        $delibere = DB::table('delibere')->where('condominio_id', $condominioId)->pluck('id');
        CollegamentiDelleDelibere::togliDelCondominioDiProva($condominioId);
        // Il seguito delle delibere: le cose da fare tengono le delibere.
        if (\Illuminate\Support\Facades\Schema::hasTable('delibere_cose_da_fare')) {
            DB::table('delibere_cose_da_fare')->where('condominio_id', $condominioId)->delete();
        }
        // Il verbale: le versioni e le parti tengono il verbale, il registro di nomina e revoca le delibere, il verbale la seduta e la copia sottoscritta; il
        // registro dei verbali (il punto di partenza) il condominio.
        $verbali = DB::table('assemblee_verbali')->where('condominio_id', $condominioId)->pluck('id');
        DB::table('assemblee_verbali_versioni')->whereIn('verbale_id', $verbali)->delete();
        DB::table('assemblee_verbali_parti')->whereIn('verbale_id', $verbali)->delete();
        DB::table('registro_nomine')->where('condominio_id', $condominioId)->delete();
        DB::table('assemblee_verbali')->whereIn('id', $verbali)->delete();
        DB::table('assemblee_registri_verbali')->where('condominio_id', $condominioId)->delete();
        // La sala: i voti scendono con le votazioni; le presenze, le dichiarazioni e le votazioni tengono le sedute, le deleghe e i gesti l'assemblea.
        $sedute = DB::table('assemblee_sedute')->whereIn('assemblea_id', $assemblee)->pluck('id');
        DB::table('assemblee_votazioni')->whereIn('seduta_id', $sedute)->delete();
        DB::table('assemblee_presenze')->whereIn('seduta_id', $sedute)->delete();
        DB::table('assemblee_dichiarazioni')->whereIn('seduta_id', $sedute)->delete();
        DB::table('assemblee_deleghe')->whereIn('assemblea_id', $assemblee)->delete();
        DB::table('assemblee_gesti')->whereIn('assemblea_id', $assemblee)->delete();
        DB::table('delibere')->whereIn('id', $delibere)->delete();
        DB::table('assemblee_convocati')->whereIn('assemblea_id', $assemblee)->delete();
        DB::table('assemblee_mezzi_scelti')->whereIn('assemblea_id', $assemblee)->delete();
        DB::table('preventivo_versioni')->where('condominio_id', $condominioId)->delete();
        DB::table('assemblee_memo')->where('condominio_id', $condominioId)->delete();
        DB::table('assemblee')->whereIn('id', $assemblee)->update(['riconvocata_da_id' => null]);
        DB::table('assemblee')->whereIn('id', $assemblee)->delete();
    }

    public static function perLaPalazzina(Palazzina $palazzina): ?string
    {
        return self::usataDa('palazzina_id', (int) $palazzina->id, 'La palazzina');
    }

    public static function perLaScala(Scala $scala): ?string
    {
        return self::usataDa('scala_id', (int) $scala->id, 'La scala');
    }

    public static function perLaTabella(Tabella $tabella): ?string
    {
        return self::usataDa('tabella_id', (int) $tabella->id, 'La tabella');
    }

    /** Le frasi per l'esercizio, accanto agli altri dati che lo trattengono. @return list<string> */
    public static function perLEsercizio(int $esercizioId): array
    {
        if (! self::tabelleCi()) {
            return [];
        }
        $frasi = [];
        $punti = DB::table('assemblee_punti')->where('esercizio_id', $esercizioId)->count();
        if ($punti > 0) {
            $frasi[] = ($punti === 1 ? '1 punto dell\'ordine del giorno di un\'assemblea' : "{$punti} punti dell'ordine del giorno delle assemblee")
                . ' (il rendiconto o il preventivo di questo esercizio)';
        }
        $preventivi = DB::table('preventivo_versioni')->where('esercizio_id', $esercizioId)->count();
        if ($preventivi > 0) {
            $frasi[] = $preventivi === 1 ? '1 preventivo fermato per l\'assemblea' : "{$preventivi} preventivi fermati per l'assemblea";
        }

        return $frasi;
    }

    private static function usataDa(string $colonna, int $id, string $cosa): ?string
    {
        if (! self::tabelleCi()) {
            return null;
        }
        $assemblee = DB::table('assemblee')->where($colonna, $id)->count();
        $punti = DB::table('assemblee_punti')->where($colonna, $id)->count();
        if ($assemblee + $punti === 0) {
            return null;
        }
        $pezzi = [];
        if ($assemblee > 0) {
            $pezzi[] = $assemblee === 1 ? 'da 1 assemblea' : "da {$assemblee} assemblee";
        }
        if ($punti > 0) {
            $pezzi[] = $punti === 1 ? 'da 1 punto dell\'ordine del giorno' : "da {$punti} punti dell'ordine del giorno";
        }

        return "{$cosa} è usata " . implode(' e ', $pezzi) . ', e per questo non può essere eliminata: cambiala prima nelle assemblee.';
    }

    /** Prima della migrazione le tabelle non ci sono (un aggiornamento a metà): nessuna guardia. */
    private static function tabelleCi(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasTable('assemblee') && \Illuminate\Support\Facades\Schema::hasTable('delibere');
    }
}
