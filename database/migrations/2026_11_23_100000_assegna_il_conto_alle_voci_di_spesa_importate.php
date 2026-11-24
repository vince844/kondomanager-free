<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Le voci di spesa importate senza il loro conto di partita doppia (Coda 113, decisione 707 (3)).
 *
 * Dalla 1.11.0-beta.4 l'importazione del bilancio consuntivo scriveva il capitolo e le sue voci senza `conto_contabile_id`: la regolazione
 * immediata le nascondeva e la prima fattura passiva su una di loro si fermava su «Integrità compromessa». Dalla 1.12.0-beta.14
 * l'importazione dà il conto; questa migrazione lo dà a quelle che ci sono già, come lo darebbe il salvataggio a mano
 * (`CreazioneDellaVoce::contoContabile()`): «Costi per Servizi» (ruolo `costi_servizi`), o «Compensi Professionisti»
 * (`compensi_professionisti`) per una voce di tipo «professionista».
 *
 * Solo le voci di spesa (`Conto::TIPI_DI_SPESA`) con `conto_contabile_id` nullo: una voce che il suo conto lo ha già non si sposta, e le voci
 * di entrata non si toccano (il loro mastro lo decide l'amministratore dal controllo del piano dei conti). Un condominio per volta: se il
 * mastro che serve non c'è (un condominio senza il piano dei conti di sistema) le sue voci restano com'erano e lo dice il registro
 * dell'applicazione; se un condominio dà un errore, va nel registro e l'aggiornamento prosegue con gli altri. In ogni caso la prima fattura
 * o il salvataggio a mano di quelle voci ricrea il mastro mancante.
 *
 * Solo dati, nessuna struttura. Rieseguibile: la seconda volta non trova voci senza conto e non fa niente. Nel dataset di
 * `UpgradeMigrationsRerunTest`. Tornando indietro le voci restano ancorate.
 */
return new class extends Migration
{
    private const RUOLI = [
        'professionista' => 'compensi_professionisti',
        'standard' => 'costi_servizi',
    ];

    private const NOMI = [
        'compensi_professionisti' => 'Compensi Professionisti',
        'costi_servizi' => 'Costi per Servizi',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('conti') || ! Schema::hasTable('piani_conti') || ! Schema::hasTable('conti_contabili')
            || ! Schema::hasColumns('conti', ['conto_contabile_id', 'tipo', 'tipo_spesa', 'piano_conto_id'])) {
            return;
        }

        $senzaConto = fn () => DB::table('conti')
            ->join('piani_conti', 'conti.piano_conto_id', '=', 'piani_conti.id')
            ->whereNull('conti.conto_contabile_id')
            ->whereIn('conti.tipo', Conto::TIPI_DI_SPESA);

        $condomini = $senzaConto()->distinct()->orderBy('piani_conti.condominio_id')->pluck('piani_conti.condominio_id');

        foreach ($condomini as $condominioId) {
            $condominioId = (int) $condominioId;
            try {
                foreach ([true, false] as $professionisti) {
                    $ruolo = self::RUOLI[$professionisti ? 'professionista' : 'standard'];
                    $voci = $senzaConto()->where('piani_conti.condominio_id', $condominioId)
                        ->where(fn ($q) => $professionisti
                            ? $q->where('conti.tipo_spesa', 'professionista')
                            : $q->where('conti.tipo_spesa', '!=', 'professionista')->orWhereNull('conti.tipo_spesa'));
                    if (! $voci->exists()) {
                        continue;
                    }
                    $mastro = ContoContabile::where('condominio_id', $condominioId)->where('ruolo', $ruolo)->value('id');
                    if ($mastro === null) {
                        Log::warning('Migrazione delle voci di spesa importate senza conto: nel condominio '.$condominioId.' manca il mastro «'
                            .self::NOMI[$ruolo].'», le sue voci di spesa senza conto restano com\'erano.');

                        continue;
                    }
                    $senzaConto()->where('piani_conti.condominio_id', $condominioId)
                        ->where(fn ($q) => $professionisti
                            ? $q->where('conti.tipo_spesa', 'professionista')
                            : $q->where('conti.tipo_spesa', '!=', 'professionista')->orWhereNull('conti.tipo_spesa'))
                        ->update(['conti.conto_contabile_id' => (int) $mastro]);
                }
            } catch (\Throwable $e) {
                Log::error('Migrazione delle voci di spesa importate senza conto: errore sul condominio '.$condominioId.', le sue voci restano com\'erano: '.$e->getMessage());
            }
        }
    }

    public function down(): void
    {
        // Niente: le voci restano ancorate al loro mastro.
    }
};
