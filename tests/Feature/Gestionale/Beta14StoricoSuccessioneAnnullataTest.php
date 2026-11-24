<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 682 (1.12.0-beta.14): lo storico di una successione annullata dice com'era l'arretrato, non lo ricalcola dai saldi di oggi.
 *
 * `StoricoTitolarita::arretratoDeiSaldi()` leggeva i saldi che l'annullamento ha tolto e rispondeva «niente da passare, il giorno della registrazione la sua
 * posizione era in pari»; `scelteDellArretrato()` diceva l'accordo «chiuso per la successione» anche dopo che l'annullamento l'ha riaperto. Ora, per un
 * passaggio annullato, la frase viene dal registro: la cifra passata con la successione (`registro.arretrato.totale`, senza di lei le successioni di prima
 * dicono il fatto senza la cifra), l'accordo riaperto, l'accessorio tornato a nome del defunto.
 *
 * Il caso: Ettore Fabbri (Neri di Via Verdi, `AccessoriScenario.php`) non ha pagato la rata 1 di € 300,00, scaduta il 30/04/2026; muore il 17/11/2026, la
 * successione di U1 passa ai figli Elena e Marco al 50 % con l'arretrato agli eredi, registrata il 20/11 e annullata il 21/11.
 *
 * Il credito (la prova 5): Venditore Ugo di `ScenariPassaggi.php` aveva pagato l'anno intero e muore il 1° maggio; la successione passa ad Anna e Bruno
 * (60 % e 40 %) € 805,48 a credito (€ 1.200,00 × 245 / 365, le cifre di `SuccessioneTest.php`, rilievo L1), e si annulla.
 *
 * Cosa NON copre: lo storico a video (`TitolaritaSheet.vue` mostra la frase com'è); la successione con l'arretrato a nome del defunto (la sua frase viene già dal
 * registro).
 */

use App\Helpers\MoneyHelper;
use App\Models\Anagrafica;
use App\Models\Gestionale\AccordoRientro;
use App\Models\Gestionale\Subentro;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AccordoCalendarioScenario.php';
require_once __DIR__ . '/Support/ScenariPassaggi.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Via Verdi con Ettore Fabbri (Neri) e la sua rata 1 di € 300,00 scaduta il 30/04/2026, non pagata. */
function b14saCaso($test): array
{
    $s = accViaVerdi($test);
    $s['persone']['Neri']->forceFill(['nome' => 'Ettore Fabbri'])->save();
    $rata = lcdRata($s, 1);
    DB::table('rate')->where('id', $rata)->update(['data_scadenza' => '2026-04-30']);
    DB::table('rate_quote')->where('rata_id', $rata)->update(['data_scadenza' => '2026-04-30']);

    return $s;
}

function b14saPersona(array $s, string $nome): Anagrafica
{
    $email = strtolower($nome) . '-b14sa-' . $s['c']->id . '@test.it';
    if (($gia = Anagrafica::where('email', $email)->first()) !== null) {
        return $gia;
    }
    $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => $email, 'indirizzo' => 'Via Verdi 1',
        'codice_fiscale' => 'B14' . strtoupper(substr($nome, 0, 3)) . str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $p->condomini()->syncWithoutDetaching([$s['c']->id]);

    return $p;
}

/** La successione di Ettore dal 17/11/2026 a Elena e Marco al 50 %, con l'arretrato agli eredi, registrata il 20/11. */
function b14saRegistra($test, array $s): Subentro
{
    [$elena, $marco] = [b14saPersona($s, 'Elena'), b14saPersona($s, 'Marco')];
    $test->travelTo('2026-11-20 10:00:00');
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']['U1']]), [
        'tipo' => 'successione', 'riga_uscente_id' => $s['righe']['Neri'], 'decorrenza' => '2026-11-17', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $elena->id, 'quota' => 50], ['anagrafica_id' => $marco->id, 'quota' => 50]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $elena->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 1', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ])->assertSessionHasNoErrors();

    return Subentro::where('immobile_id', $s['unita']['U1']->id)->latest('id')->firstOrFail();
}

function b14saAnnulla($test, array $s, Subentro $passaggio): void
{
    $test->travelTo('2026-11-21 10:00:00');
    $test->actingAs($test->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita']['U1'], $passaggio]), ['nota_annullamento' => 'Registrata per sbaglio'])
        ->assertRedirect();
}

/** Il passaggio com'è nello storico dell'unità, annullato compreso. */
function b14saStorico(array $s, Subentro $passaggio): array
{
    return collect(app(StoricoTitolarita::class)->perImmobile($s['unita']['U1']->fresh())['subentri'])->firstWhere('id', $passaggio->id);
}

it('1 — registrata e annullata: lo storico dice la cifra passata agli eredi e che l\'annullamento ha tolto le righe, non «niente da passare»', function () {
    $s = b14saCaso($this);
    $passaggio = b14saRegistra($this, $s);
    $totale = (int) $passaggio->registro['arretrato']['totale'];
    expect($totale)->toBeGreaterThan(0)
        // Registrata: la frase di sempre, con le righe dei saldi.
        ->and((string) b14saStorico($s, $passaggio)['arretrato']['frase'])->toContain('passato agli eredi per quota (art. 754 c.c.), con righe nei saldi della gestione');

    b14saAnnulla($this, $s, $passaggio);

    $frase = (string) b14saStorico($s, $passaggio)['arretrato']['frase'];
    expect($frase)->toContain(sprintf('Arretrato di Ettore Fabbri agli eredi: %s, passato con la successione; l\'annullamento ha tolto le sue righe dai saldi.', MoneyHelper::format($totale)))
        ->and($frase)->not->toContain('niente da passare');
});

it('2 — la cifra è quella della registrazione: tutte le rate aperte di Ettore al decesso, € 1.052,05, la somma delle righe date agli eredi', function () {
    $s = b14saCaso($this);
    $passaggio = b14saRegistra($this, $s);
    $righe = Subentro::conAnnullati()->findOrFail($passaggio->id)->saldi()->whereIn('id', $passaggio->saldiDellArretrato())->where('anagrafica_id', '!=', $passaggio->anagrafica_uscente_id)->sum('saldo_iniziale');
    $totale = (int) $passaggio->registro['arretrato']['totale'];
    // Il totale del registro è la somma delle righe date agli eredi (con il segno del debito: positivo).
    expect($totale)->toBe((int) $righe)
        ->and(MoneyHelper::format($totale))->toBe('€ 1.052,05');
});

it('3 — una successione di prima, senza il totale nel registro: il fatto senza la cifra', function () {
    $s = b14saCaso($this);
    $passaggio = b14saRegistra($this, $s);
    $registro = $passaggio->registro;
    unset($registro['arretrato']['totale']);
    DB::table('subentri')->where('id', $passaggio->id)->update(['registro' => json_encode($registro)]);

    b14saAnnulla($this, $s, $passaggio);

    expect((string) b14saStorico($s, $passaggio)['arretrato']['frase'])
        ->toContain('Arretrato di Ettore Fabbri agli eredi: passato con la successione; l\'annullamento ha tolto le sue righe dai saldi.');
});

it('4 — con un accordo di rientro chiuso dalla successione e riaperto dall\'annullamento: lo storico dice «si era chiuso … l\'annullamento l\'ha riaperto»', function () {
    $s = b14saCaso($this);
    $this->travelTo('2026-07-15 12:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['Neri']->id, 'data_accordo' => '2026-07-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '300,00']],
        'scadenze' => aclCalendario(['150,00', '150,00'], ['2026-07-31', '2026-08-31']), 'decadenza_rate' => 2, 'decadenza_giorni' => 15,
    ])->assertCreated();
    $accordo = AccordoRientro::latest('id')->firstOrFail();

    $passaggio = b14saRegistra($this, $s);
    expect((string) b14saStorico($s, $passaggio)['arretrato']['frase'])->toContain('L\'accordo di rientro di Ettore Fabbri del 15/07/2026 si è chiuso per la successione');

    b14saAnnulla($this, $s, $passaggio);

    $frase = (string) b14saStorico($s, $passaggio)['arretrato']['frase'];
    expect($accordo->fresh()->chiuso_il)->toBeNull()
        ->and($frase)->toContain('L\'accordo di rientro di Ettore Fabbri del 15/07/2026 si era chiuso per la successione; l\'annullamento l\'ha riaperto.')
        ->and($frase)->not->toContain('si è chiuso per la successione');
});

it('5 — il credito del defunto passato agli eredi, annullato: «Credito di … agli eredi: € 805,48, passato con la successione», senza «a credito» una seconda volta', function () {
    $s = ruScenario('prima_rata', 0);
    ruEmetti($s, '2026-12-31');
    DB::table('rate_quote')->whereIn('rata_id', DB::table('rate')->where('piano_rate_id', $s['piano']->id)->pluck('id'))->update(['importo_pagato' => DB::raw('importo'), 'stato' => 'pagata']);
    [$anna, $bruno] = [b14saPersona($s, 'Anna'), b14saPersona($s, 'Bruno')];
    $passaggio = ruRegistra($this, $s, [
        'tipo' => 'successione', 'riga_uscente_id' => $s['rigaV'], 'decorrenza' => '2026-05-01', 'quota' => 100, 'tipologia' => 'proprietario',
        'eredi' => [['anagrafica_id' => $anna->id, 'quota' => 60], ['anagrafica_id' => $bruno->id, 'quota' => 40]], 'arretrato' => 'eredi', 'erede_di_riferimento' => $anna->id,
        'copia_autentica' => false, 'estremi_titolo' => 'dichiarazione di successione n. 2', 'pertinenze' => [], 'ho_letto' => true, 'nota_cancello' => 'Dichiarazione di successione letta',
    ]);
    expect((int) $passaggio->registro['arretrato']['totale'])->toBe(-80548);

    ruAnnulla($this, $s, $passaggio)->assertRedirect();

    $frase = (string) collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $passaggio->id)['arretrato']['frase'];
    // Con il codice di prima: «Credito di Venditore Ugo agli eredi: € 805,48 a credito, passato con la successione; …».
    expect($frase)->toContain('Credito di Venditore Ugo agli eredi: € 805,48, passato con la successione; l\'annullamento ha tolto le sue righe dai saldi.')
        ->and($frase)->not->toContain('a credito, passato');
});
