<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Coda 187 (con la 207) — chi vende si toglie dal condominio, se l'amministratore lo sceglie; il debito rimasto si incassa lo stesso.
 *
 * Nessun passaggio toglieva il venditore dalle persone del condominio: continuava a vedere il portale e a ricevere ciò che va «a tutti». Al passaggio il
 * pannello dice chi vende e che cosa gli resta nel condominio; il modulo chiede «Togli Mario dal condominio», spuntato di serie se non gli resta niente,
 * e la registrazione lo toglie. Le righe di titolarità e le rate restano: l'incasso delle sue rate di prima passa e il suo estratto conto risponde
 * (`Anagrafica::delCondominio`).
 *
 * Il caso: Mario, proprietario al 100 % dell'Interno 7, ha tre rate emesse da € 400,00; vende a Elsa il 01/07/2026.
 */

use App\Models\Gestionale\RataQuote;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/Beta51PianoRateScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-07-10 10:00:00');
});

function vfcScenario($test, bool $altraUnita = false): Beta51Scenario
{
    $s = Beta51Scenario::nuovo($test)->tabella('Proprietà', ['7' => 600, 'B' => 400])->titolare('7', 'mario')->titolare('B', $altraUnita ? 'mario' : 'bice')->gestione('Ordinaria');
    app(\App\Services\CondominioService::class)->ensureDefaultConti($s->c);
    $s->voce('Ordinaria', 'Spese generali', 200000, ['Proprietà' => 100]);
    $s->pianoOrdinario('Preventivo 2026', 'Ordinaria', numeroRate: 3, primaScadenza: '2026-02-05');
    $s->genera('Preventivo 2026');
    $s->emetti('Preventivo 2026', '2026-06-05')->assertSessionHasNoErrors();
    $s->persona('elsa');
    $s->cassa();

    return $s;
}

/** Il modulo della vendita di Mario a Elsa, con la scelta su chi si toglie dal condominio. */
function vfcModulo(Beta51Scenario $s, ?array $togli = null): array
{
    $dati = $s->modulo('vendita', '7', 'mario', 'elsa', '2026-07-01');

    return $togli === null ? $dati : $dati + ['togli_dal_condominio' => array_map(fn (string $chi) => $s->persona($chi)->id, $togli)];
}

function vfcAnteprima($test, Beta51Scenario $s, array $dati)
{
    return $test->actingAs($s->admin)->postJson(route('admin.gestionale.immobili.passaggi.anteprima', [$s->c, $s->unita['7']]), $dati);
}

function vfcRegistra($test, Beta51Scenario $s, array $dati)
{
    $ant = vfcAnteprima($test, $s, $dati)->assertOk()->json();
    if ($ant['cancello']['richiesto'] ?? false) {
        $dati += ['ho_letto' => true, 'nota_cancello' => 'Letto: il pannello è stato controllato nella prova'];
    }

    return $test->actingAs($s->admin)->from('/passaggio')->post(route('admin.gestionale.immobili.passaggi.store', [$s->c, $s->unita['7']]), $dati);
}

function vfcNelCondominio(Beta51Scenario $s, string $chi): bool
{
    return DB::table('anagrafica_condominio')->where('anagrafica_id', $s->persona($chi)->id)->where('condominio_id', $s->c->id)->exists();
}

it('Coda 187 — il pannello della vendita dice che a Mario non resta niente nel condominio (la casella spuntata di serie); con un\'altra unità la dice', function () {
    $s = vfcScenario($this);
    expect(vfcAnteprima($this, $s, vfcModulo($s))->assertOk()->json('esce_dal_condominio'))
        ->toBe([['id' => $s->persona('mario')->id, 'nome' => 'Mario', 'resta' => []]]);

    $t = vfcScenario($this, altraUnita: true);
    expect(vfcAnteprima($this, $t, vfcModulo($t))->assertOk()->json('esce_dal_condominio'))
        ->toBe([['id' => $t->persona('mario')->id, 'nome' => 'Mario', 'resta' => ['Interno B']]]);
});

it('Coda 187 — la catena: «Togli Mario dal condominio» → la vendita scritta → Mario non è più fra le persone del condominio → l\'incasso della sua rata di prima passa e il suo estratto conto risponde', function () {
    $s = vfcScenario($this);
    $mario = $s->persona('mario');

    vfcRegistra($this, $s, vfcModulo($s, ['mario']))->assertSessionHasNoErrors()->assertRedirect()
        ->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''), 'Mario non è più fra le persone del condominio: le sue rate rimaste si incassano lo stesso.'));

    expect(vfcNelCondominio($s, 'mario'))->toBeFalse()->and(vfcNelCondominio($s, 'elsa'))->toBeTrue();
    // Le righe di titolarità restano: la sua chiusa al 30/06.
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $mario->id)->value('data_fine'))->toStartWith('2026-06-30');
    $quota = RataQuote::where('anagrafica_id', $mario->id)->orderBy('id')->firstOrFail();
    $importo = Beta51Scenario::euro((int) $quota->importo);
    $this->actingAs($s->admin)->post(route('admin.gestionale.movimenti-rate.store', [$s->c]), [
        'pagante_id' => $mario->id, 'cassa_id' => $s->cassa->id, 'data_pagamento' => '2026-07-10', 'importo_totale' => $importo, 'descrizione' => 'Bonifico',
        'eccedenza' => '0.00', 'gestione_id' => $s->gestioni['Ordinaria']['g']->id, 'dettaglio_pagamenti' => [['rata_id' => $quota->id, 'importo' => $importo]],
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect(ScritturaContabile::where('condominio_id', $s->c->id)->where('tipo_movimento', 'incasso_rata')->count())->toBe(1);
    $this->actingAs($s->admin)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s->c, $mario]))->assertOk();
});

it('Coda 187 — senza la spunta Mario resta fra le persone del condominio, e la richiesta non toglie chi non vende', function () {
    $s = vfcScenario($this);

    vfcAnteprima($this, $s, vfcModulo($s, ['bice']))->assertStatus(422)->assertJsonValidationErrors('togli_dal_condominio');
    expect(vfcNelCondominio($s, 'bice'))->toBeTrue();

    vfcRegistra($this, $s, vfcModulo($s))->assertSessionHasNoErrors()->assertRedirect();
    expect(vfcNelCondominio($s, 'mario'))->toBeTrue();
});

function vfcAnnulla($test, Beta51Scenario $s)
{
    $passaggio = \App\Models\Gestionale\Subentro::where('condominio_id', $s->c->id)->orderByDesc('id')->firstOrFail();

    return $test->actingAs($s->admin)->from('/storico')->delete(route('admin.gestionale.immobili.passaggi.annulla', [$s->c, $s->unita['7'], $passaggio]),
        ['nota_annullamento' => 'Rogito registrato per errore nella prova']);
}

it('l\'annullamento della vendita rimette fra le persone del condominio chi la vendita aveva tolto, e il messaggio lo dice', function () {
    $s = vfcScenario($this);
    vfcRegistra($this, $s, vfcModulo($s, ['mario']))->assertSessionHasNoErrors()->assertRedirect();
    expect(vfcNelCondominio($s, 'mario'))->toBeFalse();
    // La finestra dello storico lo dice prima di annullare.
    $passaggio = \App\Models\Gestionale\Subentro::where('condominio_id', $s->c->id)->orderByDesc('id')->firstOrFail();
    expect(app(\App\Actions\Subentro\AnnullaPassaggioAction::class)->effetti($passaggio))->toContain('Mario torna fra le persone del condominio.');

    vfcAnnulla($this, $s)->assertSessionHasNoErrors()->assertRedirect('/storico')
        ->assertSessionHas('message', fn ($m) => str_contains((string) ($m['message'] ?? ''), 'Mario è di nuovo fra le persone del condominio.'));

    expect(vfcNelCondominio($s, 'mario'))->toBeTrue()
        ->and(DB::table('anagrafica_condominio')->where('anagrafica_id', $s->persona('mario')->id)->where('condominio_id', $s->c->id)->count())->toBe(1)
        // Chi è entrato resta nella rubrica del condominio: l'annullamento rimette, non toglie.
        ->and(vfcNelCondominio($s, 'elsa'))->toBeTrue();
});

it('l\'annullamento non rimette chi la vendita non aveva tolto, né raddoppia chi è già stato rimesso dalla rubrica', function () {
    $s = vfcScenario($this);
    vfcRegistra($this, $s, vfcModulo($s))->assertSessionHasNoErrors()->assertRedirect();
    vfcAnnulla($this, $s)->assertSessionHasNoErrors()->assertRedirect()
        ->assertSessionHas('message', fn ($m) => ! str_contains((string) ($m['message'] ?? ''), 'di nuovo fra le persone del condominio'));
    expect(DB::table('anagrafica_condominio')->where('anagrafica_id', $s->persona('mario')->id)->where('condominio_id', $s->c->id)->count())->toBe(1);

    $t = vfcScenario($this);
    vfcRegistra($this, $t, vfcModulo($t, ['mario']))->assertSessionHasNoErrors()->assertRedirect();
    $t->persona('mario')->condomini()->attach($t->c->id);
    vfcAnnulla($this, $t)->assertSessionHasNoErrors()->assertRedirect()
        ->assertSessionHas('message', fn ($m) => ! str_contains((string) ($m['message'] ?? ''), 'di nuovo fra le persone del condominio'));
    expect(DB::table('anagrafica_condominio')->where('anagrafica_id', $t->persona('mario')->id)->where('condominio_id', $t->c->id)->count())->toBe(1);
});

it('se togliere Mario dal condominio non riesce, la vendita non resta scritta a metà: niente passaggio, le righe come prima, Mario nel condominio', function () {
    $s = vfcScenario($this);
    $righePrima = DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['7']->id)->orderBy('id')->get(['id', 'anagrafica_id', 'data_inizio', 'data_fine'])->toArray();
    // Il legame con il condominio non si può togliere: il database lo rifiuta, come farebbe un guasto nel mezzo.
    DB::statement("CREATE TRIGGER vfc_niente_stacco BEFORE DELETE ON anagrafica_condominio BEGIN SELECT RAISE(ABORT, 'stacco rifiutato'); END");

    // Nei test l'errore si rilancia (`ErroriDiagnosticabili::registra`): la pagina risponde 500, fuori dai test torna con l'errore sotto il modulo.
    vfcRegistra($this, $s, vfcModulo($s, ['mario']))->assertServerError();

    expect(DB::table('subentri')->where('immobile_id', $s->unita['7']->id)->count())->toBe(0)
        ->and(DB::table('anagrafica_immobile')->where('immobile_id', $s->unita['7']->id)->orderBy('id')->get(['id', 'anagrafica_id', 'data_inizio', 'data_fine'])->toArray())->toEqual($righePrima)
        ->and(vfcNelCondominio($s, 'mario'))->toBeTrue();
});

it('tolto dal condominio, Mario ha ancora il fascicolo per il legale e «Rimborsa il credito»: le rotte rispondono; la persona di un altro condominio ha 404', function () {
    $s = vfcScenario($this);
    $mario = $s->persona('mario');
    vfcRegistra($this, $s, vfcModulo($s, ['mario']))->assertSessionHasNoErrors()->assertRedirect();
    expect(vfcNelCondominio($s, 'mario'))->toBeFalse();
    $rotta = fn (string $nome, $chi) => route('admin.gestionale.' . $nome, ['condominio' => $s->c->id, 'anagrafica' => $chi->id]);

    $this->actingAs($s->admin)->getJson($rotta('anagrafiche.fascicolo-legale', $mario))->assertOk();
    // Senza rate scelte il fascicolo non si prepara: la risposta è la frase del modulo (422), non la pagina che non esiste.
    $this->actingAs($s->admin)->postJson($rotta('anagrafiche.fascicolo-legale.prepara', $mario), ['rate' => [], 'parti' => []])->assertStatus(422);
    // Senza quota e senza cassa il rimborso risponde con gli errori del modulo, non con 404.
    $this->actingAs($s->admin)->postJson($rotta('anagrafiche.rimborsi.store', $mario), [])->assertUnprocessable();

    $altro = Beta51Scenario::nuovo($this)->tabella('Proprietà', ['1' => 1000])->titolare('1', 'nadia')->gestione('Ordinaria');
    $nadia = $altro->persona('nadia');
    $this->actingAs($s->admin)->getJson($rotta('anagrafiche.fascicolo-legale', $nadia))->assertNotFound();
    $this->actingAs($s->admin)->postJson($rotta('anagrafiche.fascicolo-legale.prepara', $nadia), ['rate' => [], 'parti' => []])->assertNotFound();
    $this->actingAs($s->admin)->getJson(route('admin.gestionale.anagrafiche.fascicolo-legale.documento', ['condominio' => $s->c->id, 'anagrafica' => $nadia->id, 'documento' => 1]))->assertNotFound();
    $this->actingAs($s->admin)->postJson($rotta('anagrafiche.rimborsi.store', $nadia), [])->assertNotFound();
});
