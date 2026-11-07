<?php

use App\Actions\Gestionale\Movimenti\StornoIncassoRateAction;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Models\Gestione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

require_once __DIR__ . '/RicevutaIncassoScenario.php';

uses(RefreshDatabase::class);

/**
 * La pagina delle ricevute (decisione 201: il caso di tutti i mesi in un clic) e quello che l'elenco e la pagina di un incasso dicono delle
 * ricevute. Si apre con «mai emessa» già messo; i filtri: periodo del versamento, gestione, cassa, persone (una, alcune, tutte), email con o
 * senza, ricevuta mai emessa, già stampata, già inviata. Gli incassi annullati non compaiono.
 *
 * Che cosa NON copre: la selezione e i conteggi a video (nel modulo JavaScript `ricevute.ts`), l'aspetto della pagina.
 */
function paginaRicevute($test, object $s, array $filtri = [])
{
    return $test->actingAs(amministratoreRicevute())
        ->get(route('admin.gestionale.ricevute.index', array_merge(['condominio' => $s->condominio->id], $filtri)))
        ->assertOk();
}

function idsDellaPagina($risposta): array
{
    return collect($risposta->viewData('page')['props']['incassi']['data'])->pluck('id')->sort()->values()->all();
}

function segnaEmissione(object $s, ScritturaContabile $incasso, string $canale, string $stato): void
{
    RegistroInvio::create([
        'condominio_id' => $s->condominio->id, 'anagrafica_id' => null, 'tipo' => 'ricevuta', 'canale' => $canale,
        'documentabile_type' => ScritturaContabile::class, 'documentabile_id' => $incasso->id, 'numero' => $incasso->numero_protocollo,
        'stato' => $stato, 'emesso_il' => now(),
    ]);
}

test('la pagina si apre con «mai emessa» e i versamenti dal primo del mese scorso: le ricevute già stampate o partite non ci sono, quelle non partite sì, gli annullati mai', function () {
    $s = scenarioRicevutaIncasso();
    $giorni = fn (int $n) => now()->setTimezone(config('app.user_timezone'))->subDays($n)->format('Y-m-d');
    $nuovo = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], $giorni(2), 150.00);
    $stampato = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], $giorni(3), 120.50);
    $fallito = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], $giorni(4), 60.00);
    $annullato = incassaPerRicevuta($s, $s->ugo, [[$s->ugo2, 60.00]], $giorni(5), 60.00);
    app(StornoIncassoRateAction::class)->execute($annullato->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    // Un versamento di prima del mese scorso: è «mai emessa», ma all'apertura non c'è.
    $vecchio = incassaPerRicevuta($s, $s->anna, [[$s->anna3, 150.00]], $giorni(70), 150.00);
    segnaEmissione($s, $stampato, 'stampa', 'stampata');
    segnaEmissione($s, $fallito, 'email', 'non_partita');

    $risposta = paginaRicevute($this, $s);
    $filtri = $risposta->viewData('page')['props']['filters'];

    expect(idsDellaPagina($risposta))->toBe(collect([$nuovo->id, $fallito->id])->sort()->values()->all())
        ->and($filtri['ricevuta'])->toBe('mai')
        ->and($filtri['data_da'])->toBe(now()->setTimezone(config('app.user_timezone'))->subMonthNoOverflow()->startOfMonth()->format('Y-m-d'))
        ->and($filtri['data_a'])->toBe(now()->setTimezone(config('app.user_timezone'))->format('Y-m-d'))
        ->and($risposta->viewData('page')['props']['totale'])->toBe(2);

    // Tolto il periodo, il versamento vecchio c'è; con «tutte» anche la stampata.
    expect(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'mai'])))->toBe(collect([$nuovo->id, $fallito->id, $vecchio->id])->sort()->values()->all())
        ->and(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'tutte'])))->toBe(collect([$nuovo->id, $stampato->id, $fallito->id, $vecchio->id])->sort()->values()->all());
});

test('i filtri: periodo del versamento, gestione, cassa, email, importo sopra o fino a € 77,47, ricevuta già stampata o già inviata', function () {
    $s = scenarioRicevutaIncasso();
    $febbraio = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-10', 150.00);
    $marzo = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-10', 120.50);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-15', 60.00, ['cassa_id' => $s->contanti->id]);
    segnaEmissione($s, $febbraio, 'stampa', 'stampata');
    segnaEmissione($s, $marzo, 'email', 'inviata');

    $tutte = ['ricevuta' => 'tutte'];
    expect(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['data_da' => '2026-02-01', 'data_a' => '2026-02-28'])))->toBe(collect([$febbraio->id, $ugo->id])->sort()->values()->all())
        ->and(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['cassa_id' => $s->contanti->id])))->toBe([$ugo->id])
        ->and(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['email' => 'senza'])))->toBe([$ugo->id])
        ->and(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['email' => 'con'])))->toBe(collect([$febbraio->id, $marzo->id])->sort()->values()->all())
        ->and(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['soglia' => 'fino'])))->toBe([$ugo->id])
        ->and(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['soglia' => 'sopra'])))->toBe(collect([$febbraio->id, $marzo->id])->sort()->values()->all())
        ->and(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'stampata'])))->toBe([$febbraio->id])
        ->and(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'inviata'])))->toBe([$marzo->id]);

    $straordinaria = Gestione::create(['condominio_id' => $s->condominio->id, 'nome' => 'Tetto', 'tipo' => 'straordinaria', 'data_inizio' => '2026-01-01']);
    $marzo->update(['gestione_id' => $straordinaria->id]);
    expect(idsDellaPagina(paginaRicevute($this, $s, $tutte + ['gestione_id' => $straordinaria->id])))->toBe([$marzo->id]);
});

test('il filtro sulle persone ne prende una, alcune o tutte, e conta anche chi ha versato per conto di altri', function () {
    $s = scenarioRicevutaIncasso();
    $anna = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $perConto = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50, ['versato_da_id' => $s->carlo->id]);
    $ugo = incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);

    $risposta = paginaRicevute($this, $s, ['ricevuta' => 'mai', 'persone' => [$s->ugo->id]]);
    expect(idsDellaPagina($risposta))->toBe([$ugo->id])
        ->and(collect($risposta->viewData('page')['props']['persone'])->pluck('nome')->all())->toBe(['Anna Bruni', 'Carlo Bruni', 'Ugo Neri']);

    expect(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'mai', 'persone' => [$s->ugo->id, $s->anna->id]])))->toBe(collect([$anna->id, $perConto->id, $ugo->id])->sort()->values()->all())
        ->and(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'mai', 'persone' => [$s->carlo->id]])))->toBe([$perConto->id])
        ->and(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'mai'])))->toHaveCount(3);
});

test('una riga dell\'elenco dice la persona, chi ha versato, l\'importo, se c\'è un indirizzo e l\'ultima emissione', function () {
    $s = scenarioRicevutaIncasso();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['versato_da_id' => $s->carlo->id]);
    segnaEmissione($s, $incasso, 'stampa', 'stampata');
    segnaEmissione($s, $incasso, 'email', 'inviata');
    RegistroInvio::latest('id')->first()->update(['destinatario' => $s->anna->email]);

    $riga = paginaRicevute($this, $s, ['ricevuta' => 'tutte'])->viewData('page')['props']['incassi']['data'][0];

    expect($riga)->toMatchArray([
        'id' => $incasso->id, 'numero' => $incasso->numero_protocollo, 'data' => '2026-02-01', 'importo' => 15000,
        'sopra_soglia' => true, 'ha_email' => true,
    ])
        ->and($riga['posizione'])->toBe(['id' => $s->anna->id, 'nome' => 'Anna Bruni'])
        ->and($riga['versato_da'])->toBe(['id' => $s->carlo->id, 'nome' => 'Carlo Bruni'])
        ->and($riga['ultima'])->toMatchArray(['canale' => 'email', 'stato' => 'inviata', 'destinatario' => $s->anna->email])
        ->and($riga['ultima_email_id'])->toBe(RegistroInvio::latest('id')->first()->id);
});

test('«tutte le N dei filtri»: l\'anteprima del blocco lavora sui filtri, non su un elenco di identificativi', function () {
    $s = scenarioRicevutaIncasso();
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-03-01', 120.50);
    incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-02-01', 60.00);

    $anteprima = $this->actingAs(amministratoreRicevute())
        ->postJson(route('admin.gestionale.ricevute.anteprima', ['condominio' => $s->condominio->id]), ['tutte' => 1, 'filtri' => ['ricevuta' => 'mai', 'email' => 'con']])
        ->assertOk()->json();

    expect($anteprima['ricevute'])->toBe(2)
        ->and($anteprima['email']['persone'])->toBe(1)
        ->and($anteprima['email']['ricevute'])->toBe(2)
        ->and($anteprima['oltre_tetto'])->toBeFalse();
});

// Il conteggio accanto al pulsante conta come la pagina che si apre: i versamenti dal primo del mese scorso (il test che segue lo prova con
// versamenti più vecchi); qui le date sono di pochi giorni fa.
test('l\'elenco degli incassi dice quanti sono senza ricevuta; la pagina di un incasso dice le emissioni della sua ricevuta', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $giorni = fn (int $n) => now()->setTimezone(config('app.user_timezone'))->subDays($n)->format('Y-m-d');
    $con = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], $giorni(3), 150.00);
    incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], $giorni(3), 60.00);
    incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], $giorni(2), 120.50);
    segnaEmissione($s, $con, 'stampa', 'stampata');
    segnaEmissione($s, $con, 'email', 'non_partita');

    $this->actingAs(amministratoreRicevute())
        ->get(route('admin.gestionale.movimenti-rate.index', ['condominio' => $s->condominio->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ricevute_mancanti.numero', 2));

    $this->actingAs(amministratoreRicevute())
        ->get(route('admin.gestionale.movimenti-rate.show', ['condominio' => $s->condominio->id, 'scrittura' => $con->id]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('emissioni', 2)
            ->where('emissioni.0.canale', 'email')
            ->where('emissioni.0.stato', 'non_partita')
            ->where('emissioni.1.canale', 'stampa'));
});

test('il conteggio accanto al pulsante «Ricevute» conta come la pagina che si apre: i versamenti dal primo del mese scorso, e dice da quando', function () {
    $s = scenarioRicevutaIncasso();
    foreach (['2026-01-10', '2026-02-10', '2026-03-10', '2026-04-10'] as $d) {
        incassaPerRicevuta($s, $s->ugo, [[$s->ugo1->fresh(), 1.00]], $d, 1.00);
    }
    incassaPerRicevuta($s, $s->anna, [[$s->anna1, 10.00]], now()->setTimezone(config('app.user_timezone'))->format('Y-m-d'), 10.00);
    $dal = now()->setTimezone(config('app.user_timezone'))->subMonthNoOverflow()->startOfMonth()->format('Y-m-d');

    $this->actingAs(amministratoreRicevute())
        ->get(route('admin.gestionale.movimenti-rate.index', ['condominio' => $s->condominio->id]))
        ->assertInertia(fn ($page) => $page->where('ricevute_mancanti', ['numero' => 1, 'dal' => $dal]));
    expect(paginaRicevute($this, $s)->viewData('page')['props']['totale'])->toBe(1);
});

test('un incasso senza denaro (la rata coperta solo dal credito) non è una ricevuta di pagamento: fuori dall\'elenco, dal conteggio e dal blocco; stampa e invio lo rifiutano, la finestra lo dice', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $giorni = fn (int $n) => now()->setTimezone(config('app.user_timezone'))->subDays($n)->format('Y-m-d');
    $vero = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], $giorni(3), 170.00);
    $zero = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], $giorni(2), 0.00);
    $u = amministratoreRicevute();
    $c = ['condominio' => $s->condominio->id];

    expect(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'tutte'])))->toBe([$vero->id]);
    $this->actingAs($u)->get(route('admin.gestionale.movimenti-rate.index', $c))->assertInertia(fn ($page) => $page->where('ricevute_mancanti.numero', 1));

    $this->actingAs($u)->post(route('admin.gestionale.ricevute.stampa', $c), ['incassi' => [$zero->id], 'bollo' => 'non_dovuta'])
        ->assertStatus(422)->assertSee('non è entrato denaro', false);
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia-una', $c + ['scrittura' => $zero->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email']]], 'bollo' => 'non_dovuta'])->assertStatus(422);
    $opzioni = $this->actingAs($u)->getJson(route('admin.gestionale.ricevute.opzioni', $c + ['scrittura' => $zero->id]))->assertOk()->json();
    expect($opzioni['emettibile'])->toBeFalse()
        ->and($opzioni['motivo'])->toContain('non è entrato denaro');

    $anteprima = $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.anteprima', $c), ['incassi' => [$vero->id, $zero->id], 'indirizzi' => ['email']])->json();
    expect($anteprima['ricevute'])->toBe(1)
        ->and($anteprima['senza_versamento'])->toBe(1)
        ->and($anteprima['annullati'])->toBe(0);
    $this->actingAs($u)->postJson(route('admin.gestionale.ricevute.invia', $c), ['incassi' => [$vero->id, $zero->id], 'indirizzi' => ['email'], 'bollo_sopra' => 'assolta'])
        ->assertOk()->assertJson(['ricevute' => 1, 'senza_versamento' => 1, 'annullati' => 0]);
    expect(RegistroInvio::pluck('documentabile_id')->unique()->all())->toBe([$vero->id]);
});

test('la pagina riceve del condominio solo quello che legge, e il filtro «Cassa» comprende la cassa virtuale, che la ricevuta nomina', function () {
    $s = scenarioRicevutaIncasso();
    $conto = \App\Models\Gestionale\ContoContabile::create(['condominio_id' => $s->condominio->id, 'codice' => '10.12', 'nome' => 'Pagamenti elettronici',
        'tipo' => 'attivo', 'ruolo' => 'gateway_pagamento', 'categoria' => 'liquidita']);
    $virtuale = \App\Models\Gestionale\Cassa::create(['condominio_id' => $s->condominio->id, 'conto_contabile_id' => $conto->id,
        'nome' => 'Pagamenti online', 'tipo' => 'virtuale', 'attiva' => true]);
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['cassa_id' => $virtuale->id]);

    $props = paginaRicevute($this, $s, ['ricevuta' => 'tutte'])->viewData('page')['props'];
    expect(array_keys($props['condominio']))->toBe(['id', 'nome'])
        ->and(collect($props['casse'])->pluck('id')->all())->toContain($virtuale->id)
        ->and(idsDellaPagina(paginaRicevute($this, $s, ['ricevuta' => 'tutte', 'cassa_id' => $virtuale->id])))->toBe([$incasso->id])
        ->and(app(\App\Services\Gestionale\Ricevute\RicevutaIncasso::class)->per($incasso->fresh())['cassa'])->toBe(['nome' => 'Pagamenti online', 'tipo' => 'Cassa virtuale']);
});
