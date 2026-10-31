<?php

/**
 * Coda 287 — la lista delle fatture passive segnala le regolazioni immediate non stornate scritte su una voce di entrata.
 *
 * Fino alla 1.11.0-beta.51 il menu della regolazione immediata offriva anche le voci di entrata, e la registrazione scriveva DARE sul mastro di
 * costo della voce e AVERE sulla cassa: la banca scende invece di salire. Dalla .52 la registrazione le rifiuta, ma quelle già scritte restano.
 * La lista delle fatture passive riceve l'elenco (`regolazioniSuVoceDiEntrata`, da `RegolazioniSuVoceDiEntrata::delCondominio`) per il condominio
 * della rotta, non per esercizio: protocollo, data, importo in centesimi, nome e id della voce (la lista dice su quante voci stanno le regolazioni, e due voci
 * possono avere lo stesso nome) e id della scrittura per il collegamento alla sua scheda.
 * Stornata dalla scheda, la regolazione esce dall'elenco. Il tipo è quello di oggi: una voce di spesa diventata un'entrata conta.
 *
 * Fonte dell'atteso: la riga della Coda 287 nella roadmap («scrittura `regolazione_immediata` non stornata [...] la cui riga DARE ha
 * `voce_spesa_id` su una voce che oggi non è di spesa») e la decisione 163.2 (D2: per condominio, non per esercizio, e l'avviso si spegne quando
 * l'ultima è stornata). Le cifre sono quelle scritte nel modulo (€ 300,00 il 10/03/2026 → 30000 centesimi); il protocollo lo assegna il programma
 * e si legge dalla scrittura.
 *
 * Il dato di prima si costruisce come lo scriveva la 1.11.0-beta.51: la regolazione passa dalla rotta su una voce di spesa, poi la sua riga DARE
 * prende la voce di entrata (stesso mastro: la voce lo riceve dal tipo di spesa, non dal tipo); oppure la voce stessa diventa un'entrata.
 *
 * Che cosa NON copre: la schermata (`FatturaRegisterListRegolazioniEntrata.test.ts`); MySQL.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\ScritturaContabile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

require_once __DIR__.'/Beta52VoceEntrataScenari.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = be52Utente();
    Event::fake([\App\Events\Gestionale\FatturaRegistrata::class]);
});

/** Una regolazione dalla rotta, sulla voce data: € 300,00 il 10/03/2026 di default. */
function b60rRegolazione($test, array $s, int $contoId, float $euro = 300.00, string $data = '2026-03-10'): ScritturaContabile
{
    $test->actingAs($test->user)->post(route('admin.gestionale.regolazioni-immediate.store', [$s['c']]), [
        'gestione_id' => $s['g']->id, 'esercizio_id' => $s['e']->id, 'conto_id' => $contoId, 'cassa_id' => $s['cassa']->id,
        'data_operazione' => $data, 'causale' => 'Interessi attivi del conto', 'importo' => $euro,
    ])->assertSessionHasNoErrors();

    return ScritturaContabile::where('condominio_id', $s['c']->id)->where('tipo_movimento', 'regolazione_immediata')->latest('id')->firstOrFail();
}

/** La regolazione come la scriveva la 1.11.0-beta.51 su una voce di entrata: la riga DARE porta la voce di entrata e il suo mastro. */
function b60rRegolazioneSuEntrata($test, array $s, float $euro = 300.00, string $data = '2026-03-10'): ScritturaContabile
{
    $r = b60rRegolazione($test, $s, $s['spesa']->id, $euro, $data);
    DB::table('righe_scritture')->where('scrittura_id', $r->id)->where('tipo_riga', 'dare')
        ->update(['voce_spesa_id' => $s['entrata']->id, 'conto_contabile_id' => $s['entrata']->conto_contabile_id]);

    return $r;
}

/** L'elenco che la lista delle fatture passive riceve, dalla sua rotta. */
function b60rAvviso($test, array $s): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.fatture.index', [$s['c']]))->assertOk()->viewData('page')['props']['regolazioniSuVoceDiEntrata'];
}

it('Coda 287 — una regolazione non stornata scritta su una voce di entrata compare nell\'avviso della lista delle fatture, con protocollo, data, importo e voce', function () {
    $s = be52Scenario($this);
    $r = b60rRegolazioneSuEntrata($this, $s);

    expect(b60rAvviso($this, $s))->toBe([
        ['id' => $r->id, 'numero_protocollo' => $r->numero_protocollo, 'data' => '10/03/2026', 'importo' => 30000, 'voce' => 'Affitto antenna', 'voce_id' => $s['entrata']->id],
    ]);
    // Il fatto che l'avviso dice: la banca è scesa dell'importo (AVERE sulla cassa), e lo speso della voce di entrata è salito.
    expect((int) DB::table('righe_scritture')->where('scrittura_id', $r->id)->where('tipo_riga', 'avere')->whereNotNull('cassa_id')->value('importo'))->toBe(30000)
        ->and($r->numero_protocollo)->not->toBeNull();
});

it('Coda 287 — due regolazioni sulla stessa voce di entrata portano lo stesso id della voce, quella su un\'altra voce un id diverso', function () {
    $s = be52Scenario($this);
    $prima = b60rRegolazioneSuEntrata($this, $s, 300.00, '2026-03-10');
    $seconda = b60rRegolazioneSuEntrata($this, $s, 45.00, '2026-04-02');
    // Una seconda voce con lo stesso nome: la lista le distingue per id.
    $omonima = DB::table('conti')->where('id', $s['entrata']->id)->first();
    $idOmonima = DB::table('conti')->insertGetId(array_merge((array) $omonima, ['id' => null, 'nome' => 'Affitto antenna']));
    $terza = b60rRegolazioneSuEntrata($this, $s, 80.00, '2026-05-03');
    DB::table('righe_scritture')->where('scrittura_id', $terza->id)->where('tipo_riga', 'dare')->update(['voce_spesa_id' => $idOmonima]);

    $voci = collect(b60rAvviso($this, $s))->pluck('voce_id', 'id')->all();
    expect($voci)->toBe([$prima->id => $s['entrata']->id, $seconda->id => $s['entrata']->id, $terza->id => $idOmonima])
        ->and($idOmonima)->not->toBe($s['entrata']->id);
});

it('Coda 287 — una regolazione su una voce di spesa diventata poi un\'entrata compare: conta il tipo di oggi', function () {
    $s = be52Scenario($this);
    $r = b60rRegolazione($this, $s, $s['spesa']->id, 120.50, '2026-02-01');
    DB::table('conti')->where('id', $s['spesa']->id)->update(['tipo' => 'entrata']);

    expect(b60rAvviso($this, $s))->toBe([
        ['id' => $r->id, 'numero_protocollo' => $r->numero_protocollo, 'data' => '01/02/2026', 'importo' => 12050, 'voce' => 'Spese generali', 'voce_id' => $s['spesa']->id],
    ]);
});

it('Coda 287 — controprova: una regolazione su una voce di spesa non compare, e l\'elenco è vuoto', function () {
    $s = be52Scenario($this);
    b60rRegolazione($this, $s, $s['spesa']->id);

    expect(b60rAvviso($this, $s))->toBe([]);
});

it('Coda 287 — stornata dalla sua scheda, la regolazione esce dall\'avviso; quella non stornata resta', function () {
    $s = be52Scenario($this);
    $prima = b60rRegolazioneSuEntrata($this, $s, 300.00, '2026-03-10');
    $seconda = b60rRegolazioneSuEntrata($this, $s, 45.00, '2026-04-02');

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $prima]), ['motivo' => 'Era un interesse attivo, non una spesa'])
        ->assertSessionHasNoErrors();

    expect(collect(b60rAvviso($this, $s))->pluck('id')->all())->toBe([$seconda->id]);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $seconda]), ['motivo' => 'Era un rimborso, non una spesa'])
        ->assertSessionHasNoErrors();

    // L'ultima stornata: l'elenco è vuoto e la pagina non mostra più il blocco.
    expect(b60rAvviso($this, $s))->toBe([]);
});

it('Coda 287 — la regolazione di un altro condominio non compare', function () {
    $s = be52Scenario($this);
    $altro = be52Scenario($this);
    $mia = b60rRegolazioneSuEntrata($this, $s);
    b60rRegolazioneSuEntrata($this, $altro, 80.00);

    expect(collect(b60rAvviso($this, $s))->pluck('id')->all())->toBe([$mia->id])
        ->and(collect(b60rAvviso($this, $altro))->pluck('importo')->all())->toBe([8000]);

    // Il condominio è quello della rotta: un id nell'indirizzo non lo cambia.
    $props = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.index', [$s['c'], 'condominio_id' => $altro['c']->id]))->assertOk()->viewData('page')['props'];
    expect(collect($props['regolazioniSuVoceDiEntrata'])->pluck('id')->all())->toBe([$mia->id]);
});

it('Coda 287 — una regolazione di un esercizio chiuso compare, e lo storno dalla sua scheda passa nell\'esercizio aperto e la toglie', function () {
    $s = be52Scenario($this);
    $r = b60rRegolazioneSuEntrata($this, $s);
    $s['e']->update(['stato' => 'chiuso']);
    $nuovo = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);

    expect(collect(b60rAvviso($this, $s))->pluck('id')->all())->toBe([$r->id]);

    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $r]), ['motivo' => 'Era un interesse attivo, non una spesa'])
        ->assertSessionHasNoErrors();

    expect(ScritturaContabile::where('scrittura_padre_id', $r->id)->value('esercizio_id'))->toBe($nuovo->id)
        ->and(b60rAvviso($this, $s))->toBe([]);
});

it('Coda 287 — senza un esercizio aperto l\'avviso compare lo stesso, la pagina riceve l\'esercizio vuoto e lo storno si rifiuta', function () {
    $s = be52Scenario($this);
    $r = b60rRegolazioneSuEntrata($this, $s);
    $s['e']->update(['stato' => 'chiuso']);

    $props = $this->actingAs($this->user)->get(route('admin.gestionale.fatture.index', [$s['c']]))->assertOk()->viewData('page')['props'];
    expect(collect($props['regolazioniSuVoceDiEntrata'])->pluck('id')->all())->toBe([$r->id])
        ->and($props['esercizio'])->toBeNull();

    // Il fatto su cui poggia la frase della pagina senza esercizio aperto: lo storno di una scrittura di un esercizio chiuso cerca l'esercizio aperto.
    $this->actingAs($this->user)->post(route('admin.gestionale.regolazioni-immediate.storno', [$s['c'], $r]), ['motivo' => 'Era un interesse attivo, non una spesa'])
        ->assertSessionHas('message.type', 'error');
    expect(ScritturaContabile::where('scrittura_padre_id', $r->id)->count())->toBe(0);
});
