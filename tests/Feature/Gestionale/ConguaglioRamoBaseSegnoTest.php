<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il ramo base del conguaglio (un piano senza righe di riparto congelate, come quelli generati prima della 1.11.0-beta.29) con il segno.
 *
 * Su una quota arrivata a chi esce con un passaggio di prima (le quote emesse a Ugo e le bozze passate a Zeta con la vendita del 1/7), la
 * parte di chi esce è ciò che gli era arrivato meno ciò che passa ora. `ConguaglioPassaggio` la calcolava con `max(0, …)` senza il segno: con
 * un netto negativo (l'entrata più grande della spesa) la parte di chi esce, un credito, diventava zero. La stessa forma con il segno del ramo
 * per conto (Coda 368, `Beta56CoppiaConLEntrataTest`).
 *
 * I CONTI A MANO (centesimi, 2026 di 365 giorni): «Spese generali» € 1.200,00, «Affitto antenna» € 3.000,00 di entrata, 70 % al proprietario:
 * 84000 − 210000 = − 126000, dodici quote da − 10500. Due gruppi di sei (emesse a Ugo, passate a Zeta il 1/7), − 63000 ciascuno. Per gruppo,
 * all'acquisto (184 giorni dal 1/7) − 63000 × 184/365 = − 31758,90 → − 31759; ora (92 giorni dal 1/10) − 63000 × 92/365 = − 15879,45
 * → − 15879, a Mario (− 31758 sui due gruppi). La parte di Zeta, per gruppo, è ciò che le era arrivato meno ciò che passa: − 31759 − (− 15879) =
 * − 15880, un credito; − 31760 sui due (la somma per acquisto di DV1 vale per le quote positive, e qui non entra). Il programma, prima: 0.
 *
 * La gravità: la cifra «uscente» non entra nel Wallet (la coppia si scrive da «entrante») né nelle frasi del ramo base; si legge nei dati
 * dell'anteprima (le quote e la somma per gestione). Cosa NON copre: la pagina Vue; MySQL.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/Support/ScenariFineLocazione.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware([\App\Http\Middleware\HandleInertiaRequests::class]);
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
});

function crbScenario($test, ?string $importo = '500,00', ?array $competenza = null): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['inquilini' => [['Ines', 100]]]);
    $pc = \App\Models\Gestionale\PianoConto::where('gestione_id', $s['g']->id)->firstOrFail();
    $tabella = \App\Models\Tabella::where('condominio_id', $s['c']->id)->where('nome', 'Proprietà')->firstOrFail();
    $spese = \App\Models\Gestionale\Conto::where('nome', 'Spese generali')->where('piano_conto_id', $pc->id)->firstOrFail();
    $capitoli = [['id' => $spese->id, 'importo' => '1.200,00', 'note' => '']];
    $competenze = [];
    if ($importo !== null) {
        $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.piani-conti.conti.store', [$s['c'], $s['e'], $pc]), [
            'nome' => 'Affitto antenna', 'codice' => '', 'default_fornitore_id' => null, 'tipo_spesa' => 'standard', 'tipo' => 'entrata', 'importo' => $importo, 'descrizione' => '', 'note' => '',
            'parent_id' => null, 'tabella_millesimale_id' => $tabella->id, 'percentuale_proprietario' => 70, 'percentuale_inquilino' => 30, 'percentuale_usufruttuario' => 0,
            'isCapitolo' => false, 'isSottoConto' => false, 'richiedeGiaVersato' => false,
        ])->assertSessionHasNoErrors();
        $antenna = \App\Models\Gestionale\Conto::where('nome', 'Affitto antenna')->where('piano_conto_id', $pc->id)->firstOrFail();
        $capitoli[] = ['id' => $antenna->id, 'importo' => $importo, 'note' => ''];
        if ($competenza !== null) {
            $competenze[] = ['conto_id' => $antenna->id, 'tratti' => [['dal' => $competenza[0], 'al' => $competenza[1]]]];
        }
    }
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $test->actingAs($test->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'nome' => 'Preventivo 2026', 'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 12,
        'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05', 'genera_subito' => false, 'recurrence_enabled' => false, 'capitoli_ids' => [],
        'capitoli_config' => $capitoli, 'competenze_capitoli' => $competenze,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $s['piano'] = \App\Models\Gestionale\PianoRate::where('gestione_id', $s['g']->id)->latest('id')->firstOrFail();
    $test->actingAs($test->user)->from('/piano')->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $s['piano']]), [
        'approvato' => true, 'data_delibera_assemblea' => '2025-12-15',
    ])->assertSessionHasNoErrors();
    $s['piano'] = $s['piano']->fresh();
    fclGenera($s);
    fclEmetti($test, $s, '2026-06-30');
    expect(fclEmesse($s))->toBe(6);

    return $s;
}

/** La vendita della quota del proprietario dalla rotta vera: [il passaggio, le frasi del pannello]. */
function crbVendita($test, array &$s, string $esce, string $entra, string $il): array
{
    if (! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, $esce, 'proprietario'), 'anagrafica_entrante_id' => $s['persone'][$entra]->id,
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []];
    $frasi = fclFrasi(fclAnteprima($test, $s, $dati));

    return [fclRegistra($test, $s, $dati)->fresh(), $frasi];
}


it('due vendite su un piano senza righe congelate, con l\'entrata più grande della spesa: la parte di Zeta che esce è il suo credito, non zero', function () {
    $s = crbScenario($this, '3.000,00');
    DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->delete();
    crbVendita($this, $s, 'Ugo', 'Zeta', '2026-07-01');
    fclPersona($s, 'Mario');
    $dati = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, 'Zeta', 'proprietario'), 'anagrafica_entrante_id' => $s['persone']['Mario']->id,
        'decorrenza' => '2026-10-01', 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 901', 'pertinenze' => []];

    $c = fclAnteprima($this, $s, $dati)['rate']['conguaglio'];
    $quote = collect($c['quote']);

    // Il ramo base: nessun dettaglio per conto.
    expect($quote->pluck('per_capitolo')->filter()->all())->toBe([])
        ->and($quote->sum('quota_pura'))->toBe(-126000)
        ->and($quote->sum('entrante'))->toBe(-31758)
        ->and($quote->sum('uscente'))->toBe(-31760);
    // Le parti hanno il segno della voce: nessuna quota con una parte di chi esce positiva.
    expect($quote->filter(fn ($q) => (int) $q['uscente'] > 0)->count())->toBe(0);
});
