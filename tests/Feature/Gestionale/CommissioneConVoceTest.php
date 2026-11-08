<?php

/**
 * La commissione del pagamento al fornitore ha una voce, scelta dall'amministratore: la riga DARE va sul mastro della voce, con la voce, come la
 * regolazione immediata. Così entra nel consuntivo della voce (e da lì nel riparto e nel conguaglio), invece di restare sulle «Spese bancarie»
 * di sistema senza voce, fuori da tutto.
 *
 * I tre punti che scrivono la commissione: la registrazione (la voce è obbligatoria quando la commissione è più di zero, fra le voci di spesa della
 * gestione del pagamento), la modifica (riscrive la riga con la voce; un pagamento scritto prima senza voce, se la commissione non cambia, può restare
 * senza voce, com'era, e se la commissione cambia la voce si chiede) e lo storno (ricopia le righe com'erano, con la voce o senza, e non la chiede).
 * Nessun controllo di budget sulla commissione. La voce proposta è quella dell'ultima commissione del condominio in quella gestione; se quella voce
 * non c'è più, il campo resta vuoto.
 *
 * Caso: fattura € 1.000,00 sulla voce «Spese generali», pagata con una commissione di € 2,50 sulla voce «Spese bancarie» (preventivo € 150,00).
 * Le cifre vengono dalla regola (la commissione sale sul consuntivo della sua voce), non dal codice.
 */

use App\Models\Gestionale\ContoContabile;
use App\Models\Gestionale\FatturaPassiva;
use App\Models\Gestionale\PagamentoFornitore;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\RigaScrittura;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\SpesaPerVoceService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

const CVC_SENZA_VOCE = 'Scegli la voce della commissione: senza una voce la commissione resta fuori dal consuntivo delle voci.';

beforeEach(function () {
    $this->user = be52Utente();
    // Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
    $this->withoutVite();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
    $this->travelTo(Carbon::parse('2026-11-07 10:00', 'Europe/Rome'));
});

/** Il condominio dell'antenna con la voce «Spese bancarie» (€ 150,00), un fornitore e una fattura da € 1.000,00 su «Spese generali». */
function cvcScenario($test, string $numero = 'FT-290'): array
{
    $s = be52Scenario($test);
    app(\App\Actions\Cassa\RegistraAperturaCassaAction::class)->execute($s['cassa']->fresh());
    $s['bancarie'] = be52Voce($test, $s, $s['pc'], 'Spese bancarie', 'spesa', '150,00');
    $s['fornitore'] = be52Fornitore();
    $s['fattura'] = cvcFattura($test, $s, $numero);

    return $s;
}

function cvcFattura($test, array $s, string $numero, float $euro = 1000.00): FatturaPassiva
{
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]), be52PayloadFattura($s, $s['fornitore'], $s['spesa']->id, $euro, [], $numero))->assertSessionHasNoErrors();

    return FatturaPassiva::where('numero_documento', $numero)->where('condominio_id', $s['c']->id)->firstOrFail();
}

function cvcCorpo(array $s, FatturaPassiva $fattura, array $extra = []): array
{
    return array_merge([
        'fornitore_id' => $s['fornitore']->id, 'esercizio_id' => $s['e']->id, 'conto_corrente_id' => $s['cassa']->conto_contabile_id, 'data_pagamento' => '2026-04-10',
        'metodo_pagamento' => 'bonifico', 'iban_beneficiario' => 'IT60X0542811101000000123456', 'importo_commissioni_cents' => 250, 'bonifico_parlante' => false,
        'voce_commissione_id' => $s['bancarie']->id,
        'allow_overdraft' => true, 'iban_confermato_manualmente' => true, 'conferma_duplicato_verificato' => true,
        'allocazioni' => [['fattura_id' => $fattura->id, 'tipo' => 'pagamento', 'importo_allocato_cents' => (int) $fattura->fresh()->netto_a_pagare]],
    ], $extra);
}

function cvcPaga($test, array $s, ?FatturaPassiva $fattura = null, array $extra = [])
{
    return $test->actingAs($test->user)->post(route('admin.gestionale.pagamenti-fornitori.store', [$s['c']]), cvcCorpo($s, $fattura ?? $s['fattura'], $extra));
}

function cvcPagamento(array $s): PagamentoFornitore
{
    return PagamentoFornitore::where('condominio_id', $s['c']->id)->whereNull('pagamento_padre_id')->latest('id')->firstOrFail();
}

/** Le righe DARE della scrittura che non sono il debito verso il fornitore: la commissione. */
function cvcRigaCommissione(ScritturaContabile $sc): ?RigaScrittura
{
    $debiti = ContoContabile::where('condominio_id', $sc->condominio_id)->where('ruolo', 'debiti_fornitori')->value('id');

    return $sc->righe()->where('conto_contabile_id', '!=', $debiti)->where('tipo_riga', 'dare')->first()
        ?? $sc->righe()->where('conto_contabile_id', '!=', $debiti)->whereNull('cassa_id')->first();
}

/** Un pagamento scritto prima della beta: la commissione su «Spese bancarie» di sistema, senza voce. */
function cvcRendiVecchio(PagamentoFornitore $p): void
{
    $sistema = ContoContabile::where('condominio_id', $p->condominio_id)->where('ruolo', 'spese_bancarie')->value('id');
    $riga = cvcRigaCommissione($p->scrittura);
    DB::table('righe_scritture')->where('id', $riga->id)->update(['conto_contabile_id' => $sistema, 'voce_spesa_id' => null]);
}

/** Il corpo della pagina di modifica, aperta e rimandata senza toccare niente. */
function cvcCorpoModifica($test, array $s, PagamentoFornitore $p, array $cambi = []): array
{
    $props = $test->actingAs($test->user)->get(route('admin.gestionale.pagamenti-fornitori.edit', [$s['c'], $p]))->assertOk()->viewData('page')['props'];
    $pp = $props['pagamento'];

    return array_merge([
        'conto_corrente_id' => $pp['conto_corrente']['id'], 'data_pagamento' => $pp['data_pagamento'], 'metodo_pagamento' => $pp['metodo_pagamento'],
        'importo_commissioni_cents' => $pp['importo_commissione'], 'importo_lordo_cents' => $pp['importo_lordo'], 'importo_ritenuta_cents' => $pp['importo_ritenuta'],
        'importo_netto_cents' => $pp['importo_netto'], 'bonifico_parlante' => (bool) $pp['bonifico_parlante'], 'tipo_detrazione' => null,
        'voce_commissione_id' => $props['commissione']['voce_id'], 'allow_overdraft' => true,
    ], $cambi);
}

it('la commissione con la voce «Spese bancarie»: DARE sul mastro della voce, con la voce; il consuntivo della voce sale di € 2,50', function () {
    $s = cvcScenario($this);
    $prima = (new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id] ?? 0;
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $riga = cvcRigaCommissione(cvcPagamento($s)->scrittura);

    expect($prima)->toBe(0)
        ->and([$riga->tipo_riga, $riga->importo, $riga->conto_contabile_id, $riga->voce_spesa_id])
            ->toBe(['dare', 250, $s['bancarie']->conto_contabile_id, $s['bancarie']->id])
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id])->toBe(250)
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['spesa']->id])->toBe(100000);
});

it('la commissione senza voce è rifiutata con la frase, e il pagamento non si scrive', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s, null, ['voce_commissione_id' => null])->assertSessionHasErrors(['voce_commissione_id' => CVC_SENZA_VOCE]);

    expect(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('senza commissione la voce non serve, e nessuna riga di commissione si scrive', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s, null, ['importo_commissioni_cents' => 0, 'voce_commissione_id' => null])->assertSessionHasNoErrors();

    expect(cvcPagamento($s)->scrittura->righe()->count())->toBe(2);
});

it('una voce di un\'altra gestione è rifiutata', function () {
    $s = cvcScenario($this);
    $straordinaria = be52GestioneStraordinaria($s);
    $altra = be52Voce($this, $s, $straordinaria['pc'], 'Spese bancarie del tetto', 'spesa', '50,00');

    expect((int) $straordinaria['g']->id)->not->toBe((int) $s['g']->id);
    cvcPaga($this, $s, null, ['voce_commissione_id' => $altra->id])->assertSessionHasErrors('voce_commissione_id');

    expect(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('la commissione di un pagamento della straordinaria sulle spese bancarie dell\'ordinaria è rifiutata, e il rifiuto dice la via d\'uscita: la commissione a zero e una regolazione immediata', function () {
    $s = cvcScenario($this);
    $tetto = be52GestioneStraordinaria($s);
    $lavori = be52Voce($this, $s, $tetto['pc'], 'Lavori tetto', 'spesa', '2.000,00');
    $fattura = cvcFattura($this, array_merge($s, ['g' => $tetto['g'], 'spesa' => $lavori]), 'SAL-1', 1000.00);
    $risposta = cvcPaga($this, $s, $fattura, ['voce_commissione_id' => $s['bancarie']->id]);

    $risposta->assertSessionHasErrors('voce_commissione_id');
    expect(session('errors')->first('voce_commissione_id'))
        ->toEndWith('Per metterla su una voce di un\'altra gestione, per esempio le spese bancarie dell\'ordinaria, lascia la commissione a zero e registrala con una regolazione immediata.')
        ->and(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('una voce di entrata è rifiutata: la commissione è una spesa', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s, null, ['voce_commissione_id' => $s['entrata']->id])->assertSessionHasErrors('voce_commissione_id');

    expect(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('una voce di un altro condominio è rifiutata', function () {
    $s = cvcScenario($this);
    $altro = cvcScenario($this, 'FT-ALTRO');
    cvcPaga($this, $s, null, ['voce_commissione_id' => $altro['bancarie']->id])->assertSessionHasErrors('voce_commissione_id');

    expect(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('nessun controllo di budget: una commissione oltre il preventivo della voce si registra', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s, null, ['importo_commissioni_cents' => 20000])->assertSessionHasNoErrors();

    expect((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id])->toBe(20000);
});

it('la voce proposta è quella dell\'ultima commissione del condominio in quella gestione', function () {
    $s = cvcScenario($this);
    $proposta = fn () => collect($this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.create', [$s['c']]))->assertOk()
        ->viewData('page')['props']['voce_commissione_proposta'])->firstWhere('gestione_id', $s['g']->id)['voce_id'] ?? null;

    expect($proposta())->toBeNull();
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    expect($proposta())->toBe($s['bancarie']->id);
    $altraVoce = be52Voce($this, $s, $s['pc'], 'Bolli e commissioni', 'spesa', '30,00');
    cvcPaga($this, $s, cvcFattura($this, $s, 'FT-291', 100.00), ['voce_commissione_id' => $altraVoce->id])->assertSessionHasNoErrors();
    expect($proposta())->toBe($altraVoce->id);
});

it('se la voce dell\'ultima commissione è stata cancellata, il campo resta vuoto: mai una voce sbagliata', function () {
    $s = cvcScenario($this);
    $altraVoce = be52Voce($this, $s, $s['pc'], 'Bolli e commissioni', 'spesa', '30,00');
    cvcPaga($this, $s, null, ['voce_commissione_id' => $altraVoce->id])->assertSessionHasNoErrors();
    DB::table('righe_scritture')->where('voce_spesa_id', $altraVoce->id)->update(['voce_spesa_id' => null]);
    DB::table('conti')->where('id', $altraVoce->id)->delete();
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.create', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect(collect($props['voce_commissione_proposta'])->firstWhere('gestione_id', $s['g']->id)['voce_id'] ?? null)->toBeNull()
        ->and(collect($props['voci_commissione'])->pluck('id')->all())->toContain($s['bancarie']->id)
        ->and(collect($props['voci_commissione'])->pluck('id')->all())->not->toContain($s['entrata']->id);
});

it('se la voce dell\'ultima commissione non è più una voce di spesa, il campo resta vuoto', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    // L'amministratore ha fatto della voce un'entrata: la riga della commissione la porta ancora, ma non è più una voce per una commissione.
    DB::table('conti')->where('id', $s['bancarie']->id)->update(['tipo' => 'entrata']);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.create', [$s['c']]))->assertOk()->viewData('page')['props'];

    expect(collect($props['voce_commissione_proposta'])->firstWhere('gestione_id', $s['g']->id)['voce_id'] ?? null)->toBeNull()
        ->and(collect($props['voci_commissione'])->pluck('id')->all())->not->toContain($s['bancarie']->id);
});

it('la modifica riscrive la riga con la voce scelta', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    $altraVoce = be52Voce($this, $s, $s['pc'], 'Bolli e commissioni', 'spesa', '30,00');
    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$s['c'], $p]), cvcCorpoModifica($this, $s, $p, ['voce_commissione_id' => $altraVoce->id, 'importo_commissioni_cents' => 300]))
        ->assertSessionHasNoErrors();
    $riga = cvcRigaCommissione($p->fresh()->scrittura);

    expect([$riga->importo, $riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([300, $altraVoce->conto_contabile_id, $altraVoce->id])
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id] ?? 0)->toBe(0)
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$altraVoce->id])->toBe(300);
});

it('aperta e rimandata senza toccare niente, la modifica di un pagamento con la voce lascia tutto com\'era', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$s['c'], $p]), cvcCorpoModifica($this, $s, $p))->assertSessionHasNoErrors();
    $riga = cvcRigaCommissione($p->fresh()->scrittura);

    expect([$riga->importo, $riga->voce_spesa_id])->toBe([250, $s['bancarie']->id]);
});

/** Un pagamento scritto prima, come lo scriveva la versione di prima: € 2,50 sulle «Spese bancarie» di sistema, senza voce. */
function cvcPagamentoVecchio($test, array $s): PagamentoFornitore
{
    cvcPaga($test, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    cvcRendiVecchio($p);

    return $p;
}

function cvcPropsModifica($test, array $s, PagamentoFornitore $p): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.pagamenti-fornitori.edit', [$s['c'], $p]))->assertOk()->viewData('page')['props'];
}

function cvcModifica($test, array $s, PagamentoFornitore $p, array $corpo)
{
    return $test->actingAs($test->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$s['c'], $p]), $corpo);
}

function cvcSistema(array $s): int
{
    return (int) ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
}

it('un pagamento scritto prima senza voce, con la commissione com\'era: la modifica non chiede la voce, la riga resta sulle «Spese bancarie» di sistema senza voce e il pagamento resta fra le commissioni senza voce', function () {
    $s = cvcScenario($this);
    $p = cvcPagamentoVecchio($this, $s);
    $props = cvcPropsModifica($this, $s, $p);

    expect($props['commissione']['senza_voce'])->toBeTrue()->and($props['commissione']['puo_restare_senza_voce'])->toBeTrue()
        ->and($props['commissione']['voce_id'])->toBeNull()->and($props['commissione']['gestione_id'])->toBe($s['g']->id);

    // Cambiata la data, non la commissione, e nessuna voce: la pagina manda quello che ha ricevuto.
    $corpo = cvcCorpoModifica($this, $s, $p, ['data_pagamento' => '2026-04-12']);
    expect($corpo['voce_commissione_id'])->toBeNull();
    cvcModifica($this, $s, $p, $corpo)->assertSessionHasNoErrors();

    $dopo = $p->fresh();
    $riga = cvcRigaCommissione($dopo->scrittura);
    expect([$riga->importo, $riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([250, cvcSistema($s), null])
        ->and($dopo->data_pagamento->format('Y-m-d'))->toBe('2026-04-12')->and($dopo->importo_commissione)->toBe(250)
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id] ?? 0)->toBe(0);

    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.commissioni-senza-voce.index', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props'];
    expect($elenco['totale'])->toBe(['numero' => 1, 'importo' => 250])->and($elenco['commissioni'][0]['assegnabile'])->toBeTrue();
});

it('lo stesso pagamento con la commissione cambiata, anche di un centesimo, chiede la voce con la sua frase; con la voce la riga passa sul mastro della voce', function () {
    $s = cvcScenario($this);
    $p = cvcPagamentoVecchio($this, $s);

    foreach ([300, 251, 249] as $nuova) {
        cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p, ['importo_commissioni_cents' => $nuova]))->assertSessionHasErrors(['voce_commissione_id' => CVC_SENZA_VOCE]);
        $riga = cvcRigaCommissione($p->fresh()->scrittura);
        expect([$riga->importo, $riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([250, cvcSistema($s), null]);
    }

    cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p, ['importo_commissioni_cents' => 300, 'voce_commissione_id' => $s['bancarie']->id]))->assertSessionHasNoErrors();
    $riga = cvcRigaCommissione($p->fresh()->scrittura);

    expect([$riga->importo, $riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([300, $s['bancarie']->conto_contabile_id, $s['bancarie']->id]);
});

it('un pagamento scritto prima senza voce può prendere la voce anche con la commissione com\'era', function () {
    $s = cvcScenario($this);
    $p = cvcPagamentoVecchio($this, $s);
    cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p, ['voce_commissione_id' => $s['bancarie']->id]))->assertSessionHasNoErrors();
    $riga = cvcRigaCommissione($p->fresh()->scrittura);

    expect([$riga->importo, $riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([250, $s['bancarie']->conto_contabile_id, $s['bancarie']->id])
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id])->toBe(250);
});

it('un pagamento che aveva la voce non può perderla: senza voce in modifica è rifiutato, anche con la commissione com\'era', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    $props = cvcPropsModifica($this, $s, $p);

    expect($props['commissione']['senza_voce'])->toBeFalse()->and($props['commissione']['puo_restare_senza_voce'])->toBeFalse();
    cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p, ['voce_commissione_id' => null]))->assertSessionHasErrors(['voce_commissione_id' => CVC_SENZA_VOCE]);

    expect(cvcRigaCommissione($p->fresh()->scrittura)->voce_spesa_id)->toBe($s['bancarie']->id);
});

it('una commissione senza voce che non sta sulle «Spese bancarie» di sistema (la voce cancellata dopo) chiede la voce anche con la commissione com\'era', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    DB::table('righe_scritture')->where('id', cvcRigaCommissione($p->scrittura)->id)->update(['voce_spesa_id' => null]);
    $props = cvcPropsModifica($this, $s, $p);

    expect($props['commissione']['senza_voce'])->toBeTrue()->and($props['commissione']['puo_restare_senza_voce'])->toBeFalse();
    cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p))->assertSessionHasErrors(['voce_commissione_id' => CVC_SENZA_VOCE]);

    $riga = cvcRigaCommissione($p->fresh()->scrittura);
    expect([$riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([$s['bancarie']->conto_contabile_id, null]);
});

it('tolta la commissione a un pagamento scritto prima senza voce non resta niente da lasciare: se risale, la voce si chiede', function () {
    $s = cvcScenario($this);
    $p = cvcPagamentoVecchio($this, $s);
    cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p, ['importo_commissioni_cents' => 0]))->assertSessionHasNoErrors();
    expect(cvcRigaCommissione($p->fresh()->scrittura))->toBeNull()->and(cvcPropsModifica($this, $s, $p->fresh())['commissione']['puo_restare_senza_voce'])->toBeFalse();

    cvcModifica($this, $s, $p->fresh(), cvcCorpoModifica($this, $s, $p->fresh(), ['importo_commissioni_cents' => 250]))->assertSessionHasErrors(['voce_commissione_id' => CVC_SENZA_VOCE]);
});

it('la regola di «senza voce, com\'era» in poche righe: serve una commissione più di zero, uguale a quella registrata, sulle «Spese bancarie» di sistema e senza voce', function () {
    $s = cvcScenario($this);
    $p = cvcPagamentoVecchio($this, $s);
    $voci = app(\App\Services\Gestionale\VoceDellaCommissione::class);

    expect($voci->puoRestareSenzaVoce($p, 250))->toBeTrue()->and($voci->contoSenzaVoce($p, 250))->toBe(cvcSistema($s))
        ->and($voci->puoRestareSenzaVoce($p, 251))->toBeFalse()->and($voci->puoRestareSenzaVoce($p, 249))->toBeFalse()->and($voci->puoRestareSenzaVoce($p, 0))->toBeFalse();

    // Registrata a zero, con una riga che resta sul conto di sistema: non c'è niente da lasciare.
    DB::table('pagamenti_fornitori')->where('id', $p->id)->update(['importo_commissione' => 0]);
    expect($voci->puoRestareSenzaVoce($p->fresh(), 0))->toBeFalse();
});

it('una commissione con la voce non si lascia senza voce, anche se la voce usa il mastro delle «Spese bancarie» di sistema', function () {
    $s = cvcScenario($this);
    DB::table('conti')->where('id', $s['bancarie']->id)->update(['conto_contabile_id' => cvcSistema($s)]);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    $riga = cvcRigaCommissione($p->scrittura);

    expect([$riga->conto_contabile_id, $riga->voce_spesa_id])->toBe([cvcSistema($s), $s['bancarie']->id])
        ->and(app(\App\Services\Gestionale\VoceDellaCommissione::class)->puoRestareSenzaVoce($p, 250))->toBeFalse()
        ->and(cvcPropsModifica($this, $s, $p)['commissione']['puo_restare_senza_voce'])->toBeFalse();
    cvcModifica($this, $s, $p, cvcCorpoModifica($this, $s, $p, ['voce_commissione_id' => null]))->assertSessionHasErrors(['voce_commissione_id' => CVC_SENZA_VOCE]);
});

it('lo storno di un pagamento scritto prima senza voce non è un pagamento da lasciare com\'era: la sua riga sulle «Spese bancarie» è in AVERE', function () {
    $s = cvcScenario($this);
    $p = cvcPagamentoVecchio($this, $s);
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $storno = PagamentoFornitore::where('pagamento_padre_id', $p->id)->firstOrFail();

    expect($storno->importo_commissione)->toBe(250)
        ->and(app(\App\Services\Gestionale\VoceDellaCommissione::class)->puoRestareSenzaVoce($storno, 250))->toBeFalse();
});

it('lo storno di un pagamento con la voce ricopia la voce: il consuntivo della voce torna a zero', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $storno = PagamentoFornitore::where('pagamento_padre_id', $p->id)->firstOrFail();
    $riga = $storno->scrittura->righe()->where('voce_spesa_id', $s['bancarie']->id)->first();

    expect($riga)->not->toBeNull()
        ->and([$riga->tipo_riga, $riga->importo, $riga->conto_contabile_id])->toBe(['avere', 250, $s['bancarie']->conto_contabile_id])
        ->and((new SpesaPerVoceService)->perEsercizio($s['e'])[$s['bancarie']->id])->toBe(0);
});

it('lo storno di un pagamento scritto prima senza voce ricopia le righe com\'erano, senza chiedere la voce', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    cvcRendiVecchio($p);
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], $p]), ['motivo' => 'Bonifico respinto dalla banca'])->assertSessionHasNoErrors();
    $storno = PagamentoFornitore::where('pagamento_padre_id', $p->id)->firstOrFail();
    $sistema = ContoContabile::where('condominio_id', $s['c']->id)->where('ruolo', 'spese_bancarie')->value('id');
    $riga = $storno->scrittura->righe()->where('conto_contabile_id', $sistema)->first();

    expect([$riga->tipo_riga, $riga->importo, $riga->voce_spesa_id])->toBe(['avere', 250, null]);
});

/** Una gestione straordinaria con il suo piano dei conti, una voce di spesa «Lavori tetto», una voce «Bancarie del tetto» e una fattura del tetto. */
function cvcTetto($test, array $s): array
{
    $t = be52GestioneStraordinaria($s);
    $t['lavori'] = be52Voce($test, $s, $t['pc'], 'Lavori tetto', 'spesa', '5.000,00');
    $t['bancarie'] = be52Voce($test, $s, $t['pc'], 'Bancarie del tetto', 'spesa', '50,00');
    $test->actingAs($test->user)->post(route('admin.gestionale.fatture.store', [$s['c']]),
        be52PayloadFattura($s, $s['fornitore'], $t['lavori']->id, 2000.00, ['gestione_id' => $t['g']->id], 'FT-TETTO'))->assertSessionHasNoErrors();
    $t['fattura'] = FatturaPassiva::where('numero_documento', 'FT-TETTO')->where('condominio_id', $s['c']->id)->firstOrFail();

    return $t;
}

function cvcProposta($test, array $s, int $gestioneId): ?int
{
    return collect($test->actingAs($test->user)->get(route('admin.gestionale.pagamenti-fornitori.create', [$s['c']]))->assertOk()
        ->viewData('page')['props']['voce_commissione_proposta'])->firstWhere('gestione_id', $gestioneId)['voce_id'] ?? null;
}

it('la proposta salta i pagamenti stornati: rifatto il pagamento, non torna la voce sbagliata', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $sbagliata = be52Voce($this, $s, $s['pc'], 'Bolli e commissioni', 'spesa', '30,00');
    cvcPaga($this, $s, cvcFattura($this, $s, 'FT-291', 100.00), ['voce_commissione_id' => $sbagliata->id])->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('admin.gestionale.pagamenti-fornitori.storno', [$s['c'], cvcPagamento($s)]), ['motivo' => 'Voce della commissione sbagliata'])->assertSessionHasNoErrors();

    expect(cvcProposta($this, $s, $s['g']->id))->toBe($s['bancarie']->id);
});

it('la proposta è per gestione: una commissione del tetto, dopo, non cambia quella dell\'ordinaria', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $t = cvcTetto($this, $s);
    cvcPaga($this, $s, $t['fattura'], ['voce_commissione_id' => $t['bancarie']->id])->assertSessionHasNoErrors();

    expect(cvcProposta($this, $s, $s['g']->id))->toBe($s['bancarie']->id)
        ->and(cvcProposta($this, $s, $t['g']->id))->toBe($t['bancarie']->id);
});

it('il rifiuto di una voce di entrata dice perché, con la sua frase', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s, null, ['voce_commissione_id' => $s['entrata']->id])
        ->assertSessionHasErrors(['voce_commissione_id' => '«Affitto antenna» è una voce di entrata: la commissione è una spesa. Scegli una voce di spesa.']);
});

it('una voce senza conto contabile non riceve la commissione: nessuna riga senza conto', function () {
    $s = cvcScenario($this);
    DB::table('conti')->where('id', $s['bancarie']->id)->update(['conto_contabile_id' => null]);
    cvcPaga($this, $s)->assertSessionHasErrors(['voce_commissione_id' => '«Spese bancarie» non può ricevere la commissione: deve essere una voce senza sottovoci, con un conto contabile.']);

    expect(PagamentoFornitore::where('condominio_id', $s['c']->id)->count())->toBe(0);
});

it('in modifica una voce di un\'altra gestione è rifiutata, e la riga resta com\'era', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    $t = cvcTetto($this, $s);
    $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$s['c'], $p]), cvcCorpoModifica($this, $s, $p, ['voce_commissione_id' => $t['bancarie']->id]))
        ->assertSessionHasErrors(['voce_commissione_id' => '«Bancarie del tetto» è di un\'altra gestione: la commissione va su una voce della gestione del pagamento («'.$s['g']->nome.'»). '
            .'Per metterla su una voce di un\'altra gestione, per esempio le spese bancarie dell\'ordinaria, lascia la commissione a zero e registrala con una regolazione immediata.']);

    expect(cvcRigaCommissione($p->fresh()->scrittura)->voce_spesa_id)->toBe($s['bancarie']->id);
});

it('la modifica di un pagamento scritto prima senza voce, in un esercizio chiuso, dice di stornare e non chiede la voce', function () {
    $s = cvcScenario($this);
    cvcPaga($this, $s)->assertSessionHasNoErrors();
    $p = cvcPagamento($s);
    cvcRendiVecchio($p);
    $corpo = cvcCorpoModifica($this, $s, $p);
    DB::table('esercizi')->where('id', $s['e']->id)->update(['stato' => 'chiuso']);
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.pagamenti-fornitori.edit', [$s['c'], $p]))->viewData('page')['props'];
    $r = $this->actingAs($this->user)->put(route('admin.gestionale.pagamenti-fornitori.update', [$s['c'], $p]), $corpo);

    expect($props['commissione']['esercizio_chiuso'])->toBeTrue();
    $r->assertSessionHasErrors(['modifica_vietata' => 'Il pagamento appartiene a un esercizio chiuso: usa lo storno.']);
    $r->assertSessionDoesntHaveErrors('voce_commissione_id');
});
