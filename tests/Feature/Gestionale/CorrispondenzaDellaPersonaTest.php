<?php

/**
 * La scheda «Corrispondenza» dell'estratto conto (Coda 533, decisione 231): oltre alle lettere (`lettere`, com'erano), la pagina porta le ricevute,
 * gli avvisi di compensazione e le scelte «non serve comunicarla» della persona in quel condominio (`documenti`), una voce per tipo, incasso,
 * istante di emissione e canale, dalla più recente; e l'ultima lettera (`ultima_lettera`), letta con la stessa funzione e alla stessa data della
 * colonna «Ultima lettera» di «Rate scadute».
 *
 * Le cifre sono quelle dello scenario della ricevuta: Anna Bruni ha la rata 1 da € 150,00 e la rata 2 da € 120,50; Ugo Neri € 60,00 a rata.
 *
 * Che cosa NON copre: i filtri, le pagine e il numero della scheda, che stanno nel browser (`corrispondenza.test.ts`, `RegistroLettere.test.ts`).
 */

use App\Models\Condominio;
use App\Models\Gestionale\RegistroInvio;
use App\Models\Gestionale\ScritturaContabile;
use App\Services\Gestionale\Ricevute\AvvisiCompensazione;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

require_once __DIR__ . '/RicevutaIncassoScenario.php';
require_once __DIR__ . '/LettereScenario.php';

// Le pagine si leggono dalla loro rotta: il test non dipende da una compilazione di Vite.
beforeEach(function () {
    $this->withoutVite();
});

uses(RefreshDatabase::class);

/** Le props della pagina dell'estratto conto di una persona. */
function cdpPagina($test, $utente, Condominio $condominio, $persona): array
{
    return $test->actingAs($utente)->get(route('admin.gestionale.anagrafiche.estratto-conto', [$condominio, $persona]))->assertOk()->viewData('page')['props'];
}

function cdpStampa($test, $utente, object $s, ScritturaContabile $incasso): void
{
    $test->actingAs($utente)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$incasso->id], 'bollo' => 'assolta'])->assertOk();
}

test('la scheda porta le ricevute e gli avvisi della persona in quel condominio: una voce per emissione, l\'invio a due indirizzi è una voce con due righe; né le lettere né i documenti di altri', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();

    // 01/03 alle 10:00 la ricevuta del versamento di Anna (€ 170,00, € 20,00 di credito) stampata.
    $this->travelTo('2026-03-01 10:00:00');
    $versamento = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 170.00);
    cdpStampa($this, $utente, $s, $versamento);
    // 02/03 alle 10:00 la stessa ricevuta mandata alla primaria e alla secondaria: una copia, due righe nello stesso istante.
    $this->travelTo('2026-03-02 10:00:00');
    $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $versamento->id]),
        ['destinatari' => [['anagrafica_id' => $s->anna->id, 'tipi' => ['email', 'email_secondaria']]], 'bollo' => 'assolta'])->assertOk();
    // 03/03 alle 10:00 il credito copre € 20,00 della rata 2: l'avviso di compensazione stampato.
    $this->travelTo('2026-03-03 10:00:00');
    $compensazione = incassaPerRicevuta($s, $s->anna, [[$s->anna1->fresh(), -20.00], [$s->anna2, 20.00]], '2026-03-03', 0.00);
    $this->actingAs($utente)->post(route('admin.gestionale.ricevute.avvisi-compensazione.stampa', ['condominio' => $s->condominio->id]),
        ['avvisi' => [$compensazione->id . ':' . $s->anna->id], 'bollo' => 'non_dovuta'])->assertOk();
    // Documenti che non sono suoi: la ricevuta di Ugo, una lettera ad Anna, una ricevuta ad Anna in un altro condominio.
    $this->travelTo('2026-03-04 10:00:00');
    cdpStampa($this, $utente, $s, incassaPerRicevuta($s, $s->ugo, [[$s->ugo1, 60.00]], '2026-03-04', 60.00));
    RegistroInvio::create(['condominio_id' => $s->condominio->id, 'anagrafica_id' => $s->anna->id, 'tipo' => 'sollecito', 'canale' => 'stampa',
        'documentabile_type' => \App\Models\Anagrafica::class, 'documentabile_id' => $s->anna->id, 'dati' => ['lettera' => (string) Str::uuid(), 'righe' => [], 'totale' => 0],
        'stato' => 'stampata', 'emesso_il' => now()]);
    $altro = scenarioRicevutaIncasso('Condominio Via dei Tigli');
    RegistroInvio::create(['condominio_id' => $altro->condominio->id, 'anagrafica_id' => $s->anna->id, 'tipo' => 'ricevuta', 'canale' => 'stampa',
        'documentabile_type' => ScritturaContabile::class, 'documentabile_id' => $versamento->id, 'numero' => '99', 'stato' => 'stampata', 'emesso_il' => now()]);

    $p = cdpPagina($this, $utente, $s->condominio, $s->anna);
    $documenti = $p['documenti'];
    expect($documenti)->toHaveCount(3)
        ->and(array_column($documenti, 'tipo'))->toBe(['avviso_compensazione', 'ricevuta', 'ricevuta'])
        ->and(array_column($documenti, 'emessa_il'))->toBe(['2026-03-03', '2026-03-02', '2026-03-01']);
    expect($documenti[0])->toMatchArray(['tipo' => 'avviso_compensazione', 'numero' => $compensazione->numero_protocollo, 'versamento_del' => '2026-03-03', 'importo' => 2000,
        'non_da_comunicare' => false, 'ora' => '11:00', 'incasso_id' => $compensazione->id, 'per_conto_di' => null])
        ->and($documenti[0]['righe'])->toHaveCount(1)
        ->and($documenti[0]['righe'][0])->toMatchArray(['canale' => 'stampa', 'stato' => 'stampata']);
    // L'invio a due indirizzi è una voce sola, con le due righe; è una copia della ricevuta del 01/03.
    expect($documenti[1])->toMatchArray(['tipo' => 'ricevuta', 'numero' => $versamento->numero_protocollo, 'versamento_del' => '2026-02-01', 'importo' => 17000,
        'copia_del' => '2026-03-01', 'annullato' => false, 'incasso_id' => $versamento->id, 'per_conto_di' => null])
        ->and(array_column($documenti[1]['righe'], 'destinatario'))->toBe([$s->anna->email, $s->anna->email_secondaria])
        ->and(array_column($documenti[1]['righe'], 'canale'))->toBe(['email', 'email'])
        ->and($documenti[2])->toMatchArray(['tipo' => 'ricevuta', 'copia_del' => null, 'emesso_da' => $utente->name])
        ->and($documenti[2]['righe'][0])->toMatchArray(['canale' => 'stampa', 'stato' => 'stampata', 'destinatario' => null]);
    // Le lettere restano dove erano.
    expect($p['lettere'])->toHaveCount(1)->and($p['lettere'][0]['tipo'])->toBe('sollecito');
    // Ugo ha la sua ricevuta, non quelle di Anna.
    expect(array_column(cdpPagina($this, $utente, $s->condominio, $s->ugo)['documenti'], 'tipo'))->toBe(['ricevuta']);
});

test('la ricevuta di un incasso annullato e la scelta «non serve comunicarla»: due voci, la seconda dice chi e quando', function () {
    Notification::fake();
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-01 10:00:00');
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    cdpStampa($this, $utente, $s, $incasso);
    $this->travelTo('2026-03-05 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::VERSAMENTO_TORNATO);
    $this->travelTo('2026-03-06 10:00:00');
    $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.non-serve-comunicarla', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]))->assertOk();

    $documenti = cdpPagina($this, $utente, $s->condominio, $s->anna)['documenti'];
    expect($documenti)->toHaveCount(2)
        ->and($documenti[0])->toMatchArray(['tipo' => 'ricevuta', 'non_da_comunicare' => true, 'emessa_il' => '2026-03-06', 'numero' => $incasso->numero_protocollo, 'emesso_da' => $utente->name])
        ->and($documenti[0]['righe'][0])->toMatchArray(['canale' => 'nessuno', 'stato' => 'non_da_comunicare'])
        ->and($documenti[1])->toMatchArray(['tipo' => 'ricevuta', 'non_da_comunicare' => false, 'emessa_il' => '2026-03-01', 'annullato' => false]);
});

test('il giorno di una voce è quello dell\'amministratore: una ricevuta stampata alle 22:30 UTC del 31/03 è del 01/04 a Roma', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo(\Carbon\Carbon::parse('2026-03-31 22:30:00', 'UTC'));
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-03-31', 150.00);
    cdpStampa($this, $utente, $s, $incasso);

    $documenti = cdpPagina($this, $utente, $s->condominio, $s->anna)['documenti'];
    expect($documenti[0])->toMatchArray(['emessa_il' => '2026-04-01', 'ora' => '00:30'])
        ->and($documenti[0]['righe'][0]['quando'])->toBe('2026-04-01 00:30');
});

test('la voce resta se l\'incasso della ricevuta non c\'è più: si legge dal registro e dai dati salvati, non dall\'incasso', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-01 10:00:00');
    cdpStampa($this, $utente, $s, incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00));
    // Una riga del registro che punta a un incasso che non esiste (cancellato dopo l'emissione), con i dati di allora.
    $riga = RegistroInvio::where('tipo', 'ricevuta')->sole();
    $copia = $riga->replicate();
    $copia->documentabile_id = 999999;
    $copia->save();
    $riga->delete();

    $documenti = cdpPagina($this, $utente, $s->condominio, $s->anna)['documenti'];
    expect($documenti)->toHaveCount(1)->and($documenti[0])->toMatchArray(['tipo' => 'ricevuta', 'importo' => 15000, 'versamento_del' => '2026-02-01', 'incasso_id' => null]);
});

test('l\'ultima lettera in testa è quella della colonna di «Rate scadute» per la stessa persona, con la lettera in coda dopo («poi»)', function () {
    $this->user = amministratoreRicevute();
    $s = lttScenario($this);
    $utente = $this->user;
    lttRigaScritta($s, 'ugo', 'sollecito', 'spedita', 5)->update(['dati' => ['lettera' => (string) Str::uuid(), 'righe' => [], 'totale' => 0,
        'spedizione' => ['canale' => 'raccomandata', 'data' => '2026-03-16', 'numero' => 'RA1']]]);
    lttRigaScritta($s, 'ugo', 'promemoria', 'in_coda', 1, 'email');

    $p = cdpPagina($this, $utente, $s['c'], $s['persone']['ugo']);
    $elenco = $this->actingAs($utente)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    $riga = collect($elenco['righe']['data'])->firstWhere('anagrafica_id', $s['persone']['ugo']->id);

    expect($p['ultima_lettera']['ultima'])->not->toBeNull()
        ->and($p['ultima_lettera']['ultima'])->toBe($riga['ultima_lettera'])
        ->and($p['ultima_lettera']['in_sospeso'])->toBe($riga['lettera_in_sospeso'])
        ->and($p['ultima_lettera']['ultima'])->toMatchArray(['tipo' => 'sollecito', 'stato' => 'spedita'])
        ->and($p['ultima_lettera']['in_sospeso'])->toMatchArray(['tipo' => 'promemoria', 'stato' => 'in_coda'])
        // Bice non ha lettere.
        ->and(cdpPagina($this, $utente, $s['c'], $s['persone']['bice'])['ultima_lettera'])->toBe(['ultima' => null, 'in_sospeso' => null]);
});

test('due ricevute stampate in un PDF sono due voci; la ristampa dello stesso incasso nello stesso minuto, o un minuto dopo, è un\'altra voce', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-01 10:00:00');
    $primo = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $secondo = incassaPerRicevuta($s, $s->anna, [[$s->anna2, 120.50]], '2026-02-15', 120.50);
    $this->actingAs($utente)->post(route('admin.gestionale.ricevute.stampa', ['condominio' => $s->condominio->id]), ['incassi' => [$primo->id, $secondo->id], 'bollo' => 'assolta'])->assertOk();
    $this->travelTo('2026-03-01 10:00:30');
    cdpStampa($this, $utente, $s, $primo);
    $this->travelTo('2026-03-01 10:01:30');
    cdpStampa($this, $utente, $s, $primo);

    $documenti = cdpPagina($this, $utente, $s->condominio, $s->anna)['documenti'];
    expect($documenti)->toHaveCount(4)
        ->and(array_column(array_slice($documenti, 0, 2), 'incasso_id'))->toBe([$primo->id, $primo->id])
        ->and(collect(array_slice($documenti, 2))->pluck('incasso_id')->sort()->values()->all())->toBe(collect([$primo->id, $secondo->id])->sort()->values()->all())
        ->and(array_column($documenti, 'ora'))->toBe(['11:01', '11:00', '11:00', '11:00'])
        ->and(collect($documenti)->every(fn ($d) => count($d['righe']) === 1))->toBeTrue();
});

test('la ristampa dopo l\'annullamento: la voce dice «incasso annullato» ed è una copia della prima', function () {
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-01 10:00:00');
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    cdpStampa($this, $utente, $s, $incasso);
    $this->travelTo('2026-03-05 10:00:00');
    app(\App\Actions\Gestionale\Movimenti\StornoIncassoRateAction::class)->execute($incasso->fresh(['righe', 'quotePagate', 'figlie']), $s->condominio, \App\Enums\NaturaStorno::ERRORE_REGISTRAZIONE);
    $this->travelTo('2026-03-06 10:00:00');
    cdpStampa($this, $utente, $s, $incasso);

    $documenti = cdpPagina($this, $utente, $s->condominio, $s->anna)['documenti'];
    expect($documenti)->toHaveCount(2)
        ->and($documenti[0])->toMatchArray(['emessa_il' => '2026-03-06', 'annullato' => true, 'copia_del' => '2026-03-01'])
        ->and($documenti[1])->toMatchArray(['emessa_il' => '2026-03-01', 'annullato' => false, 'copia_del' => null]);
});

test('la ricevuta mandata a chi ha versato per un altro dice per conto di chi', function () {
    \Illuminate\Support\Facades\Notification::fake();
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $this->travelTo('2026-03-01 10:00:00');
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00, ['versato_da_id' => $s->carlo->id]);
    $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]),
        ['destinatari' => [['anagrafica_id' => $s->carlo->id, 'tipi' => ['email']]], 'bollo' => 'assolta'])->assertOk();

    $documenti = cdpPagina($this, $utente, $s->condominio, $s->carlo)['documenti'];
    expect($documenti)->toHaveCount(1)->and($documenti[0])->toMatchArray(['tipo' => 'ricevuta', 'per_conto_di' => 'Anna Bruni']);
});

test('una ricevuta non partita porta la frase dell\'errore, come le righe delle lettere', function () {
    trasportoCheRifiutaLeRicevute();
    $s = scenarioRicevutaIncasso();
    $utente = amministratoreRicevute();
    $incasso = incassaPerRicevuta($s, $s->anna, [[$s->anna1, 150.00]], '2026-02-01', 150.00);
    $this->actingAs($utente)->postJson(route('admin.gestionale.ricevute.invia-una', ['condominio' => $s->condominio->id, 'scrittura' => $incasso->id]),
        ['destinatari' => [$s->anna->id], 'bollo' => 'assolta'])->assertOk();

    $riga = cdpPagina($this, $utente, $s->condominio, $s->anna)['documenti'][0]['righe'][0];
    $registro = RegistroInvio::where('stato', 'non_partita')->first();
    expect($riga['stato'])->toBe('non_partita')
        ->and($riga['errore'])->not->toBeNull()
        ->and($riga['errore'])->toBe(\App\Services\Gestionale\Ricevute\ErroreDiPosta::frase($registro->errore));
});

test('l\'ultima lettera alle 00:30 di Roma è quella del giorno di Roma, in testa come nella colonna di «Rate scadute»', function () {
    $this->user = amministratoreRicevute();
    $s = lttScenario($this);
    // 31/03 alle 23:30 UTC sono le 01:30 del 01/04 a Roma; la lettera è delle 23:10 UTC, cioè delle 01:10 del 01/04.
    $this->travelTo(\Carbon\Carbon::parse('2026-03-31 23:10:00', 'UTC'));
    lttRigaScritta($s, 'ugo', 'sollecito', 'inviata', 0, 'email');
    $this->travelTo(\Carbon\Carbon::parse('2026-03-31 23:30:00', 'UTC'));

    $p = cdpPagina($this, $this->user, $s['c'], $s['persone']['ugo']);
    $elenco = $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id]))->assertOk()->viewData('page')['props'];
    $riga = collect($elenco['righe']['data'])->firstWhere('anagrafica_id', $s['persone']['ugo']->id);
    expect($p['ultima_lettera']['ultima'])->not->toBeNull()
        ->and($p['ultima_lettera']['ultima'])->toMatchArray(['tipo' => 'sollecito', 'data' => '2026-04-01'])
        ->and($p['ultima_lettera']['ultima'])->toBe($riga['ultima_lettera']);
});
