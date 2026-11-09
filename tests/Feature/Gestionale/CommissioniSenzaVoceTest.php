<?php

/**
 * Le commissioni scritte prima che la commissione avesse una voce: restano come sono (nessun riempimento), e un elenco per esercizio le mostra
 * perché l'amministratore assegni la voce a mano, in blocco, negli esercizi con lo stato «aperto». Un esercizio chiuso non si tocca: il suo
 * rendiconto è già stato presentato.
 *
 * «Aperto» è lo stato scritto dell'esercizio, non la data: un 2026 finito per data e ancora aperto (fino all'assemblea) si corregge. L'elenco tiene
 * insieme la commissione e il suo storno, e non offre la voce a uno storno il cui originale sta in un esercizio chiuso: −€ 2,50 su una voce
 * dell'anno nuovo contro un originale che resta senza voce.
 *
 * Caso: dodici pagamenti con una commissione di € 2,50 ciascuno, scritti senza voce sulle «Spese bancarie» di sistema; assegnati in blocco alla voce
 * «Spese bancarie» la fanno salire di € 30,00 (12 × 2,50). Le cifre vengono dalla regola, non dal codice.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\PagamentoFornitore;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->travelTo(Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
});

/** Il condominio con la voce «Spese bancarie», un fornitore, una fattura da € 1.000,00 e `$quanti` pagamenti da € 10,00 con € 2,50 di commissione, scritti senza voce. */
function csvScenario($test, int $quanti = 12): array
{
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['bancarie'] = be52Voce($test, $s, $s['pc'], 'Spese bancarie', 'spesa', '150,00');
    $s['fornitore'] = be52Fornitore();
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $s['fornitore'], $s['spesa']->id, 1000.00, [], 'FT-CSV'))->assertSessionHasNoErrors();
    $s['fattura'] = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'FT-CSV')->where('condominio_id', $s['c']->id)->firstOrFail();
    $s['sistema'] = (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
    for ($i = 1; $i <= $quanti; $i++) {
        csvPagaVecchio($test, $s, 1000 + $i);
    }

    return $s;
}

/** Un pagamento con la commissione, poi riportato come lo scriveva la versione di prima: «Spese bancarie» di sistema, senza voce. */
function csvPagaVecchio($test, array $s, int $centesimi): PagamentoFornitore
{
    $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-04-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 250, 'bonifico_parlante' => false,
        'voce_commissione_id' => $s['bancarie']->id,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $s['fattura']->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => $centesimi]],
    ])->assertSessionHasNoErrors();
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->latest('id')->firstOrFail();
    DB::table('righe_scritture')->where('scrittura_id', $p->scrittura_contabile_id)->where('voce_spesa_id', $s['bancarie']->id)
        ->update(['conto_contabile_id' => $s['sistema'], 'voce_spesa_id' => null]);

    return $p;
}

function csvElenco($test, array $s, ?Esercizio $e = null): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$s['c'], $e ?? $s['e']]))->assertOk()->viewData('page')['props'];
}

function csvAssegna($test, array $s, array $corpo, ?Esercizio $e = null)
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $e ?? $s['e']]), $corpo);
}

function csvSpesoBancarie(array $s, ?Esercizio $e = null): int
{
    return (int) ((new SpesaPerVoceService)->perEsercizio($e ?? $s['e'])[$s['bancarie']->id] ?? 0);
}

it('l\'elenco: dodici commissioni senza voce, € 30,00, nell\'esercizio aperto e assegnabili', function () {
    $s = csvScenario($this);
    $p = csvElenco($this, $s);

    expect($p['commissioni'])->toHaveCount(12)
        ->and($p['totale'])->toBe(['numero' => 12, 'importo' => 3000])
        ->and(collect($p['commissioni'])->every(fn ($c) => $c['assegnabile'] === true))->toBeTrue()
        ->and($p['commissioni'][0]['righe'][0]['data'])->toBe('2026-04-10')
        ->and($p['commissioni'][0]['fornitore'])->toBe('Fornitore Test Srl')
        ->and($p['esercizio']['aperto'])->toBeTrue();
});

it('assegnate in blocco alla voce «Spese bancarie»: la voce sale di € 30,00 e le righe passano sul mastro della voce', function () {
    $s = csvScenario($this);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();

    expect(csvSpesoBancarie($s))->toBe(0);
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();

    expect(csvSpesoBancarie($s))->toBe(3000)
        ->and(DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->count())->toBe(0)
        ->and(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->where('conto_contabile_id', $s['bancarie']->conto_contabile_id)->count())->toBe(12)
        ->and(csvElenco($this, $s)['commissioni'])->toBe([]);
});

it('la voce data a una commissione già scritta lascia la sua traccia sulla riga del giornale: quando, chi, e dove stava prima; alle 00:30 di Roma del 01/01 il giorno è il 01/01', function () {
    $s = csvScenario($this, 2);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    $sistema = (string) DB::table('conti_contabili')->where('id', $s['sistema'])->value('nome');
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();

    expect(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->whereIn('scrittura_id', PagamentoFornitore::where('condominio_id', $s['c']->id)->pluck('scrittura_contabile_id'))
        ->pluck('note')->unique()->values()->all())
        ->toBe(['Voce assegnata il 01/01/2027 da '.$this->user->name.' (prima senza voce, sulle «'.$sistema.'» di sistema)']);
});

it('la traccia della voce si aggiunge dopo la nota che la riga della commissione aveva già, senza perderla', function () {
    $s = csvScenario($this, 2);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    $sistema = (string) DB::table('conti_contabili')->where('id', $s['sistema'])->value('nome');
    DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->whereNull('voce_spesa_id')->update(['note' => 'Bonifico del 12/03']);
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();

    expect(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->whereIn('scrittura_id', PagamentoFornitore::where('condominio_id', $s['c']->id)->pluck('scrittura_contabile_id'))
        ->pluck('note')->unique()->values()->all())
        ->toBe(['Bonifico del 12/03 Voce assegnata il 01/01/2027 da '.$this->user->name.' (prima senza voce, sulle «'.$sistema.'» di sistema)']);
});

it('«tutte le N»: senza scegliere le righe si assegnano tutte quelle della gestione', function () {
    $s = csvScenario($this, 3);
    csvAssegna($this, $s, ['tutte' => true, 'gestione_id' => $s['g']->id, 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();

    expect(csvSpesoBancarie($s))->toBe(750);
});

it('senza la voce, con una voce di entrata o di un\'altra gestione, l\'assegnazione è rifiutata e nessuna riga cambia', function () {
    $s = csvScenario($this, 2);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    $straordinaria = be52GestioneStraordinaria($s);
    $altra = be52Voce($this, $s, $straordinaria['pc'], 'Bancarie del tetto', 'spesa', '10,00');
    csvAssegna($this, $s, ['commissioni' => $ids])->assertSessionHasErrors('voce_id');
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['entrata']->id])->assertSessionHasErrors('voce_id');
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $altra->id])->assertSessionHasErrors('voce_id');

    expect(DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->whereNull('voce_spesa_id')->count())->toBe(2);
});

it('in un esercizio chiuso le commissioni si vedono ma non si toccano, e la frase lo dice', function () {
    $s = csvScenario($this, 2);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $p = csvElenco($this, $s);

    expect($p['commissioni'])->toHaveCount(2)
        ->and(collect($p['commissioni'])->every(fn ($c) => $c['assegnabile'] === false))->toBeTrue()
        ->and($p['esercizio']['aperto'])->toBeFalse()
        ->and($p['frase_chiuso'])->toBe('L\'esercizio «Esercizio 2026» è chiuso: le commissioni senza voce restano come sono, e il suo rendiconto le mostra in una riga loro. La voce si assegna solo negli esercizi aperti.');
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])->assertSessionHasErrors([
        'commissioni' => 'L\'esercizio «Esercizio 2026» è chiuso: le commissioni senza voce restano come sono, e il suo rendiconto le mostra in una riga loro. La voce si assegna solo negli esercizi aperti.',
    ]);

    expect(DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->whereNull('voce_spesa_id')->count())->toBe(2);
});

it('un 2026 finito per data e ancora aperto nel database si corregge', function () {
    $s = csvScenario($this, 2);
    $this->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();

    expect(csvSpesoBancarie($s))->toBe(500);
});

it('la commissione e il suo storno nello stesso esercizio stanno insieme e prendono la voce insieme: il consuntivo netto è zero', function () {
    $s = csvScenario($this, 1);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $elenco = csvElenco($this, $s)['commissioni'];

    expect($elenco)->toHaveCount(1)
        ->and(collect($elenco[0]['righe'])->pluck('importo')->all())->toBe([250, -250])
        ->and($elenco[0]['importo'])->toBe(0);
    csvAssegna($this, $s, ['commissioni' => [$elenco[0]['id']], 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();

    expect(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->count())->toBe(2)
        ->and(csvSpesoBancarie($s))->toBe(0);
});

it('il messaggio dopo «Assegna la voce» e la riga del registro contano le commissioni come commissioni, anche quella che ha il suo storno nello stesso esercizio', function () {
    $s = csvScenario($this, 2);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->orderBy('id')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    \Illuminate\Support\Facades\Log::spy();

    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors()
        ->assertSessionHas('message', fn ($m) => ($m['message'] ?? null) === 'Voce «Spese bancarie» assegnata a 2 commissioni: il consuntivo della voce cambia di € 2,50.');
    \Illuminate\Support\Facades\Log::shouldHaveReceived('info')
        ->with(\Mockery::on(fn ($riga) => is_string($riga) && str_starts_with($riga, 'Voce assegnata a 2 commissioni senza voce da utente #')))->once();
    expect($ids)->toHaveCount(2);
});

it('lo storno nel 2027 di un pagamento del 2026 chiuso non si offre: l\'originale resta senza voce, e la riga dice perché', function () {
    $s = csvScenario($this, 1);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->firstOrFail();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $elenco = csvElenco($this, $s, $e27)['commissioni'];

    expect($elenco)->toHaveCount(1)
        ->and($elenco[0]['assegnabile'])->toBeFalse()
        ->and($elenco[0]['importo'])->toBe(-250)
        ->and($elenco[0]['motivo'])->toBe('È lo storno di una commissione dell\'esercizio «Esercizio 2026», che è chiuso e resta senza voce: lo storno resta senza voce anche lui.');
    csvAssegna($this, $s, ['commissioni' => [$elenco[0]['id']], 'voce_id' => $s['bancarie']->id], $e27)->assertSessionHasErrors('commissioni');

    expect(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->count())->toBe(0);
});

it('l\'elenco e l\'assegnazione di un esercizio di un altro condominio rispondono 404', function () {
    $s = csvScenario($this, 1);
    $altro = be52Condominio();
    $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$altro['c'], $s['e']]))->assertNotFound();
    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$altro['c'], $s['e']]), ['tutte' => true, 'gestione_id' => $s['g']->id, 'voce_id' => $s['bancarie']->id])->assertNotFound();

    expect(DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->whereNull('voce_spesa_id')->count())->toBe(1);
});

it('una commissione che nel frattempo ha già la voce non si assegna di nuovo: la richiesta è rifiutata per intero', function () {
    $s = csvScenario($this, 2);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();
    csvAssegna($this, $s, ['commissioni' => [$ids[0]], 'voce_id' => $s['bancarie']->id])->assertSessionHasNoErrors();
    $altraVoce = be52Voce($this, $s, $s['pc'], 'Bolli', 'spesa', '10,00');
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $altraVoce->id])->assertSessionHasErrors('commissioni');

    expect(csvSpesoBancarie($s))->toBe(250)
        ->and((int) ((new SpesaPerVoceService)->perEsercizio($s['e'])[$altraVoce->id] ?? 0))->toBe(0);
});

it('l\'elenco e l\'assegnazione stanno dietro il pannello', function () {
    $s = csvScenario($this, 1);
    $esterno = \App\Models\User::factory()->create();
    $this->actingAs($esterno)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$s['c'], $s['e']]))->assertForbidden();
    $this->actingAs($esterno)->post(route('admin.gestionale.esercizi.commissioni-senza-voce.assegna', [$s['c'], $s['e']]), ['tutte' => true, 'gestione_id' => $s['g']->id, 'voce_id' => $s['bancarie']->id])->assertForbidden();

    expect(DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->whereNull('voce_spesa_id')->count())->toBe(1);
});

it('la pagina dei pagamenti dice quante commissioni dell\'esercizio aperto sono senza voce, con l\'importo', function () {
    $s = csvScenario($this, 2);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.index', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect($props['commissioni_senza_voce'])->toBe(['esercizio_id' => $s['e']->id, 'numero' => 2, 'importo' => 500]);
});

function csvRiquadro($test, array $s): ?array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.pagamenti-fornitori.index', [$s['c']]))->assertOk()->viewData('page')['props']['commissioni_senza_voce'];
}

it('il riquadro dei pagamenti conta solo quelle che si possono assegnare: il solo storno di un anno chiuso non lo accende', function () {
    $s = csvScenario($this, 1);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->firstOrFail();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();

    expect(csvRiquadro($this, $s))->toBeNull();
});

it('il riquadro non invita ad assegnare una commissione e il suo storno, che insieme fanno zero', function () {
    $s = csvScenario($this, 1);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();

    expect(csvRiquadro($this, $s))->toBeNull();
});

it('con dodici commissioni assegnabili il riquadro dice 12 e € 30,00', function () {
    $s = csvScenario($this);

    expect(csvRiquadro($this, $s))->toBe(['esercizio_id' => $s['e']->id, 'numero' => 12, 'importo' => 3000]);
});

it('una commissione e il suo storno in due esercizi (riaperto il primo): nessuno dei due elenchi la offre, e ognuno dice perché', function () {
    $s = csvScenario($this, 1);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->firstOrFail();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    // Il 2027 si chiude e il 2026 si riapre: un esercizio chiuso si riapre, se nessun altro è aperto.
    DB::table('esercizi')->where('id', $e27->id)->update(['stato' => 'chiuso']);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'aperto']);
    $del2026 = csvElenco($this, $s)['commissioni'];
    $del2027 = csvElenco($this, $s, $e27)['commissioni'];

    expect($del2026)->toHaveCount(1)
        ->and($del2026[0]['assegnabile'])->toBeFalse()
        ->and($del2026[0]['motivo'])->toBe('Il suo storno sta in un altro esercizio: la commissione e lo storno prendono la voce insieme, nello stesso esercizio.')
        ->and($del2027)->toHaveCount(1)
        ->and($del2027[0]['assegnabile'])->toBeFalse()
        ->and($del2027[0]['motivo'])->toBe('È lo storno di una commissione dell\'esercizio «Esercizio 2026»: la commissione e lo storno stanno in due esercizi, e la voce non si assegna a uno solo dei due.');
    csvAssegna($this, $s, ['commissioni' => [$del2026[0]['id']], 'voce_id' => $s['bancarie']->id])->assertSessionHasErrors('commissioni');

    expect(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->count())->toBe(0);
});

it('un id di un altro condominio fra le commissioni è un 404 per tutta la richiesta, e nessuna riga cambia in nessuno dei due', function () {
    $s = csvScenario($this, 1);
    $altro = csvScenario($this, 1);
    $mia = csvElenco($this, $s)['commissioni'][0]['id'];
    $sua = csvElenco($this, $altro)['commissioni'][0]['id'];
    csvAssegna($this, $s, ['commissioni' => [$mia, $sua], 'voce_id' => $s['bancarie']->id])->assertNotFound();

    expect(DB::table('righe_scritture')->whereIn('id', [$mia, $sua])->whereNull('voce_spesa_id')->count())->toBe(2);
});

it('commissioni di due gestioni insieme: la richiesta è rifiutata, con la frase', function () {
    $s = csvScenario($this, 1);
    $t = be52GestioneStraordinaria($s);
    $lavori = be52Voce($this, $s, $t['pc'], 'Lavori tetto', 'spesa', '5.000,00');
    $bancarieTetto = be52Voce($this, $s, $t['pc'], 'Bancarie del tetto', 'spesa', '50,00');
    $this->actingAs($this->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $s['fornitore'], $lavori->id, 2000.00, ['gestione_id' => $t['g']->id], 'FT-TETTO'))->assertSessionHasNoErrors();
    $fattura = \App\Models\Gestionale\FatturaPassiva::where('numero_documento', 'FT-TETTO')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), [
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-04-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 250, 'bonifico_parlante' => false,
        'voce_commissione_id' => $bancarieTetto->id, 'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => 1000]],
    ])->assertSessionHasNoErrors();
    DB::table('righe_scritture')->where('voce_spesa_id', $bancarieTetto->id)->update(['conto_contabile_id' => $s['sistema'], 'voce_spesa_id' => null]);
    $ids = collect(csvElenco($this, $s)['commissioni'])->pluck('id')->all();

    expect($ids)->toHaveCount(2);
    csvAssegna($this, $s, ['commissioni' => $ids, 'voce_id' => $s['bancarie']->id])
        // Dalla Coda 553 la stessa voce dell'ordinaria va bene per tutte e due: il rifiuto resta, il perché cambia (SA11 dello scettico della tappa A).
        ->assertSessionHasErrors(['commissioni' => 'Le commissioni scelte sono di gestioni diverse: si assegnano una gestione alla volta, anche con la stessa voce.']);

    expect(DB::table('righe_scritture')->where('conto_contabile_id', $s['sistema'])->whereNull('voce_spesa_id')->count())->toBe(2);
});

it('anche con due esercizi aperti insieme (dati che la pagina degli esercizi oggi non permette), lo storno di un originale dell\'altro non si offre', function () {
    $s = csvScenario($this, 1);
    $p = PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->firstOrFail();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo(Carbon::parse('2027-02-10 10:00', 'Europe/Rome'));
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'aperto']);
    $del2027 = csvElenco($this, $s, $e27)['commissioni'];

    expect($del2027)->toHaveCount(1)
        ->and($del2027[0]['assegnabile'])->toBeFalse()
        ->and($del2027[0]['motivo'])->toBe('È lo storno di una commissione dell\'esercizio «Esercizio 2026»: la commissione e lo storno stanno in due esercizi, e la voce non si assegna a uno solo dei due.');
    csvAssegna($this, $s, ['commissioni' => [$del2027[0]['id']], 'voce_id' => $s['bancarie']->id], $e27)->assertSessionHasErrors('commissioni');

    expect(DB::table('righe_scritture')->where('voce_spesa_id', $s['bancarie']->id)->count())->toBe(0);
});
