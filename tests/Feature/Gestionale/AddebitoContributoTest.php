<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
// Copyright (C) Vincenzo Vecchio — KondoManager, https://github.com/vince844/kondomanager-free
// Additional terms under AGPL-3.0 section 7(b) and 7(c) apply: see the NOTICE file.

/**
 * Il contributo a una persona sola come quota del piano degli addebiti: il piano con la sua natura, nato approvato, l'addebito emesso subito; la
 * lettura comune, l'estratto conto, l'incasso parziale e la ricevuta lo leggono come una rata. Il piano non si genera, non si ricalcola, non si
 * approva, non è mai «disallineato» e l'elenco dei piani lo mostra a parte. Un addebito per persona anche quando se ne registrano tanti insieme:
 * ognuno si annulla da solo, e l'annullato si cancella. Non si annulla un addebito che un accordo di rientro riconosce, né uno di un esercizio
 * chiuso: la frase dice perché, e l'elenco dell'estratto conto lo dice prima.
 *
 * Il caso è Via Verdi (`ConsuntivoPerPersonaPosizioneScenario.php`): le quattro rate emesse il 15/01, Neri su U1 e Bianchi su U2.
 */

use App\Enums\EventoTipo;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Services\Gestionale\Debiti\LetturaDebiti;
use App\Services\Gestionale\Ricevute\RicevutaIncasso;
use App\Services\PianoRateQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/AddebitiScenario.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    adbAdmin($this);
    $this->withoutVite();
});

/** Le righe del giornale di una scrittura, con il ruolo del conto: [ruolo, verso, importo, persona, unità, rata]. */
function adbRigheDellaScrittura(int $scritturaId): array
{
    return DB::table('righe_scritture as r')->join('conti_contabili as c', 'c.id', '=', 'r.conto_contabile_id')->where('r.scrittura_id', $scritturaId)
        ->orderBy('r.id')->get(['c.ruolo', 'r.tipo_riga', 'r.importo', 'r.anagrafica_id', 'r.immobile_id', 'r.rata_id'])
        ->map(fn ($r) => [$r->ruolo, $r->tipo_riga, (int) $r->importo, $r->anagrafica_id === null ? null : (int) $r->anagrafica_id, $r->immobile_id === null ? null : (int) $r->immobile_id,
            $r->rata_id === null ? null : (int) $r->rata_id])->all();
}

it('il contributo è una rata del piano degli addebiti, emessa subito: la lettura comune, l\'estratto conto, l\'incasso parziale e la ricevuta lo leggono come le rate', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-05-01 10:00:00');
    $attesiPrima = app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 31)->incassiAttesiCents;

    $r = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Quota non assegnata: Cantina, U1', 'scadenza' => '2026-06-01']]);
    $r->assertCreated();
    $rataId = $r->json('rate.0');

    // Il piano degli addebiti: la natura, approvato senza delibera, il nome con l'anno e la gestione.
    $piano = PianoRate::degliAddebiti()->where('gestione_id', $s['g']->id)->firstOrFail();
    expect($piano->natura)->toBe('addebiti')
        ->and($piano->stato->value)->toBe('approvato')
        ->and($piano->data_delibera_assemblea)->toBeNull()
        ->and($piano->nome)->toBe('Addebiti individuali – Ordinaria 2026')
        ->and((int) $piano->esercizio_id)->toBe((int) $s['e']->id)
        ->and(PianoRate::where('gestione_id', $s['g']->id)->count())->toBe(2);

    // Una rata, una quota di Neri su U1, emessa: DARE «Crediti verso condomini» con Neri, U1 e la rata; AVERE «Gestione rate».
    $rata = Rata::findOrFail($rataId);
    $quota = adbQuota($rataId);
    expect($rata->stato)->toBe('emessa')
        ->and($rata->descrizione)->toBe('Quota non assegnata: Cantina, U1')
        ->and($quota->tipo)->toBe(RataQuote::TIPO_ADDEBITO)
        ->and((int) $quota->importo)->toBe(5000)
        ->and(adbRigheDellaScrittura((int) $quota->scrittura_contabile_id))->toBe([
            ['crediti_condomini', 'dare', 5000, (int) $s['persone']['Neri']->id, (int) $s['unita']['U1']->id, (int) $rataId],
            ['gestione_rate', 'avere', 5000, null, null, null],
        ])
        // Nessuna parola di effetto legale scritta dall'addebito: le due colonne hanno il valore di partenza della tabella, come ogni quota che non
        // le scrive, e nessuno le legge.
        ->and(DB::table('rate_quote')->where('id', $quota->id)->first(['stato_legale', 'origine_tipo', 'stato_legale_aggiornato_at']))
        ->toEqual((object) ['stato_legale' => 'certo', 'origine_tipo' => 'condominiale', 'stato_legale_aggiornato_at' => null]);

    // Il promemoria nel portale nasce con l'addebito, già emesso e visibile; nessun compito «Emettere la rata» per l'amministratore.
    $evento = Evento::where('tipo', EventoTipo::SCADENZA_RATA_CONDOMINO->value)->where('meta->context->rata_id', $rataId)->firstOrFail();
    expect($evento->meta['is_emitted'])->toBeTrue()
        ->and($evento->meta['is_published'])->toBeTrue()
        ->and($evento->anagrafiche()->pluck('anagrafiche.id')->all())->toBe([(int) $s['persone']['Neri']->id])
        ->and(Evento::where('tipo', EventoTipo::EMISSIONE_RATA->value)->where('meta->context->rata_id', $rataId)->exists())->toBeFalse();

    // Il Treasury lo attende come le rate, nei giorni che guarda (il 01/06 è a 31 giorni dal 01/05).
    expect(app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 31)->incassiAttesiCents - $attesiPrima)->toBe(5000)
        ->and(app(\App\Services\Treasury\TreasuryGuardianService::class)->perCondominio((int) $s['c']->id, null, 30)->incassiAttesiCents - $attesiPrima)->toBe(0);

    // La lettura comune il 01/07: scaduto da 30 giorni (dal 01/06 escluso al 01/07).
    $this->travelTo('2026-07-01 10:00:00');
    // L'elenco «Rate scadute» lo dice con il suo piano, e i sei mesi dalla fine dell'esercizio del piano (31/12/2026 + 6 mesi).
    $elenco = app(\App\Services\Gestionale\Debiti\ElencoRateScadute::class);
    $neri = $elenco->righe($s['c'], $elenco->filtriNormalizzati([]))->firstWhere('anagrafica_id', (int) $s['persone']['Neri']->id);
    $nellElenco = collect($neri['rate'])->firstWhere('rata_id', $rataId);
    expect([$nellElenco['piano'], $nellElenco['residuo'], $nellElenco['giorni_ritardo'], $nellElenco['sei_mesi']['piu_vicina']])
        ->toBe(['Addebiti individuali – Ordinaria 2026', 5000, 30, '2027-06-30']);
    $riga = collect(app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id)['rate'])->firstWhere('rata_id', $rataId);
    expect($riga['residuo'])->toBe(5000)->and($riga['scaduta'])->toBeTrue()->and($riga['giorni_ritardo'])->toBe(30)
        // L'estratto conto, la lettura e le quote dicono la stessa cifra per ogni persona.
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['Neri' => $s['persone']['Neri']->id]))->toBe([]);

    // L'incasso parziale di € 20,00 dalla rotta dell'incasso, sulla quota dell'addebito.
    $incasso = lcdIncassa($this, $s, 'Neri', [$quota->id => 2000], '2026-07-01');
    $riga = collect(app(LetturaDebiti::class)->perPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id)['rate'])->firstWhere('rata_id', $rataId);
    expect($riga['residuo'])->toBe(3000)
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['Neri' => $s['persone']['Neri']->id]))->toBe([]);

    // La ricevuta dice la riga dell'addebito con il suo nome: € 20,00 su € 50,00, restano € 30,00.
    $ricevuta = app(RicevutaIncasso::class)->per(\App\Models\Gestionale\ScritturaContabile::findOrFail($incasso));
    $suaRiga = collect($ricevuta['quote'])->firstWhere('quota_id', $quota->id);
    expect($suaRiga['rata'])->toBe('Quota non assegnata: Cantina, U1')
        ->and($suaRiga['piano'])->toBe('Addebiti individuali – Ordinaria 2026')
        ->and([$suaRiga['importo'], $suaRiga['imputato'], $suaRiga['dopo']])->toBe([5000, 2000, 3000]);
});

it('il piano degli addebiti non si genera, non si ricalcola, non si approva, non si elimina, non emette e non annulla l\'emissione; non è mai disallineato', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-05-01 10:00:00');
    $statoDelRiparto = app(PianoRateQuoteService::class)->statoAllineamento($s['piano']->fresh());
    $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00']])->assertCreated()->json('rate.0');
    $piano = PianoRate::degliAddebiti()->firstOrFail();
    $frase = '«Addebiti individuali – Ordinaria 2026» è il piano degli addebiti a singole persone: non si genera, non si ricalcola, non si approva e non si modifica. '
        . 'Ogni addebito si annulla dall\'estratto conto della persona.';
    $base = [$s['c'], $s['e'], $piano];

    $this->actingAs($this->user)->post(route('admin.gestionale.esercizi.piani-rate.regenerate', $base))->assertSessionHas('message', fn ($m) => $m['message'] === $frase);
    $this->actingAs($this->user)->put(route('admin.gestionale.piani-rate.update-stato', $base), ['approvato' => false])->assertSessionHas('message', fn ($m) => $m['message'] === $frase);
    $this->actingAs($this->user)->delete(route('admin.gestionale.esercizi.piani-rate.destroy', $base))->assertSessionHas('message', fn ($m) => $m['message'] === $frase);
    $this->actingAs($this->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => [$rataId], 'data_emissione' => '2026-05-01'])
        ->assertSessionHas('message', fn ($m) => $m['message'] === $frase);
    $this->actingAs($this->user)->delete(route('admin.gestionale.piani-rate.annulla-emissione', [$s['c'], $piano, $rataId]))->assertSessionHas('message', fn ($m) => $m['message'] === $frase);
    expect(fn () => app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano))->toThrow(\Illuminate\Validation\ValidationException::class, $frase);

    // Niente è cambiato: approvato, la rata emessa con la sua quota a giornale.
    expect($piano->fresh()->stato->value)->toBe('approvato')
        ->and(Rata::findOrFail($rataId)->stato)->toBe('emessa')
        ->and(adbQuota($rataId)->scrittura_contabile_id)->not->toBeNull()
        // L'atteso dai capitoli è zero e l'addebito sarebbe uno scarto: il piano degli addebiti non è mai «disallineato».
        ->and(app(PianoRateQuoteService::class)->statoAllineamento($piano->fresh()))->toBe(PianoRateQuoteService::ALLINEATO)
        // Il piano del riparto resta com'era prima dell'addebito.
        ->and(app(PianoRateQuoteService::class)->statoAllineamento($s['piano']->fresh()))->toBe($statoDelRiparto);
});

it('l\'elenco dei piani mostra il piano degli addebiti a parte, con il numero degli addebiti e il totale', function () {
    // Senza il piano del riparto: l'elenco dei piani del riparto (`PianoRateResource`) legge il JSON delle quote con una funzione che SQLite non ha.
    $s = cpcViaVerdi($this);
    DB::table('piani_rate')->where('id', $s['piano']->id)->delete();
    $this->travelTo('2026-05-01 10:00:00');
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00'], ['chi' => 'Bianchi', 'unita' => 'U2', 'euro' => '25,00']])->assertCreated();

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.piani-rate.index', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    expect($props['pianiRate'])->toBe([])
        ->and($props['pianiAddebiti'])->toBe([[
            'id' => (int) PianoRate::degliAddebiti()->value('id'), 'nome' => 'Addebiti individuali – Ordinaria 2026', 'gestione' => 'Ordinaria 2026',
            'addebiti' => 2, 'totale' => 7500,
        ]]);
});

it('tanti addebiti insieme sono una rata per persona: annullare quello di Neri con l\'incasso di Bianchi registrato lo cancella, quello incassato si storna prima', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-05-01 10:00:00');
    $rate = adbRegistra($this, $s, [
        ['chi' => 'Neri', 'unita' => 'U1', 'euro' => '25,00', 'causale' => 'Spese di sollecito'],
        ['chi' => 'Bianchi', 'unita' => 'U2', 'euro' => '25,00', 'causale' => 'Spese di sollecito'],
    ])->assertCreated()->json('rate');
    [$diNeri, $diBianchi] = $rate;
    expect(Rata::whereIn('id', $rate)->get()->map(fn (Rata $r) => $r->rateQuote()->count())->all())->toBe([1, 1]);

    // Bianchi versa il suo.
    $incasso = lcdIncassa($this, $s, 'Bianchi', [adbQuota($diBianchi)->id => 2500], '2026-05-01');
    $scritturaNeri = (int) adbQuota($diNeri)->scrittura_contabile_id;

    // Annullato quello di Neri: la scrittura se ne va, la rata e la quota sono cancellate, non tornano in bozza; il promemoria non c'è più.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $diNeri]))->assertOk();
    expect(Rata::find($diNeri))->toBeNull()
        ->and(RataQuote::where('rata_id', $diNeri)->exists())->toBeFalse()
        ->and(DB::table('scritture_contabili')->where('id', $scritturaNeri)->exists())->toBeFalse()
        ->and(DB::table('righe_scritture')->where('scrittura_id', $scritturaNeri)->exists())->toBeFalse()
        ->and(Evento::where('meta->context->rata_id', $diNeri)->exists())->toBeFalse()
        ->and(Rata::where('piano_rate_id', PianoRate::degliAddebiti()->value('id'))->where('stato', 'bozza')->exists())->toBeFalse();

    // Quello di Bianchi ha un incasso: si rifiuta, e dice di stornare prima.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $diBianchi]))->assertStatus(422)
        ->assertJsonPath('errors.addebito.0', \App\Services\Gestionale\Addebiti\AddebitiIndividuali::FRASE_NON_ANNULLABILE);
    expect(Rata::find($diBianchi))->not->toBeNull();

    // Lo storno dell'incasso (una registrazione sbagliata), poi l'annullamento.
    lcdStorna($this, $s, $incasso, ['natura_storno' => 'errore_registrazione']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $diBianchi]))->assertOk();
    expect(Rata::find($diBianchi))->toBeNull()
        ->and(lcdProvaPersone($this, $this->user, $s['c']->id, $s['e']->id, ['Neri' => $s['persone']['Neri']->id, 'Bianchi' => $s['persone']['Bianchi']->id]))->toBe([]);
});

it('un addebito non va a un inquilino né a chi non ha l\'unità; di un altro condominio è un 404', function () {
    $s = cpcViaVerdi($this, ['inquilino' => ['U1', 'Ines', 30, '2026-01-01', null]]);
    $this->travelTo('2026-05-01 10:00:00');

    adbRegistra($this, $s, [['chi' => 'Ines', 'unita' => 'U1', 'euro' => '10,00']])->assertStatus(422)
        ->assertJsonPath('errors.addebiti.0', \App\Services\Gestionale\Addebiti\AddebitiIndividuali::FRASE_INQUILINO);
    adbRegistra($this, $s, [['chi' => 'Bianchi', 'unita' => 'U1', 'euro' => '10,00']])->assertStatus(422);
    expect(PianoRate::degliAddebiti()->exists())->toBeFalse();

    $altro = cpcViaVerdi($this);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.store', [$altro['c']]), ['gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id,
        'voci' => [['anagrafica_id' => $s['persone']['Neri']->id, 'immobile_id' => $s['unita']['U1']->id, 'importo' => '10,00', 'causale' => 'x', 'scadenza' => '2026-05-01']]])
        ->assertNotFound();
    $rata = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '10,00']])->assertCreated()->json('rate.0');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$altro['c'], $rata]))->assertNotFound();
    // Una rata di un piano del riparto non è un addebito.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], lcdRata($s, 1)]))->assertNotFound();
    // Le spese non attribuite di origine di un altro condominio: 404, e niente si scrive.
    $rate = \App\Models\Gestionale\Rata::count();
    adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '1,00', 'gestione_origine_id' => $altro['g']->id, 'esercizio_origine_id' => $altro['e']->id]])->assertNotFound();
    expect(\App\Models\Gestionale\Rata::count())->toBe($rate);
});

it('un addebito che un accordo di rientro riconosce non si annulla: la frase lo dice, la riga dell\'accordo resta, e l\'estratto conto lo dice prima', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-05-01 10:00:00');
    $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-05-01']])->assertCreated()->json('rate.0');

    // Il 20/05 Neri firma un accordo di rientro sul contributo e sulla rata 4 (€ 50,00 + € 300,00).
    $this->travelTo('2026-05-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => (int) $s['persone']['Neri']->id, 'data_accordo' => '2026-05-20', 'fino_al' => '2026-09-30',
        'rate' => [['rata_id' => $rataId, 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '50,00'],
            ['rata_id' => (int) lcdRata($s, 4), 'immobile_id' => (int) $s['unita']['U1']->id, 'importo' => '300,00']],
        'autorizzato_assemblea' => '0', 'nota' => 'Accordo firmato in studio',
    ])->assertCreated();
    $righe = fn () => DB::table('accordo_rientro_rate')->orderBy('rata_id')->get(['rata_id', 'importo_riconosciuto'])->map(fn ($r) => [(int) $r->rata_id, (int) $r->importo_riconosciuto])->all();
    $prima = $righe();
    $frase = 'Questo addebito è nell\'accordo di rientro del 20/05/2026, che lo riconosce: annullarlo toglierebbe la sua riga dall\'accordo, e un accordo non si '
        . 'cancella. L\'addebito resta.';

    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rataId]))->assertStatus(422)->assertJsonPath('errors.addebito.0', $frase);
    expect($righe())->toBe($prima)->and($prima)->toHaveCount(2)
        ->and(Rata::find($rataId))->not->toBeNull();
    $suo = collect(app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class)->dellaPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id))->firstWhere('rata_id', $rataId);
    expect([$suo['annullabile'], $suo['perche_resta']])->toBe([false, $frase]);

    // Chiuso l'accordo, la sua riga resta, e l'addebito pure.
    \App\Models\Gestionale\AccordoRientro::query()->update(['chiuso_il' => '2026-06-01']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rataId]))->assertStatus(422)->assertJsonPath('errors.addebito.0', $frase);
    expect($righe())->toBe($prima);
});

it('un addebito di un esercizio chiuso non si annulla: la sua scrittura resta in quell\'esercizio, come le altre', function () {
    $s = cpcViaVerdi($this);
    cpcRateEIncassi($this, $s);
    $this->travelTo('2026-11-02 10:00:00');
    $rataId = adbRegistra($this, $s, [['chi' => 'Neri', 'unita' => 'U1', 'euro' => '50,00', 'causale' => 'Contributo cantina', 'scadenza' => '2026-11-20']])->assertCreated()->json('rate.0');
    $scrittura = (int) adbQuota($rataId)->scrittura_contabile_id;
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);

    $this->travelTo('2027-03-20 10:00:00');
    $this->actingAs($this->user)->postJson(route('admin.gestionale.addebiti.annulla', [$s['c'], $rataId]))->assertStatus(422)
        ->assertJsonPath('errors.addebito.0', 'L\'esercizio «Esercizio 2026» è chiuso: l\'addebito resta nei suoi conti.');
    expect(DB::table('scritture_contabili')->where('id', $scrittura)->whereNull('deleted_at')->exists())->toBeTrue()
        ->and(DB::table('righe_scritture')->where('scrittura_id', $scrittura)->count())->toBe(2)
        ->and(Rata::find($rataId))->not->toBeNull();
    $suo = collect(app(\App\Services\Gestionale\Addebiti\AddebitiIndividuali::class)->dellaPersona((int) $s['c']->id, (int) $s['persone']['Neri']->id))->firstWhere('rata_id', $rataId);
    expect([$suo['annullabile'], $suo['perche_resta']])->toBe([false, 'L\'esercizio «Esercizio 2026» è chiuso: l\'addebito resta nei suoi conti.']);
});
