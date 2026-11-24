<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * 1.12.0-beta.14, decisione 705: le catene dei passaggi registrati insieme (§3 di `tappa7_progetto.md`), da un capo all'altro.
 *
 * - **Il passaggio → i conguagli → l'estratto conto e l'incasso.** Ogni persona che esce ha la sua coppia in saldi con il suo passaggio; chi entra ne ha
 *   due. L'estratto conto di Luca dice € 40,98 a debito in due righe; la rata di dicembre, emessa dalla rotta, ha due quote sue (una da ciascuna) e il
 *   suo incasso di € 80,00 dalla rotta le paga tutte e due. Dopo, lo storico dice che l'insieme non si annulla, con il motivo che dà l'azione.
 * - **Il passaggio → il piano rate (la issue 58).** Il pregresso intestato all'unità all'1/1 va a chi era titolare quel giorno, Cecilia e Marta per metà
 *   (decisione 674); la finestra «A chi va» dice l'insieme in una riga e offre Luca dal giorno dell'atto; il piano creato dalla rotta con la regola dà
 *   € 90,00 di pregresso a ciascuna e niente a Luca, che paga le rate dal giorno dell'atto.
 *
 * Il caso è quello della bozza approvata (`docs/design/beta14_piu_venditori_bozza.html`): Cecilia Angeli e Marta Acciaioli, al 50 % dell'Int. 1, vendono
 * a Luca Toscani con l'atto del 16 novembre 2026; il «Preventivo 2026» da € 960,00 in dodici rate da € 80,00 (€ 40,00 a testa), emesse fino a novembre.
 *
 * La registrazione passa dalla rotta, con `righe_uscenti`, come la fa la pagina (il pannello, poi la registrazione con la sua impronta).
 *
 * **Che cosa non copre.** Le catene del pannello, del registro, delle teste e della fermata sono nelle prove di `Beta14PiuVenditoriTest`; l'annullamento
 * è in `Beta14PiuVenditoriAnnullamentoTest`. MySQL.
 */

use App\Actions\PianoRate\GeneratePianoRateAction;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Gestione;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Subentro;
use App\Models\Immobile;
use App\Models\Saldo;
use App\Models\Tabella;
use App\Models\User;
use App\Services\Gestionale\EstrattoContoDellaPersona;
use App\Services\Subentro\StoricoTitolarita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/GestionaleTestHelpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    foreach (['Visualizza anagrafiche', 'Crea anagrafiche', 'Modifica anagrafiche', 'Elimina anagrafiche'] as $nome) {
        $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => $nome, 'guard_name' => 'web']));
    }
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->travelTo('2026-11-23 10:00:00');
});

/*
|--------------------------------------------------------------------------
| Helper (prefisso `pvca`: Pest carica tutti i file nello stesso processo)
|--------------------------------------------------------------------------
*/

/**
 * L'Int. 1 di Cecilia Angeli e Marta Acciaioli al 50 % dal 3 marzo 2019, Luca Toscani del condominio; con `$conPiano` il «Preventivo 2026» generato a
 * gennaio con loro due.
 *
 * @return array{c: Condominio, e: Esercizio, g: Gestione, unita: Immobile, cecilia: Anagrafica, marta: Anagrafica, luca: Anagrafica, rigaC: int, rigaM: int, piano: ?PianoRate}
 */
function pvcaScenario(bool $conPiano = true): array
{
    static $seq = 0;
    $seq++;
    $c = Condominio::factory()->create();
    $e = Esercizio::factory()->create(['condominio_id' => $c->id, 'nome' => 'Esercizio 2026', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31', 'stato' => 'aperto']);
    $g = Gestione::factory()->create(['condominio_id' => $c->id, 'nome' => 'Ordinaria 2026', 'tipo' => 'ordinaria', 'data_inizio' => '2026-01-01', 'data_fine' => '2026-12-31']);
    legaAEsercizio($e, $g->id);
    $pc = PianoConto::create(['condominio_id' => $c->id, 'gestione_id' => $g->id, 'nome' => 'PC']);
    $conto = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Spese generali', 'tipo' => 'spesa', 'natura_spesa' => 'ordinaria', 'importo' => 96000]);
    $tabella = Tabella::create(['condominio_id' => $c->id, 'nome' => 'Proprietà', 'tipo' => 'standard', 'quota' => 'millesimi', 'attiva' => true]);
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $conto->id, 'tabella_id' => $tabella->id, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);
    $unita = Immobile::create(['condominio_id' => $c->id, 'tipo' => 'appartamento', 'codice_immobile' => "PVCA-{$seq}", 'nome' => 'Interno 1', 'interno' => '1']);
    DB::table('quote_tabella')->insert(['tabella_id' => $tabella->id, 'immobile_id' => $unita->id, 'valore' => 1000.0, 'created_at' => now(), 'updated_at' => now()]);

    $persona = function (string $nome, string $sigla) use ($c, $seq) {
        $p = Anagrafica::forceCreate(['nome' => $nome, 'email' => "pvca-{$sigla}{$seq}@test.it", 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'PVCA' . strtoupper($sigla) . 'PERSON' . str_pad((string) $seq, 5, '0', STR_PAD_LEFT)]);
        $p->condomini()->syncWithoutDetaching([$c->id]);

        return $p;
    };
    $cecilia = $persona('Cecilia Angeli', 'c');
    $marta = $persona('Marta Acciaioli', 'm');
    $luca = $persona('Luca Toscani', 'l');
    $riga = fn (Anagrafica $p) => DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $p->id, 'immobile_id' => $unita->id, 'tipologia' => 'proprietario',
        'quota' => 50, 'attivo' => true, 'data_inizio' => '2019-03-03', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $rigaC = $riga($cecilia);
    $rigaM = $riga($marta);

    $piano = null;
    if ($conPiano) {
        $piano = PianoRate::create([
            'gestione_id' => $g->id, 'condominio_id' => $c->id, 'esercizio_id' => $e->id, 'nome' => 'Preventivo 2026', 'stato' => 'approvato',
            'tipo' => 'ordinario', 'numero_rate' => 12, 'giorno_scadenza' => 5, 'data_prima_scadenza' => '2026-01-05',
            'metodo_distribuzione' => 'prima_rata', 'applica_saldi' => true,
        ]);
        app(GeneratePianoRateAction::class)->execute($piano, forzaApplicazioneSaldi: true, esercizio: $e);
    }

    return compact('c', 'e', 'g', 'unita', 'cecilia', 'marta', 'luca', 'rigaC', 'rigaM', 'piano');
}

/** Emette a giornale le rate del piano con scadenza fino al giorno dato (da gennaio a novembre), come le trova chi registra l'atto. */
function pvcaEmetti(array $s, string $fino = '2026-11-30'): void
{
    $scritturaId = DB::table('scritture_contabili')->insertGetId([
        'condominio_id' => $s['c']->id, 'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'data_registrazione' => '2026-01-05', 'data_competenza' => '2026-01-05',
        'numero_protocollo' => 'EMI-PVCA-' . $s['piano']->id . '-' . $fino, 'causale' => 'emissione rate', 'descrizione' => 'emissione', 'tipo_movimento' => 'emissione_rate', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $rate = DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('data_scadenza', '<=', $fino . ' 23:59:59')->pluck('id');
    DB::table('rate')->whereIn('id', $rate)->update(['stato' => 'emessa', 'data_emissione' => '2026-01-05']);
    DB::table('rate_quote')->whereIn('rata_id', $rate)->update(['scrittura_contabile_id' => $scritturaId]);
}

/** L'insieme di Cecilia e Marta a Luca, registrato dalla rotta come lo registra la pagina: il pannello, poi la registrazione con l'impronta. @return list<Subentro> */
function pvcaRegistra($test, array $s): array
{
    $modulo = ['tipo' => 'vendita', 'righe_uscenti' => [$s['rigaC'], $s['rigaM']], 'riga_uscente_id' => null, 'anagrafica_entrante_id' => $s['luca']->id,
        'decorrenza' => '2026-11-16', 'quota' => 100, 'tipologia' => 'proprietario', 'copia_autentica' => true, 'copia_autentica_il' => '2026-11-16',
        'estremi_titolo' => 'Rogito del notaio Verdi, rep. 4521', 'pertinenze' => []];
    $pannello = $test->actingAs($test->user)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s['c'], $s['unita']]), $modulo)->assertOk()->json();
    $modulo += ['impronta_del_gruppo' => $pannello['impronta']];
    if ($pannello['cancello']['richiesto'] ?? false) {
        $modulo += ['ho_letto' => true, 'nota_cancello' => 'Rogito del notaio Verdi: vendono insieme le due sorelle.'];
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.immobili.passaggi.store', [$s['c'], $s['unita']]), $modulo)->assertSessionHasNoErrors()->assertRedirect();

    return Subentro::where('immobile_id', $s['unita']->id)->whereNull('subentro_padre_id')->whereNotNull('insieme_id')->orderBy('id')->get()->all();
}

/** Il pregresso dell'unità nelle quote del piano, per persona: la somma di `saldo_usato` delle regole congelate. */
function pvcaPregresso(PianoRate $piano, int $anagraficaId): int
{
    return (int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
        ->where('rate_quote.anagrafica_id', $anagraficaId)->pluck('rate_quote.regole_calcolo')
        ->sum(fn ($r) => (int) (json_decode((string) $r, true)['importi']['saldo_usato'] ?? 0));
}

/*
|--------------------------------------------------------------------------
| Il passaggio → i conguagli → l'estratto conto e l'incasso
|--------------------------------------------------------------------------
*/

it('la catena dei conguagli: l\'insieme → l\'estratto conto di Luca con € 40,98 a debito in due righe → la rata di dicembre emessa dalla rotta → l\'incasso di Luca di € 80,00 dalla rotta sulle due quote → tutto pagato; e lo storico dice che l\'insieme non si annulla più, con il motivo dell\'azione', function () {
    $s = pvcaScenario();
    pvcaEmetti($s);
    [$primo, $secondo] = pvcaRegistra($this, $s);

    // L'estratto conto: le coppie stanno nel saldo iniziale di ciascuno, una per passaggio. Luca ne ha due.
    $estratto = fn (Anagrafica $chi) => (int) app(EstrattoContoDellaPersona::class)->ledger($s['c'], $s['e']->fresh(), $chi->fresh())[2];
    expect(Saldo::where('anagrafica_id', $s['luca']->id)->orderBy('subentro_id')->get()->map(fn (Saldo $x) => [(int) $x->subentro_id, (int) $x->saldo_iniziale])->all())
        ->toBe([[$primo->id, 2049], [$secondo->id, 2049]])
        ->and(['Luca' => $estratto($s['luca']), 'Cecilia' => $estratto($s['cecilia']), 'Marta' => $estratto($s['marta'])])
        ->toBe(['Luca' => 4098, 'Cecilia' => -2049, 'Marta' => -2049]);

    // La rata del 5 dicembre: due quote di Luca, una da ciascuna, emesse dalla rotta.
    $rata12 = (int) DB::table('rate')->where('piano_rate_id', $s['piano']->id)->where('numero_rata', 12)->value('id');
    $quote = DB::table('rate_quote')->where('rata_id', $rata12)->orderBy('id')->get();
    expect($quote->map(fn ($q) => [(int) $q->anagrafica_id, (int) $q->importo])->all())->toBe([[$s['luca']->id, 4000], [$s['luca']->id, 4000]]);
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $s['piano']]), [
        'rate_ids' => [$rata12], 'data_emissione' => '2026-11-20', 'invia_notifiche' => false,
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect(DB::table('rate_quote')->where('rata_id', $rata12)->whereNull('scrittura_contabile_id')->count())->toBe(0);

    // L'incasso di Luca dalla rotta: € 80,00 sulle due quote.
    $conto = ContoContabile::create(['condominio_id' => $s['c']->id, 'codice' => 'PVCA-B' . $s['c']->id, 'nome' => 'Banca del condominio', 'tipo' => 'attivo', 'ruolo' => 'conto_bancario', 'categoria' => 'liquidita']);
    $cassa = Cassa::create(['condominio_id' => $s['c']->id, 'conto_contabile_id' => $conto->id, 'nome' => 'Banca del condominio', 'tipo' => 'banca', 'attiva' => true, 'saldo_iniziale' => 0]);
    $this->actingAs($this->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
        'pagante_id' => $s['luca']->id, 'cassa_id' => $cassa->id, 'data_pagamento' => '2026-11-21', 'importo_totale' => '80.00', 'descrizione' => 'Bonifico di Luca Toscani',
        'eccedenza' => '0.00', 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => $quote->map(fn ($q) => ['rata_id' => (int) $q->id, 'importo' => '40.00'])->all(),
    ])->assertSessionHasNoErrors();
    expect(DB::table('rate_quote')->where('rata_id', $rata12)->orderBy('id')->get()->map(fn ($q) => [(int) $q->importo_pagato, (int) $q->importo])->all())
        ->toBe([[4000, 4000], [4000, 4000]]);

    // Il conguaglio resta in saldi, fino al prossimo piano: l'estratto di Luca lo dice ancora, e la rata di dicembre è pagata.
    expect($estratto($s['luca']))->toBe(4098);

    // Lo storico: la rata emessa e pagata dopo l'atto ferma l'annullamento, con il motivo che dà l'azione dalla rotta (l'ultima registrata per prima).
    $motivo = $this->actingAs($this->user)->deleteJson(route('admin.gestionale.immobili.passaggi.annulla', [$s['c'], $s['unita'], $primo]), [
        'nota_annullamento' => 'Atto registrato con la data sbagliata: lo rifaccio.',
    ])->assertUnprocessable()->json('errors.passaggio.0');
    $voce = collect(app(StoricoTitolarita::class)->perImmobile($s['unita']->fresh())['subentri'])->firstWhere('id', $primo->id);
    expect($motivo)->toStartWith('La parte di Marta Acciaioli: dopo il passaggio è stata emessa a Luca Toscani la rata 12 del piano «Preventivo 2026»')
        ->and($voce['annullabile']['si'])->toBeFalse()
        ->and($voce['annullabile']['motivo'])->toBe($motivo)
        ->and(collect($voce['insieme']['passaggi'])->pluck('uscente')->all())->toBe(['Cecilia Angeli', 'Marta Acciaioli']);
});

/*
|--------------------------------------------------------------------------
| Il passaggio → il piano rate (la issue 58)
|--------------------------------------------------------------------------
*/

it('la catena della issue 58: € 180,00 sull\'unità dal Wallet → l\'insieme → la finestra «A chi va» con una riga e le scelte € 90,00 + € 90,00 o € 180,00 a Luca → il piano creato dalla rotta con la regola: € 90,00 di pregresso a Cecilia e a Marta, Luca dal giorno dell\'atto', function () {
    $s = pvcaScenario(conPiano: false);

    // Il Wallet: il saldo dell'intero immobile, dalla rotta.
    $this->actingAs($this->user)->from('/saldi')->post(route('admin.gestionale.saldi.store', [$s['c']]), [
        'anagrafica_id' => null, 'immobile_id' => $s['unita']->id, 'gestione_id' => $s['g']->id, 'saldo_iniziale' => 18000,
    ])->assertSessionHasNoErrors();
    $saldo = Saldo::where('immobile_id', $s['unita']->id)->whereNull('anagrafica_id')->firstOrFail();

    pvcaRegistra($this, $s);

    // La finestra, dalla rotta che la riempie: l'insieme è una riga sola, con tutte e due.
    $righe = $this->actingAs($this->user)->getJson(route('admin.gestionale.fetch-saldi-analitici', [$s['c'], $s['e'], $s['g']]))->assertOk()->json();
    $anteprima = collect($righe)->firstWhere('id', $saldo->id)['riparto_previsto'];
    expect($anteprima['passaggi'])->toHaveCount(1)
        ->and($anteprima['passaggi'][0]['uscenti'])->toBe(['Cecilia Angeli', 'Marta Acciaioli'])
        ->and($anteprima['passaggi'][0]['entranti'])->toBe(['Luca Toscani'])
        ->and(collect($anteprima['scelte'])->map(fn (array $c) => [$c['alla_data'], $c['regola'], collect($c['quote'])->mapWithKeys(fn ($q) => [$q['nome'] => $q['importo']])->all()])->all())
        ->toBe([
            ['2026-01-01', true, ['Cecilia Angeli' => 9000, 'Marta Acciaioli' => 9000]],
            ['2026-11-16', false, ['Luca Toscani' => 18000]],
        ]);

    // Il piano creato dalla rotta con la regola, generato subito.
    $this->actingAs($this->user)->from('/crea')->post(route('admin.gestionale.esercizi.piani-rate.store', [$s['c'], $s['e']]), [
        'tipo' => 'ordinario', 'gestione_id' => $s['g']->id, 'nome' => 'Preventivo 2026', 'metodo_distribuzione' => 'prima_rata', 'numero_rate' => 2, 'giorno_scadenza' => 5,
        'data_prima_scadenza' => '2026-12-05', 'genera_subito' => true, 'accetta_destinatari' => true, 'nota_destinatari' => 'Letto: la vendita del 16 novembre',
    ])->assertSessionHasNoErrors();
    $piano = PianoRate::where('nome', 'Preventivo 2026')->firstOrFail();

    expect(['Cecilia' => pvcaPregresso($piano, $s['cecilia']->id), 'Marta' => pvcaPregresso($piano, $s['marta']->id), 'Luca' => pvcaPregresso($piano, $s['luca']->id)])
        ->toBe(['Cecilia' => 9000, 'Marta' => 9000, 'Luca' => 0])
        // Luca paga le rate del piano dal giorno dell'atto: le sue quote hanno la spesa, senza pregresso.
        ->and((int) DB::table('rate_quote')->join('rate', 'rate.id', '=', 'rate_quote.rata_id')->where('rate.piano_rate_id', $piano->id)
            ->where('rate_quote.anagrafica_id', $s['luca']->id)->sum('rate_quote.importo'))->toBeGreaterThan(0);
});
