<?php

/**
 * L'incasso da terzi: l'affitto dell'antenna, gli interessi del conto, un rimborso. Un movimento suo, che entra in una cassa reale e va sulla voce
 * di entrata del piano dei conti, in diminuzione dei costi della voce (DARE la cassa, AVERE il mastro della voce, con la voce).
 *
 * Il caso è quello dell'antenna: spese € 1.200,00 ed entrata € 240,00, quote chieste € 960,00 (12 rate emesse), le spese pagate con una
 * regolazione immediata. Prima dell'incasso la banca contabile è € 8.800,00 e il risultato di gestione dice € −240,00; con l'incasso la banca è
 * € 9.040,00, i costi € 960,00, il risultato € 0,00. Le cifre vengono dalla regola (la banca parte da € 10.000,00 di apertura) e non dal codice.
 *
 * Fonte dell'atteso: la scelta dell'amministratore sulla forma dell'incasso (in diminuzione della voce, senza un conto di ricavo), il protocollo
 * `ENT`, lo storno con la natura (registrazione sbagliata: vale dal giorno dell'incasso; tornato indietro: vale da oggi), la cassa reale (un fondo
 * non si offre: il registro di contabilità non vedrebbe l'entrata) e la controparte dal soggetto che ha versato.
 */

use App\Helpers\DateHelper;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Gestionale\Cassa;
use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\RegistroContabilitaService;
use App\Services\Gestionale\SpesaPerVoceService;
use App\Services\Gestionale\StatoPatrimonialePaginaService;
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

/** L'antenna: piano da 12 rate approvato ed emesso (quote € 960,00), le spese pagate con una regolazione da € 1.200,00 il 10/03, un gestore in rubrica. */
function idtScenario($test): array
{
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $piano = be52PianoDallaRotta($test, $s);
    $test->actingAs($test->user)->put(route('admin.gestionale.piani-rate.update-stato', [$s['c'], $s['e'], $piano]), ['approvato' => true, 'data_delibera_assemblea' => '2026-01-02'])->assertSessionHasNoErrors();
    $ids = DB::table('rate')->where('piano_rate_id', $piano->id)->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $ids, 'data_emissione' => '2026-01-31', 'invia_notifiche' => false])->assertSessionHasNoErrors();
    $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $s['spesa']->id, 'cassa_id' => $s['cassa']->id, 'fornitore_id' => null,
        'data_operazione' => '2026-03-10', 'causale' => 'Spese generali pagate', 'importo' => 1200.00,
    ])->assertSessionHasNoErrors();
    $s['piano'] = $piano;
    $s['gestore'] = tap(Anagrafica::forceCreate(['nome' => 'Torri del Nord Spa', 'email' => "torri{$s['seq']}@test.it", 'indirizzo' => 'Via Po 1',
        'codice_fiscale' => 'TORRINORD'.str_pad((string) $s['seq'], 7, '0', STR_PAD_LEFT)]), fn ($a) => $a->condomini()->syncWithoutDetaching([$s['c']->id]));

    return $s;
}

/** Il permesso di eliminare le anagrafiche, per la prova della rubrica. */
function idtPuoEliminare($test): void
{
    $test->user->givePermissionTo(\Spatie\Permission\Models\Permission::firstOrCreate(['name' => \App\Enums\Permission::DELETE_ANAGRAFICHE->value, 'guard_name' => 'web']));
}

function idtCorpo(array $s, array $extra = []): array
{
    return array_merge([
        'esercizio_id' => $s['e']->id, 'conto_id' => $s['entrata']->id, 'cassa_id' => $s['cassa']->id, 'data_operazione' => '2026-02-15',
        'importo' => 240.00, 'anagrafica_id' => $s['gestore']->id, 'causale' => 'Affitto antenna 2026',
    ], $extra);
}

function idtRegistra($test, array $s, array $extra = [])
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), idtCorpo($s, $extra));
}

function idtPagina(array $s, string $alla = '2026-12-31'): array
{
    return app(StatoPatrimonialePaginaService::class)->costruisci($s['c'], $s['e'], $alla);
}

function idtIncasso(array $s): ScritturaContabile
{
    return ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_diverso')->firstOrFail();
}

it('senza l\'incasso la banca è € 8.800,00 e il risultato € −240,00: il punto di partenza', function () {
    $s = idtScenario($this);
    $p = idtPagina($s);

    expect($p['liquidita']['liquidita_totale'])->toBe(880000)
        ->and($p['risultato_gestione']['risultato'])->toBe(-24000)
        ->and($p['risultato_gestione']['costi'])->toBe(120000);
});

it('l\'incasso di € 240,00 sull\'antenna: banca € 9.040,00, costi € 960,00, risultato € 0,00, lo speso della voce di entrata −€ 240,00', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $p = idtPagina($s);

    expect($p['liquidita']['liquidita_totale'])->toBe(904000)
        ->and($p['risultato_gestione']['costi'])->toBe(96000)
        ->and($p['risultato_gestione']['quote_emesse'])->toBe(96000)
        ->and($p['risultato_gestione']['risultato'])->toBe(0)
        ->and((new SpesaPerVoceService)->perEsercizio($s['e']))->toBe([$s['spesa']->id => 120000, $s['entrata']->id => -24000]);
});

it('la scrittura: DARE la cassa con chi ha versato, AVERE il mastro della voce con la voce, la gestione della voce, oggi come registrazione', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $righe = $sc->righe()->orderBy('id')->get();

    expect($sc->gestione_id)->toBe($s['g']->id)
        ->and($sc->esercizio_id)->toBe($s['e']->id)
        ->and($sc->data_competenza->format('Y-m-d'))->toBe('2026-02-15')
        ->and($sc->data_registrazione->format('Y-m-d'))->toBe('2026-11-07')
        ->and($sc->causale)->toBe('Affitto antenna 2026')
        ->and($righe)->toHaveCount(2)
        ->and([$righe[0]->tipo_riga, $righe[0]->conto_contabile_id, $righe[0]->cassa_id, $righe[0]->importo, $righe[0]->anagrafica_id, $righe[0]->voce_spesa_id])
            ->toBe(['dare', $s['cassa']->conto_contabile_id, $s['cassa']->id, 24000, $s['gestore']->id, null])
        ->and([$righe[1]->tipo_riga, $righe[1]->conto_contabile_id, $righe[1]->importo, $righe[1]->voce_spesa_id])
            ->toBe(['avere', $s['entrata']->conto_contabile_id, 24000, $s['entrata']->id]);
});

it('nel registro di contabilità la riga «Incasso da terzi», entrata € 240,00, la controparte e il protocollo ENT', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $riga = collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('descrizione', 'Affitto antenna 2026');

    expect($riga)->not->toBeNull()
        ->and($riga['tipo_movimento_label'])->toBe('Incasso da terzi')
        ->and($riga['entrata'])->toBe(24000)
        ->and($riga['uscita'])->toBeNull()
        ->and($riga['controparte'])->toBe('Torri del Nord Spa')
        ->and($riga['protocollo'])->toBe('ENT-2026-00001');
});

it('l\'estratto conto di chi ha versato non vede l\'incasso: legge solo i mastri dei condòmini', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$s['c'], $s['gestore']]))->assertOk()->viewData('page')['props'];

    expect($props['timeline'])->toBe([]);
});

it('senza chi ha versato si registra lo stesso, e il registro lascia la controparte vuota', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s, ['anagrafica_id' => null, 'causale' => 'Accredito senza nome'])->assertSessionHasNoErrors();
    $riga = collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('descrizione', 'Accredito senza nome');

    expect($riga['entrata'])->toBe(24000)
        ->and($riga['controparte'])->toBeNull();
});

it('su una voce di entrata creata con preventivo zero si registra, e la voce ha lo speso dell\'incasso', function () {
    $s = idtScenario($this);
    $rimborsi = be52Voce($this, $s, $s['pc'], 'Rimborsi', 'entrata', '0,00');
    idtRegistra($this, $s, ['conto_id' => $rimborsi->id, 'importo' => 800.00, 'causale' => 'Rimborso assicurazione'])->assertSessionHasNoErrors();

    expect((new SpesaPerVoceService)->perEsercizio($s['e'])[$rimborsi->id])->toBe(-80000);
});

it('su una cassa fondo è rifiutato: il registro non vedrebbe l\'entrata', function () {
    $s = idtScenario($this);
    $fondo = be52Fondo($s, 'Fondo riserva');
    $cassaFondo = Cassa::where('conto_contabile_id', $fondo->id)->firstOrFail();
    idtRegistra($this, $s, ['cassa_id' => $cassaFondo->id])->assertSessionHasErrors('cassa_id');

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('su una voce di spesa è rifiutato, con la frase sul campo della voce', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s, ['conto_id' => $s['spesa']->id])->assertSessionHasErrors([
        'conto_id' => '«Spese generali» è una voce di spesa: l\'incasso da terzi va su una voce di entrata. Scegli una voce di entrata, o creala con preventivo zero.',
    ]);

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('una voce o una cassa di un altro condominio sono rifiutate, e la data futura pure', function () {
    $s = idtScenario($this);
    $altro = idtScenario($this);
    idtRegistra($this, $s, ['conto_id' => $altro['entrata']->id])->assertSessionHasErrors('conto_id');
    idtRegistra($this, $s, ['cassa_id' => $altro['cassa']->id])->assertSessionHasErrors('cassa_id');
    idtRegistra($this, $s, ['data_operazione' => '2026-11-08'])->assertSessionHasErrors('data_operazione');

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('lo storno (tornato indietro) riporta la banca a € 8.800,00 e vale da oggi; l\'originale resta e porta la natura', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]), [
        'natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore',
    ])->assertSessionHasNoErrors();
    $storno = ScritturaContabile::where('scrittura_padre_id', $sc->id)->firstOrFail();

    expect(idtPagina($s)['liquidita']['liquidita_totale'])->toBe(880000)
        ->and($storno->tipo_movimento->value)->toBe('storno_incasso_diverso')
        ->and($storno->data_competenza->format('Y-m-d'))->toBe('2026-11-07')
        ->and($storno->numero_protocollo)->toStartWith('STO-2026-')
        ->and($sc->fresh()->stato)->toBe('registrata')
        ->and($sc->fresh()->natura_storno)->toBe('versamento_tornato')
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['entrata']->id])->toBe(0)
        ->and(collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('descrizione', 'Affitto antenna 2026')['stornata'])->toBeTrue();
});

it('lo storno (registrazione sbagliata) vale dal giorno dell\'incasso, con l\'esercizio aperto', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]), [
        'natura_storno' => 'errore_registrazione', 'motivo' => 'Importo sbagliato, da registrare di nuovo',
    ])->assertSessionHasNoErrors();
    $storno = ScritturaContabile::where('scrittura_padre_id', $sc->id)->firstOrFail();

    expect($storno->data_competenza->format('Y-m-d'))->toBe('2026-02-15')
        ->and(idtPagina($s, '2026-02-28')['liquidita']['liquidita_totale'])->toBe(1000000);
});

it('lo storno senza la natura, senza il motivo, o il secondo storno sono rifiutati', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $url = route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]);
    $this->actingAs($this->user)->post($url, ['motivo' => 'Bonifico richiamato dal gestore'])->assertSessionHasErrors('natura_storno');
    $this->actingAs($this->user)->post($url, ['natura_storno' => 'versamento_tornato'])->assertSessionHasErrors('motivo');
    $this->actingAs($this->user)->post($url, ['natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore'])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post($url, ['natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore'])
        ->assertSessionHas('message', ['type' => 'error', 'message' => "L'incasso {$sc->numero_protocollo} è già stato stornato."]);

    expect(ScritturaContabile::where('scrittura_padre_id', $sc->id)->count())->toBe(1);
});

it('lo storno di un incasso da un altro condominio risponde 404, e la rotta storna solo gli incassi da terzi', function () {
    $s = idtScenario($this);
    $altro = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$altro['c'], $sc->id]), [
        'natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore',
    ])->assertNotFound();
    $regolazione = ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'regolazione_immediata')->firstOrFail();
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $regolazione]), [
        'natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore',
    ]);

    expect(ScritturaContabile::where('scrittura_padre_id', $sc->id)->count())->toBe(0)
        ->and(ScritturaContabile::where('scrittura_padre_id', $regolazione->id)->count())->toBe(0);
});

it('alle 00:30 di Roma del 01/01 «oggi» è il nuovo anno: la data del 01/01 si accetta e lo storno da oggi vale dal 01/01', function () {
    $s = idtScenario($this);
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    expect(DateHelper::oggiUtente())->toBe('2027-01-01');
    idtRegistra($this, $s, ['data_operazione' => '2027-01-01', 'causale' => 'Interessi attivi'])->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]), [
        'natura_storno' => 'versamento_tornato', 'motivo' => 'Accredito respinto dalla banca',
    ])->assertSessionHasNoErrors();

    expect($sc->data_registrazione->format('Y-m-d'))->toBe('2027-01-01')
        ->and(ScritturaContabile::where('scrittura_padre_id', $sc->id)->firstOrFail()->data_competenza->format('Y-m-d'))->toBe('2027-01-01');
});

it('la pagina offre solo le voci di entrata del condominio, foglie con il mastro, e solo le casse reali', function () {
    $s = idtScenario($this);
    $fondo = be52Fondo($s, 'Fondo riserva');
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.incassi-da-terzi.create', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect(collect($props['voci'])->pluck('id')->all())->toBe([$s['entrata']->id])
        ->and(collect($props['casse'])->pluck('id')->all())->toBe([$s['cassa']->id])
        ->and($props['prossimo_protocollo'])->toBe('ENT-2026-00001')
        ->and(collect($props['anagrafiche'])->pluck('nome')->all())->toContain('Torri del Nord Spa');
});

it('la pagina della scrittura dice da quale giorno varrebbe lo storno per ognuna delle due nature, in aaaa-mm-gg', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.scritture.show', [$s['c'], idtIncasso($s)]))->assertOk()->viewData('page')['props'];

    expect($props['storno_vale_dal'])->toBe(['errore_registrazione' => '2026-02-15', 'versamento_tornato' => '2026-11-07']);
});

it('le rotte nuove stanno dietro il pannello: senza accesso non si apre il modulo e non si registra', function () {
    $s = idtScenario($this);
    $this->post('/logout');
    $esterno = \App\Models\User::factory()->create();
    $this->actingAs($esterno)->get(route('admin.gestionale.incassi-da-terzi.create', [$s['c']]))->assertForbidden();
    $this->actingAs($esterno)->post(route('admin.gestionale.incassi-da-terzi.store', [$s['c']]), idtCorpo($s))->assertForbidden();

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('chi ha versato un incasso da terzi non si cancella dalla rubrica: il registro di contabilità lo nomina', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    // Il gestore di solito non è del condominio: la guardia «ha un condominio» non lo fermerebbe.
    $s['gestore']->condomini()->detach();
    idtPuoEliminare($this);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $s['gestore']))
        ->assertSessionHas('message', ['type' => 'error', 'message' => 'Questa anagrafica compare come «chi ha versato» in un incasso da terzi: non si può eliminare, perché il registro di contabilità la nomina.']);

    expect(Anagrafica::find($s['gestore']->id))->not->toBeNull()
        ->and(collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('descrizione', 'Affitto antenna 2026')['controparte'])->toBe('Torri del Nord Spa');
});

it('anche quando l\'incasso è stornato: chi ha versato resta nominato dall\'incasso e dal suo storno', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], idtIncasso($s)]), [
        'natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore',
    ])->assertSessionHasNoErrors();
    $s['gestore']->condomini()->detach();
    idtPuoEliminare($this);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $s['gestore']))->assertSessionHas('message', fn ($m) => $m['type'] === 'error');

    expect(Anagrafica::find($s['gestore']->id))->not->toBeNull();
});

it('controprova: un\'anagrafica senza incassi da terzi né condominio si cancella come prima', function () {
    $s = idtScenario($this);
    $s['gestore']->condomini()->detach();
    idtPuoEliminare($this);
    $this->actingAs($this->user)->from('/anagrafiche')->delete(route('admin.anagrafiche.destroy', $s['gestore']))->assertSessionHas('message', fn ($m) => $m['type'] === 'success');

    expect(Anagrafica::find($s['gestore']->id))->toBeNull();
});

it('con l\'esercizio chiuso l\'incasso è rifiutato sul campo dell\'esercizio, e nessuna scrittura nasce', function () {
    $s = idtScenario($this);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    idtRegistra($this, $s)->assertSessionHasErrors(['esercizio_id' => "L'esercizio scelto non esiste o non è aperto."]);

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('lo storno di un incasso il cui esercizio è stato chiuso va nell\'esercizio aperto, vale da oggi e lo dice in causale (00:30 di Roma)', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $e27 = \App\Models\Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $this->travelTo(Carbon::parse('2027-02-10 00:30', 'Europe/Rome'));
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]), [
        'natura_storno' => 'errore_registrazione', 'motivo' => 'Importo sbagliato, da registrare di nuovo',
    ])->assertSessionHasNoErrors();
    $storno = ScritturaContabile::where('scrittura_padre_id', $sc->id)->firstOrFail();

    expect($storno->esercizio_id)->toBe($e27->id)
        ->and($storno->data_competenza->format('Y-m-d'))->toBe('2027-02-10')
        ->and($storno->causale)->toEndWith('Originale nell\'esercizio «Esercizio 2026».')
        ->and($storno->numero_protocollo)->toStartWith('STO-2027-');
});

it('alle 00:30 di Roma del 01/01 il protocollo prende l\'anno nuovo: ENT-2027-00001', function () {
    $s = idtScenario($this);
    $this->travelTo(Carbon::parse('2027-01-01 00:30', 'Europe/Rome'));
    idtRegistra($this, $s, ['data_operazione' => '2027-01-01', 'causale' => 'Interessi attivi'])->assertSessionHasNoErrors();

    expect(idtIncasso($s)->numero_protocollo)->toBe('ENT-2027-00001');
});

function idtVociOfferte($test, array $s, string $query = ''): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.incassi-da-terzi.create', [$s['c']]).$query)->assertOk()->viewData('page')['props'];
}

it('una voce di entrata di una gestione che non è dell\'esercizio non si offre e non riceve l\'incasso', function () {
    $s = idtScenario($this);
    $t = be52GestioneStraordinaria($s);
    $voce = be52Voce($this, $s, $t['pc'], 'Contributo del tetto', 'entrata', '0,00');
    DB::table('esercizio_gestione')->where('gestione_id', $t['g']->id)->delete();

    expect(collect(idtVociOfferte($this, $s)['voci'])->pluck('id')->all())->not->toContain($voce->id);
    idtRegistra($this, $s, ['conto_id' => $voce->id])->assertSessionHasErrors(['incasso_non_ammesso' => "La voce «Contributo del tetto» non può ricevere un incasso: deve essere una voce senza sottovoci, con un conto contabile, di una gestione dell'esercizio «Esercizio 2026»."]);

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('una voce di entrata con le sottovoci, o senza conto contabile, non si offre e non riceve l\'incasso', function () {
    $s = idtScenario($this);
    $frase = fn ($nome) => "La voce «{$nome}» non può ricevere un incasso: deve essere una voce senza sottovoci, con un conto contabile, di una gestione dell'esercizio «Esercizio 2026».";
    \App\Models\Gestionale\Conto::create(['piano_conto_id' => $s['pc']->id, 'parent_id' => $s['entrata']->id, 'conto_contabile_id' => $s['entrata']->conto_contabile_id,
        'nome' => 'Antenna sud', 'tipo' => 'entrata', 'importo' => 0, 'is_capitolo' => false]);
    $senzaMastro = be52Voce($this, $s, $s['pc'], 'Interessi attivi', 'entrata', '0,00');
    DB::table('conti')->where('id', $senzaMastro->id)->update(['conto_contabile_id' => null]);
    $offerte = collect(idtVociOfferte($this, $s)['voci'])->pluck('nome')->all();

    expect($offerte)->not->toContain('Affitto antenna')->and($offerte)->not->toContain('Interessi attivi')->and($offerte)->toContain('Antenna sud');
    idtRegistra($this, $s)->assertSessionHasErrors(['incasso_non_ammesso' => $frase('Affitto antenna')]);
    idtRegistra($this, $s, ['conto_id' => $senzaMastro->id])->assertSessionHasErrors(['incasso_non_ammesso' => $frase('Interessi attivi')]);

    expect(ScritturaContabile::where('tipo_movimento', 'incasso_diverso')->count())->toBe(0);
});

it('lo storno ricopia chi ha versato: il registro nomina la controparte anche nella riga dello storno', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $sc = idtIncasso($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], $sc]), [
        'natura_storno' => 'versamento_tornato', 'motivo' => 'Bonifico richiamato dal gestore',
    ])->assertSessionHasNoErrors();
    $storno = ScritturaContabile::where('scrittura_padre_id', $sc->id)->firstOrFail();
    $riga = collect(app(RegistroContabilitaService::class)->registro($s['e']))->firstWhere('protocollo', $storno->numero_protocollo);

    expect($storno->righe()->pluck('anagrafica_id')->unique()->values()->all())->toBe([$s['gestore']->id])
        ->and($riga['controparte'])->toBe('Torri del Nord Spa');
});

it('la voce arrivata dal collegamento (?voce=) è scelta solo se è fra quelle offerte', function () {
    $s = idtScenario($this);

    expect(idtVociOfferte($this, $s, '?voce='.$s['entrata']->id)['voce_scelta'])->toBe($s['entrata']->id)
        ->and(idtVociOfferte($this, $s, '?voce='.$s['spesa']->id)['voce_scelta'])->toBeNull()
        ->and(idtVociOfferte($this, $s, '?voce=abc')['voce_scelta'])->toBeNull();
});

it('lo storno senza la natura dice le due date, compresa quella di un esercizio chiuso', function () {
    $s = idtScenario($this);
    idtRegistra($this, $s)->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.incassi-da-terzi.storno', [$s['c'], idtIncasso($s)]), ['motivo' => 'Bonifico richiamato dal gestore'])
        ->assertSessionHasErrors(['natura_storno' => 'Scegli perché storni questo incasso: registrazione sbagliata (vale dal giorno dell\'incasso, o da oggi se il suo esercizio è chiuso) o incasso tornato indietro (vale da oggi).']);
});
