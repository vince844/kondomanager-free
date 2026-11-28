<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * La coppia di una fine «Scrivi» corretta dal Wallet con i due saldi legati alla fine: i passaggi dopo leggono la coppia corretta.
 *
 * La storia (la sonda B1 della 1.11.0-beta.56): il cambio Ines → Sara «Scrivi» il 1/7, la fine di Sara «Scrivi» il 1/10 con l'«Affitto antenna»
 * € 500,00 (lo scenario di `Beta56CoppiaConLEntrataTest`). Una versione di prima aveva scritto la coppia della fine sbagliata, Sara − 1512 e Ugo + 1512;
 * l'amministratore la corregge dal Wallet con i due saldi legati alla fine, Sara − 3781 e Ugo + 3781: il Wallet dice Sara 5293, Ugo 5293, la cifra giusta.
 * `FermataDopoScrivi::coppia` leggeva solo le righe scritte dalla fine (`subentro_id`), non quelle legate (`passaggio_id`): la vendita di Ugo del 1/11
 * diceva «€ 10,03 restano a carico di Ugo» (1512 × 61/92 = 1002,52 → 1003) e lo scriveva nel registro; l'inizio di Teo del 1/11 scriveva 1003.
 * Con le righe legate: 5293 × 61/92 = 3509,49 → 3509 (€ 35,09).
 *
 * Cosa NON copre: la pagina Vue; MySQL.
 */

use App\Models\Gestionale\Subentro;
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

/**
 * Lo scenario, tutto dalle pagine: l'«Affitto antenna» dall'importo dato (con `null` nessuna antenna), il piano con le voci e, se data, la competenza dell'antenna, approvato,
 * generato, le rate fino al 30/6 a giornale.
 */
function ccwScenario($test, ?string $importo = '500,00', ?array $competenza = null): array
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

/** Il cambio d'inquilino dalla rotta vera, con la quota di chi esce e la scelta, che l'anteprima chiede. */
function ccwCambio($test, array &$s, string $esce, string $entra, string $il, string $scelta = 'scrivi'): Subentro
{
    if (! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')] + fclModulo($s, $s['persone'][$entra], $il, [], $esce);
    expect((bool) (fclAnteprima($test, $s, $dati)['rate']['scelta_del_conguaglio'] ?? false))->toBeTrue();

    return fclRegistra($test, $s, $dati + ['conguaglio' => $scelta])->fresh();
}

/** La fine di una locazione senza chi entra dalla rotta vera, con la scelta, che l'anteprima chiede: [il passaggio, le frasi del pannello]. */
function ccwFine($test, array $s, string $esce, string $il, string $scelta = 'scrivi'): array
{
    $dati = ['quota' => (float) DB::table('anagrafica_immobile')->where('id', fclRiga($s, $esce))->value('quota')] + fclModulo($s, null, $il, [], $esce);
    $a = fclAnteprima($test, $s, $dati);
    expect((bool) ($a['rate']['scelta_del_conguaglio'] ?? false))->toBeTrue();

    return [fclRegistra($test, $s, $dati + ['conguaglio' => $scelta])->fresh(), fclFrasi($a)];
}

/** La vendita della quota del proprietario dalla rotta vera: [il passaggio, le frasi del pannello]. */
function ccwVendita($test, array &$s, string $esce, string $entra, string $il): array
{
    if (! isset($s['persone'][$entra])) {
        fclPersona($s, $entra);
    }
    $dati = ['tipo' => 'vendita', 'tipologia' => 'proprietario', 'riga_uscente_id' => fclRiga($s, $esce, 'proprietario'), 'anagrafica_entrante_id' => $s['persone'][$entra]->id,
        'decorrenza' => $il, 'quota' => 100, 'copia_autentica' => false, 'estremi_titolo' => 'atto notaio Verdi, rep. 900', 'pertinenze' => []];
    $frasi = fclFrasi(fclAnteprima($test, $s, $dati));

    return [fclRegistra($test, $s, $dati)->fresh(), $frasi];
}

/** Le righe di un passaggio in saldi (la sua coppia), per nome. */
function ccwRighe(array $s, Subentro $passaggio): array
{
    $nomi = fclNomi($s);

    return DB::table('saldi')->where('subentro_id', $passaggio->id)->orderBy('id')->get(['anagrafica_id', 'saldo_iniziale'])
        ->mapWithKeys(fn ($r) => [$nomi[(int) $r->anagrafica_id] => (int) $r->saldo_iniziale])->all();
}

/** Tutte le righe dei saldi dell'unità sulla gestione, per persona (le coppie, e i saldi legati scritti dal Wallet). */
function ccwWallet(array $s): array
{
    $nomi = fclNomi($s);
    $out = [];
    foreach (DB::table('saldi')->where('gestione_id', $s['g']->id)->where('immobile_id', $s['unita']->id)->get(['anagrafica_id', 'saldo_iniziale']) as $r) {
        $out[$nomi[(int) $r->anagrafica_id]] = ($out[$nomi[(int) $r->anagrafica_id]] ?? 0) + (int) $r->saldo_iniziale;
    }
    ksort($out);

    return $out;
}

/** Il saldo del Wallet legato a un passaggio, dal modale (POST di `saldi.store` con `passaggio_id`). */
function ccwPosta($test, array $s, string $chi, int $cents, Subentro $passaggio): void
{
    $test->actingAs($test->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => $s['persone'][$chi]->id, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => $cents, 'passaggio_id' => $passaggio->id,
    ])->assertSessionHasNoErrors();
}

/** La sezione di un conduttore nel prospetto: [totale, con il conguaglio, da rimborsare]; i giorni senza conduttore: [totale, pagata da]. */
function ccwProspetto(array $s): array
{
    $p = app(\App\Services\Riparto\ProspettoOneriAccessori::class)->calcola($s['unita']->fresh(), $s['e']);
    $out = [];
    foreach ($p['conduttori'] as $c) {
        $out[$c['nome']] = [(int) $c['totale'], (int) ($c['con_il_conguaglio'] ?? 0), (int) $c['da_rimborsare']];
    }
    ksort($out);
    $out['senza'] = [(int) $p['senza_conduttore']['totale'], collect($p['senza_conduttore']['voci'])->pluck('pagato_da')->filter()->unique()->values()->all()];

    return $out;
}


/** La storia fino ai due saldi legati alla fine: [lo scenario, la fine]. */
function ccwFineCorretta($test): array
{
    $s = ccwScenario($test);
    ccwCambio($test, $s, 'Ines', 'Sara', '2026-07-01');
    [$fine] = ccwFine($test, $s, 'Sara', '2026-10-01');
    DB::table('saldi')->where('subentro_id', $fine->id)->where('anagrafica_id', $s['persone']['Sara']->id)->update(['saldo_iniziale' => -1512]);
    DB::table('saldi')->where('subentro_id', $fine->id)->where('anagrafica_id', $s['persone']['Ugo']->id)->update(['saldo_iniziale' => 1512]);
    ccwPosta($test, $s, 'Sara', -3781, $fine);
    ccwPosta($test, $s, 'Ugo', 3781, $fine);
    expect(ccwWallet($s))->toBe(['Ines' => -10586, 'Sara' => 5293, 'Ugo' => 5293]);

    return [$s, $fine];
}

it('la vendita dopo i due saldi legati alla fine: il pannello dice € 35,09 a carico di Ugo, e il registro scrive la coppia 5293 e la fermata 3509', function () {
    [$s] = ccwFineCorretta($this);

    [$vendita, $frasi] = ccwVendita($this, $s, 'Ugo', 'Zeta', '2026-11-01');

    expect($frasi)->toContain('dei giorni dopo la sua uscita Ugo ha preso € 52,93 sulla gestione Ordinaria 2026 (Interno 1).')
        ->and($frasi)->toContain('il programma non la sposta: € 35,09 restano a carico di Ugo')
        ->and(collect($vendita->registro['fermata_dopo_scrivi'])->map(fn ($f) => [(int) $f['coppia'], (int) $f['cifra']])->all())->toBe([[5293, 3509]]);
});

it('l\'inizio di Teo dopo i due saldi legati alla fine: i giorni del conduttore scrivono 3509, pagati da Ugo', function () {
    [$s] = ccwFineCorretta($this);
    fclPersona($s, 'Teo');

    $inizio = fclRegistra($this, $s, ['tipo' => 'inizio_locazione', 'tipologia' => 'inquilino', 'anagrafica_entrante_id' => $s['persone']['Teo']->id, 'decorrenza' => '2026-11-01', 'quota' => 100,
        'copia_autentica' => false, 'data_fine_locazione' => '2030-06-30', 'regime_contratto' => 'abitativo', 'pertinenze' => []])->fresh();

    expect(collect($inizio->registro['giorni_del_conduttore'])->map(fn ($g) => [(int) $g['cifra'], $g['pagato_da']])->all())->toBe([[3509, ['Ugo']]]);
});

it('il prospetto degli oneri dopo i due saldi legati alla fine dice la stessa coppia del Wallet: i giorni senza conduttore € 52,93 pagati da Ugo', function () {
    [$s] = ccwFineCorretta($this);

    $p = ccwProspetto($s);

    expect($p['senza'])->toBe([5293, ['Ugo']])
        ->and($p['Sara'][1] ?? null)->toBe(5293);
});
