<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il già versato di un'unità si può intestare anche a chi ha venduto: a chi va un versamento senza data lo decide l'amministratore. Prima il
 * salvataggio rifiutava con 422 chi non era titolare oggi e non aveva già una riga sulla voce.
 *
 * Il caso a mano: «Pulizie» € 700,00 tutta al proprietario; l'esercizio 2024 ha 366 giorni, Ugo fino al 01/07 (183) ed Elsa dal 02/07 (183):
 * € 350,00 a testa. Ugo aveva versato € 300,00 di tasca sua, e i € 200,00 senza data l'amministratore li intesta a Ugo: € 500,00 a suo nome.
 * Ugo ha versato € 500,00 per € 350,00 (€ 150,00 restano suoi), Elsa paga € 350,00. Nel piano e nel conguaglio.
 */

use App\Enums\EventoTipo;
use App\Models\Anagrafica;
use App\Models\Evento;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\ContributoVersato;
use App\Models\Gestionale\PianoConto;
use App\Models\Immobile;
use App\Services\CalcoloQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PassaggiScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    b51aAccesso($this);
});

function gvdvScenario(): array
{
    $s = fclScenario([['Ugo', 'proprietario', 100]], ['anno' => 2024, 'importo' => 70000, 'ripartizione' => ['proprietario' => 100], 'inquilini' => []]);
    b51aPersone($s, ['Elsa']);
    $s['conto'] = Conto::where('piano_conto_id', PianoConto::where('gestione_id', $s['g']->id)->value('id'))->firstOrFail();
    $s['conto']->update(['nome' => 'Pulizie']);

    return $s;
}

/** La vendita da Ugo a Elsa dal 02/07/2024, dalle rotte vere. */
function gvdvVendi($test, array $s)
{
    return fds50Registra($test, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2024-07-02'))['subentro'];
}

/** Il salvataggio dalla pagina del già versato: le righe [persona (o null) => centesimi] dell'Interno 1. */
function gvdvSalva($test, array $s, array $righe, ?int $immobileId = null)
{
    return $test->actingAs($test->user)->putJson(route('admin.gestionale.contributi.update', [$s['c'], $s['conto']]), [
        'natura' => 'avanzo', 'vale_per' => 'tutti', 'liquidita_stato' => 'gia_in_apertura',
        'righe' => array_map(fn (array $r) => ['immobile_id' => $immobileId ?? $s['unita']->id, 'anagrafica_id' => $r[0], 'gia_versato' => $r[1]], $righe),
    ]);
}

function gvdvPagina($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.contributi.edit', [$s['c'], $s['conto']]))->assertOk()->viewData('page')['props'];
}

/** Il ricalcolo dalla pagina del piano, con la presa d'atto della divisione per giorni che l'amministratore dà. */
function gvdvRicalcola($test, array $s): void
{
    $test->actingAs($test->user)->from('/piano')->post(route('admin.gestionale.esercizi.piani-rate.regenerate', [$s['c'], $s['e'], $s['piano']]),
        ['accetta_destinatari' => true, 'nota_destinatari' => 'Letto: la spesa divisa per giorni fra Ugo ed Elsa'])
        ->assertRedirect()->assertSessionHasNoErrors()->assertSessionMissing('destinatari_warning')->assertSessionHas('message.type', 'success');
}

/** Il già versato che il piano ha tolto, per persona: nome → centesimi. */
function gvdvTolto(array $s): array
{
    $nomi = fclNomi($s);

    return DB::table('righe_riparto')->where('piano_rate_id', $s['piano']->id)->where('tipo', 'netting')
        ->where('riga_descrizione', CalcoloQuoteService::NETTING_DELLA_PERSONA)->get(['anagrafica_id', 'importo'])
        ->groupBy(fn ($r) => $nomi[(int) $r->anagrafica_id])->map(fn ($g) => -(int) $g->sum('importo'))->all();
}

it('dopo la vendita il già versato si intesta a Ugo, e il piano chiede € 350,00 a Elsa e niente a Ugo, con € 150,00 rimasti suoi', function () {
    $s = gvdvScenario();
    gvdvVendi($this, $s);

    gvdvSalva($this, $s, [[$s['persone']['Ugo']->id, 50000]])->assertRedirect();
    expect(ContributoVersato::where('anagrafica_id', $s['persone']['Ugo']->id)->value('importo_cents'))->toBe(50000);

    fclGenera($s);

    expect(fclNetti($s))->toBe(['Elsa' => 35000])
        ->and(gvdvTolto($s))->toBe(['Ugo' => 35000]);
    $compito = Evento::where('meta->type', EventoTipo::ECCEDENZA_GIA_VERSATO_RILEVATA->value)->firstOrFail();
    expect($compito->description)->toContain('Ugo: € 150,00')
        ->and($compito->meta['context']['totale_cents'])->toBe(15000);
});

it('il piano generato prima di intestare il versamento si ricalcola con la riga di chi ha venduto', function () {
    $s = gvdvScenario();
    gvdvVendi($this, $s);
    fclGenera($s);
    expect(fclNetti($s))->toBe(['Elsa' => 35000, 'Ugo' => 35000]);

    gvdvSalva($this, $s, [[$s['persone']['Ugo']->id, 50000]])->assertRedirect();
    gvdvRicalcola($this, $s);

    expect(fclNetti($s))->toBe(['Elsa' => 35000])->and(gvdvTolto($s))->toBe(['Ugo' => 35000]);
});

it('l\'annullamento della vendita non rifiuta la riga intestata dopo, e registrata di nuovo il conguaglio dà le stesse cifre del piano', function () {
    $s = gvdvScenario();
    $vendita = gvdvVendi($this, $s);
    gvdvSalva($this, $s, [[$s['persone']['Ugo']->id, 50000]])->assertRedirect();
    fclGenera($s);

    fclAnnulla($this, $s, $vendita)->assertRedirect()->assertSessionHasNoErrors();
    expect(ContributoVersato::where('anagrafica_id', $s['persone']['Ugo']->id)->value('importo_cents'))->toBe(50000);
    gvdvSalva($this, $s, [[$s['persone']['Ugo']->id, 50000]])->assertRedirect();
    gvdvRicalcola($this, $s);
    // Tornato proprietario di tutto l'anno: € 700,00 − € 500,00.
    expect(fclNetti($s))->toBe(['Ugo' => 20000]);

    // Il conguaglio: le rate emesse a Ugo (€ 200,00 al netto) e la vendita registrata di nuovo. A Elsa € 350,00; Ugo resta a € 150,00 a credito.
    fclEmetti($this, $s, '2024-12-31');
    $anteprima = fclAnteprima($this, $s, fds50ModuloVendita($s, 'Ugo', 'Elsa', '2024-07-02'));
    expect(fclCoppie($anteprima))->toBe([[(int) $s['unita']->id, 'Elsa', 35000]]);
    gvdvVendi($this, $s);

    expect(fclNetti($s))->toBe(['Elsa' => 35000, 'Ugo' => -15000]);
});

it('la pagina offre chi ha venduto con il periodo, dopo i titolari di oggi, e dopo una vendita nell\'esercizio propone «l\'unità», non chi ha comprato', function () {
    $s = gvdvScenario();
    gvdvVendi($this, $s);

    $props = gvdvPagina($this, $s);
    $titolari = collect($props['titolari'][$s['unita']->id]);

    expect($titolari->pluck('nome')->all())->toBe(['Elsa', 'Ugo'])
        ->and($titolari[0]['chiusa_il'])->toBeNull()
        ->and($titolari[0]['periodi'])->toBe([])
        ->and($titolari[1])->toMatchArray(['tipologia' => 'proprietario', 'quota' => 100.0, 'chiusa_il' => '2024-07-01', 'periodi' => [['dal' => '2019-01-01', 'al' => '2024-07-01']]])
        // Il piano divide «l'unità» per giorni fra Ugo ed Elsa: proposta da sola, Elsa prenderebbe anche i giorni di Ugo.
        ->and($props['righe'][0]['anagrafica_id'])->toBeNull()
        ->and($props['righe'][0]['quota_lorda'])->toBe(70000);
});

it('la pagina conta la parte di ciascuno per giorni, come il piano: con la sola riga di Ugo a € 500,00 la sua quota è € 350,00', function () {
    $s = gvdvScenario();
    gvdvVendi($this, $s);

    $props = gvdvPagina($this, $s);
    $titolari = collect($props['titolari'][$s['unita']->id])->keyBy('nome');
    // Il 2024 ha 366 giorni: Ugo fino al 01/07 (183), Elsa dal 02/07 (183).
    expect($props['esercizio_di_calcolo'])->toBe((int) $s['e']->id)
        ->and($titolari['Ugo']['parti'])->toBe([(int) $s['e']->id => 0.5])
        ->and($titolari['Elsa']['parti'])->toBe([(int) $s['e']->id => 0.5]);

    gvdvSalva($this, $s, [[$s['persone']['Ugo']->id, 50000]])->assertRedirect();
    $riga = gvdvPagina($this, $s)['righe'][0];
    expect($riga['anagrafica_id'])->toBe($s['persone']['Ugo']->id)
        ->and($riga['quota_lorda'])->toBe(35000)
        ->and($riga['quota_lorda_unita'])->toBe(70000);

    // Il piano chiede la stessa cosa: € 350,00 a Elsa, a Ugo niente, € 150,00 restano suoi.
    fclGenera($s);
    expect(fclNetti($s))->toBe(['Elsa' => 35000]);
});

it('con un buco fra Ugo ed Elsa la pagina prende le parti dal motore: Ugo € 348,09, Elsa € 325,13 e € 26,78 senza titolare, come il piano', function () {
    // Ugo fino al 30/06/2024, Elsa dal 15/07/2024 scritta a mano, senza passaggio. Il 2024 ha 366 giorni: Ugo 182, Elsa 170, 14 giorni senza nessuno.
    // € 700,00 × 182/366 = 348,087 → 348,09; × 170/366 = 325,136 → 325,13; × 14/366 = 26,775 → 26,78. Prima la pagina riportava a uno le parti
    // (Ugo 182/352 = 0,517045, € 361,93) e i giorni senza titolare finivano a chi c'era.
    $s = gvdvScenario();
    $ugo = $s['persone']['Ugo'];
    $elsa = $s['persone']['Elsa'];
    DB::table('anagrafica_immobile')->where('anagrafica_id', $ugo->id)->update(['data_fine' => '2024-06-30']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $elsa->id, 'immobile_id' => $s['unita']->id, 'tipologia' => 'proprietario', 'quota' => 100,
        'attivo' => true, 'data_inizio' => '2024-07-15', 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $e = (int) $s['e']->id;

    $props = gvdvPagina($this, $s);
    $t = collect($props['titolari'][$s['unita']->id])->keyBy('nome');
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($s['piano']->fresh(), forzaApplicazioneSaldi: true, accettaScoperti: true, notaScoperti: 'Prova',
        accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);

    // Le parti sono quelle del motore (182/366, 170/366 e 14/366); la pagina arrotonda riga per riga, il piano dà il centesimo del resto a chi ha il
    // resto più alto: al centesimo, le stesse cifre.
    expect($t['Ugo']['parti'][$e])->toBe(0.497268)->and($t['Elsa']['parti'][$e])->toBe(0.464481)
        ->and(((array) $props['senza_titolare'])[$s['unita']->id][$e])->toBe(0.038251)
        ->and(fclNetti($s))->toBe(['Elsa' => 32513, 'Ugo' => 34809])
        ->and(abs((int) round($t['Ugo']['parti'][$e] * 70000) - 34809))->toBeLessThanOrEqual(1)
        ->and(abs((int) round($t['Elsa']['parti'][$e] * 70000) - 32513))->toBeLessThanOrEqual(1);

    // Con la riga di Ugo la sua quota dovuta è la sua parte del motore, non 0,517 della spesa.
    gvdvSalva($this, $s, [[$ugo->id, 10000]])->assertRedirect();
    expect(gvdvPagina($this, $s)['righe'][0]['quota_lorda'])->toBe(34809);
});

it('senza una vendita nell\'esercizio la pagina propone ancora il proprietario di oggi, con tutta la quota', function () {
    $s = gvdvScenario();

    $props = gvdvPagina($this, $s);
    expect($props['righe'][0]['anagrafica_id'])->toBe($s['persone']['Ugo']->id)
        ->and($props['righe'][0]['quota_lorda'])->toBe(70000)
        ->and(collect($props['titolari'][$s['unita']->id])->firstWhere('nome', 'Ugo')['parti'])->toBe([(int) $s['e']->id => 1.0]);
});

it('con più righe passate della stessa persona i periodi attaccati si uniscono e quelli staccati restano due; il ruolo e la quota sono dell\'ultima', function () {
    $s = gvdvScenario();
    $ugo = $s['persone']['Ugo']->id;
    DB::table('anagrafica_immobile')->where('immobile_id', $s['unita']->id)->delete();
    foreach ([
        [$ugo, 'proprietario', 100, '2010-01-01', '2012-12-31'],
        [$ugo, 'proprietario', 100, '2019-01-01', '2020-12-31'],
        [$ugo, 'usufruttuario', 50, '2021-01-01', '2024-07-01'],
        [$s['persone']['Elsa']->id, 'proprietario', 100, '2024-07-02', null],
    ] as [$chi, $ruolo, $quota, $dal, $al]) {
        DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $chi, 'immobile_id' => $s['unita']->id, 'tipologia' => $ruolo, 'quota' => $quota,
            'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);
    }

    $riga = collect(gvdvPagina($this, $s)['titolari'][$s['unita']->id])->firstWhere('id', $ugo);

    expect($riga)->toMatchArray(['tipologia' => 'usufruttuario', 'quota' => 50.0, 'chiusa_il' => '2024-07-01',
        'periodi' => [['dal' => '2010-01-01', 'al' => '2012-12-31'], ['dal' => '2019-01-01', 'al' => '2024-07-01']]]);
});

it('controprova: chi non è stato titolare di questa unità di questo condominio resta rifiutato, e non compare fra le scelte', function () {
    $s = gvdvScenario();
    gvdvVendi($this, $s);
    $persona = fn (string $nome) => Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-gvdv@test.it', 'indirizzo' => 'Via Roma 1',
        'codice_fiscale' => strtoupper(substr(md5($nome), 0, 16))]);
    $riga = fn (Anagrafica $a, int $immobileId, string $ruolo, string $dal, ?string $al) => DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $a->id,
        'immobile_id' => $immobileId, 'tipologia' => $ruolo, 'quota' => 100, 'attivo' => true, 'data_inizio' => $dal, 'data_fine' => $al, 'created_at' => now(), 'updated_at' => now()]);

    // Ha venduto un'altra unità dello stesso condominio.
    $altraUnita = Immobile::create(['condominio_id' => $s['c']->id, 'tipo' => 'appartamento', 'codice_immobile' => 'GVDV-2', 'nome' => 'Interno 2', 'interno' => '2']);
    $carlo = $persona('Carlo');
    $riga($carlo, $altraUnita->id, 'proprietario', '2019-01-01', '2023-12-31');
    // Ha venduto un'unità di un altro condominio.
    $altro = fclScenario([['Dino', 'proprietario', 100]], ['inquilini' => []]);
    $dino = $altro['persone']['Dino'];
    DB::table('anagrafica_immobile')->where('immobile_id', $altro['unita']->id)->where('anagrafica_id', $dino->id)->update(['data_fine' => '2023-12-31']);
    // È stato inquilino di questa unità; comprerà questa unità domani.
    $ines = $persona('Ines');
    $riga($ines, $s['unita']->id, 'inquilino', '2019-01-01', '2023-12-31');
    $fabio = $persona('Fabio');
    $riga($fabio, $s['unita']->id, 'proprietario', now()->addDays(30)->toDateString(), null);

    foreach ([$carlo, $dino, $ines, $fabio] as $chi) {
        gvdvSalva($this, $s, [[$chi->id, 1000]])->assertUnprocessable()->assertJsonValidationErrors('righe.0.anagrafica_id');
    }
    // L'unità dell'altro condominio, con il suo venditore: rifiutata sull'unità.
    gvdvSalva($this, $s, [[$dino->id, 1000]], $altro['unita']->id)->assertUnprocessable()->assertJsonValidationErrors('righe.0.immobile_id');

    expect(ContributoVersato::count())->toBe(0)
        ->and(collect(gvdvPagina($this, $s)['titolari'][$s['unita']->id])->pluck('nome')->all())->toBe(['Elsa', 'Ugo']);
});
