<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Gli aiuti delle prove degli addebiti a una persona sola, su Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`): l'amministratore, la
 * registrazione dalla rotta della finestra, Via Verdi con U3 senza titolare a gennaio.
 *
 * NON contiene nessun it() o test — solo funzioni di supporto.
 */

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__ . '/../Riparto/ConsuntivoPerPersonaPosizioneScenario.php';

/** L'amministratore del pannello, in `$test->user`. */
function adbAdmin($test): void
{
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $test->user = User::factory()->create();
    $test->user->assignRole($ruolo);
}

/**
 * Registra gli addebiti dalla rotta della finestra, nella gestione e nell'esercizio dati (di serie quelli del caso), con la data dell'addebito data (di
 * serie quella che sceglie il server). Ogni voce: `chi`, `unita`, `euro` (la stringa che scrive l'amministratore) e il resto come lo manda la finestra.
 */
function adbRegistra($test, array $s, array $voci, ?int $gestioneId = null, ?int $esercizioId = null, ?string $data = null)
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.addebiti.store', [$s['c']]), [
        'gestione_id' => $gestioneId ?? $s['g']->id,
        'esercizio_id' => $esercizioId ?? $s['e']->id,
    ] + ($data !== null ? ['data' => $data] : []) + [
        'voci' => array_map(fn (array $v) => array_merge([
            'anagrafica_id' => $s['persone'][$v['chi']]->id, 'immobile_id' => $s['unita'][$v['unita']]->id, 'importo' => $v['euro'],
            'causale' => $v['causale'] ?? 'Addebito di prova', 'scadenza' => $v['scadenza'] ?? substr((string) now(), 0, 10),
        ], array_diff_key($v, array_flip(['chi', 'unita', 'euro']))), $voci),
    ]);
}

/** L'esercizio 2027 di Via Verdi, aperto, con la sua «Ordinaria 2027»; il 2026 nello stato dato. */
function adbAnnoDopo(array $s, string $stato2026 = 'aperto'): array
{
    $e27 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g27 = \App\Models\Gestione::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Ordinaria 2027', 'tipo' => 'ordinaria', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31']);
    legaAEsercizio($e27, $g27->id);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => $stato2026]);

    return [$e27, $g27];
}

/** La quota dell'addebito di una rata del piano degli addebiti. */
function adbQuota(int $rataId): \App\Models\Gestionale\RataQuote
{
    return \App\Models\Gestionale\RataQuote::where('rata_id', $rataId)->firstOrFail();
}

/**
 * Via Verdi con U3 senza titolare per 31 giorni: Verdi, proprietario dal 2020, esce il 30/11/2026 e nessuno subentra (il compratore non è ancora
 * comunicato). Il piano si genera di nuovo con la presa d'atto delle quote non assegnate, e i 31 giorni di dicembre di U3 restano fuori dalle rate:
 * Pulizie € 3.000,00 × 200/1000 × 31/365 = € 50,96, Manutenzioni € 1.000,00 × 200/1000 × 31/365 = € 16,99.
 */
function adbVerdiFinoANovembre($test, array $opz = []): array
{
    $s = cpcViaVerdi($test, $opz);
    DB::table('anagrafica_immobile')->where('id', $s['righe']['Verdi'])->update(['data_fine' => '2026-11-30']);
    $s['piano']->rate()->delete();
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano'], forzaApplicazioneSaldi: true, accettaScoperti: true,
        notaScoperti: 'U3 senza titolare a dicembre, da addebitare', accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    $s['piano'] = $s['piano']->fresh();

    return $s;
}

/** Le quote non assegnate del piano del caso, per voce («Pulizie», «Manutenzioni»). */
function adbQuoteNonAssegnate(array $s): array
{
    return collect($s['piano']->fresh()->quote_non_assegnate['voci'] ?? [])->keyBy('conto_nome')->all();
}
