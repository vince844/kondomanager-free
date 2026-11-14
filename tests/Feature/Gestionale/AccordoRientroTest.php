<?php

/**
 * L'accordo di rientro come registro, sul caso di Giulia: un'unità, quattro rate trimestrali da € 750,00 (31/01, 30/04, 31/07, 31/10/2026); le
 * rate 1 e 2 emesse il 10/01, la 3 il 01/07, la 4 il 01/10. Il 26/06 Giulia firma un accordo sulle rate 1 e 2 (€ 1.500,00 riconosciuti, il
 * residuo di quel giorno), fino al 15/12, in sei rate da € 252,95 (l'ultima € 252,92) che contengono anche € 17,67 di interessi e spese.
 *
 * - Nell'elenco le rate coperte restano (sono scadute) con il segno: riconosciuti, e quanto resta oggi; «residuo invariato» se è lo stesso,
 *   anche dopo un versamento stornato.
 * - Le lettere proposte lasciano fuori la parte riconosciuta (il minore fra il residuo di oggi e l'importo riconosciuto), e l'anteprima lo
 *   dice; chi ha solo rate in accordo non è nella lettera, se l'amministratore non le rimette. Dopo «fino al» le rate tornano nelle lettere.
 * - Gli interessi e le spese versati con le rate dell'accordo diventano un credito: l'accordo e l'elenco lo dicono.
 * - «Autorizzato dall'assemblea» non ha una risposta già scelta: senza, il server rifiuta.
 */

use App\Models\Gestionale\AccordoRientro;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $permesso = Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']);
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo($permesso);
    $this->user = User::factory()->create(['name' => 'Anna Studio', 'email' => 'studio@prova.it']);
    $this->user->assignRole($ruolo);
});

/** Giulia con le quattro rate; la 3 e la 4 si emettono quando nascono. Con `$altri` anche altre persone (unità => [nome, millesimi]). */
function acrGiulia($test, array $altri = []): array
{
    $test->travelTo('2026-01-05 10:00:00');
    $s = lcdScenario(['7' => ['giulia', 1000]] + $altri, 4, 'prima_rata', [], $altri === [] ? 300000 : 600000, '2026-01-31', 31);
    foreach ([1 => '2026-01-31', 2 => '2026-04-30', 3 => '2026-07-31', 4 => '2026-10-31'] as $n => $data) {
        DB::table('rate')->where('id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
        DB::table('rate_quote')->where('rata_id', lcdRata($s, $n))->update(['data_scadenza' => $data]);
    }
    $test->travelTo('2026-01-10 10:00:00');
    lcdEmetti($test, $s, [1, 2], '2026-01-10');
    $test->travelTo('2026-07-01 10:00:00');
    lcdEmetti($test, $s, [3], '2026-07-01');
    $test->travelTo('2026-10-01 10:00:00');
    lcdEmetti($test, $s, [4], '2026-10-01');

    return $s;
}

/** Un versamento di Giulia dalla rotta della pagina, con la dichiarazione dell'imputazione se serve. */
function acrIncassa($test, array $s, array $righe, string $data, int $parteInPiu = 0, ?string $scelta = null): int
{
    $test->travelTo($data . ' 11:00:00');
    $totale = array_sum($righe) + $parteInPiu;
    $dati = [
        'pagante_id' => $s['persone']['giulia']->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => $data, 'importo_totale' => number_format($totale / 100, 2, '.', ''),
        'descrizione' => 'Bonifico', 'eccedenza' => number_format($parteInPiu / 100, 2, '.', ''), 'gestione_id' => $s['g']->id,
        'dettaglio_pagamenti' => array_map(fn ($id, $cent) => ['rata_id' => (int) $id, 'importo' => number_format($cent / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
    ];
    if ($scelta !== null) {
        $dati['imputazione_scelta'] = $scelta;
        $dati['imputazione_nota'] = 'Rata 3/2026, art. 3 dell\'accordo';
    }
    $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), $dati)->assertSessionHasNoErrors();

    return (int) DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->max('id');
}

/** L'accordo registrato dalla rotta, il giorno dell'accordo: le rate [numero => centesimi], «fino al», autorizzato sì o no. */
function acrRegistra($test, array $s, string $chi, array $rate, string $data = '2026-06-26', string $finoAl = '2026-12-15', array $altro = [])
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), array_merge([
        'anagrafica_id' => $s['persone'][$chi]->id,
        'data_accordo' => $data,
        'fino_al' => $finoAl,
        'rate' => collect($rate)->map(fn ($cent, $n) => ['rata_id' => lcdRata($s, $n), 'immobile_id' => $s['immobili'][array_key_first($s['immobili'])]->id,
            'importo' => number_format($cent / 100, 2, ',', '.')])->values()->all(),
        'autorizzato_assemblea' => '0',
        'nota' => 'L\'assemblea è convocata per il 10/09',
    ], $altro));
}

function acrElenco($test, array $s, array $query = []): array
{
    return $test->actingAs($test->user)->get(route('admin.gestionale.rate-scadute.index', ['condominio' => $s['c']->id] + $query))->assertOk()->viewData('page')['props'];
}

function acrAnteprima($test, array $s, array $altro = []): array
{
    return $test->actingAs($test->user)->postJson(route('admin.gestionale.lettere.anteprima', ['condominio' => $s['c']->id]), array_merge([
        'tipo' => 'sollecito', 'tutte' => 1, 'filtri' => [], 'testo' => 'Gentile [nome], risultano da versare [totale].', 'termine' => 15,
    ], $altro))->assertOk()->json();
}

it('l\'accordo come registro: le rate coperte restano nell\'elenco con il segno delle due cifre, e la lettera proposta contiene la sola rata fuori dall\'accordo', function () {
    $s = acrGiulia($this);
    $q = fn (int $n) => lcdQuota($s, 'giulia', $n);

    // Il 26/06 le rate che l'accordo può coprire sono la 1 e la 2, con il residuo di quel giorno come importo proposto.
    $this->travelTo('2026-06-26 10:00:00');
    $proposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.accordi-rientro.index', ['condominio' => $s['c']->id,
        'anagrafica_id' => $s['persone']['giulia']->id, 'data' => '2026-06-26']))->assertOk()->json();
    expect(collect($proposta['rate'])->map(fn ($r) => [$r['numero_rata'], $r['residuo']])->all())->toBe([[1, 75000], [2, 75000]])
        ->and($proposta['accordi'])->toBe([]);
    acrRegistra($this, $s, 'giulia', [1 => 75000, 2 => 75000])->assertCreated();

    acrIncassa($this, $s, [$q(1) => 25295], '2026-07-15');
    acrIncassa($this, $s, [$q(3) => 75000], '2026-07-25', 0, 'indicata_dal_versante');
    acrIncassa($this, $s, [$q(1) => 25295], '2026-08-15');

    // Al 04/11: le rate 1 e 2 (€ 994,10) e la 4, scaduta da 4 giorni (€ 750,00).
    $this->travelTo('2026-11-04 10:00:00');
    $giulia = collect(acrElenco($this, $s)['righe']['data'])->sole();
    expect($giulia['importo'])->toBe(174410)
        ->and($giulia['accordi'])->toHaveCount(1)
        ->and($giulia['accordi'][0])->toMatchArray(['data_accordo' => '2026-06-26', 'fino_al' => '2026-12-15', 'riconosciuti' => 150000, 'oggi' => 99410,
            'invariato' => false, 'fino_al_passato' => false, 'versati_oltre' => 0, 'autorizzato_assemblea' => false]);
    $rate = collect($giulia['rate'])->keyBy('numero_rata');
    expect($rate[1]['accordo'])->toMatchArray(['riconosciuto' => 75000, 'in_accordo' => 24410])
        ->and($rate[2]['accordo'])->toMatchArray(['riconosciuto' => 75000, 'in_accordo' => 75000])
        ->and($rate[4]['accordo'])->toBeNull();

    // La lettera proposta: la sola rata 4, e l'anteprima dice quanto è in accordo.
    $a = acrAnteprima($this, $s);
    expect($a['persone'])->toHaveCount(1)
        ->and($a['persone'][0])->toMatchArray(['nome' => 'Giulia', 'totale' => 75000, 'righe' => 1, 'in_accordo' => 99410])
        ->and($a['solo_in_accordo'])->toBe([]);

    // La stampa scrive la lettera con la sola rata 4.
    $this->actingAs($this->user)->post(route('admin.gestionale.lettere.stampa', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'persone' => [$s['persone']['giulia']->id], 'testo' => 'Gentile [nome]', 'termine' => 15,
    ])->assertOk();
    $riga = \App\Models\Gestionale\RegistroInvio::where('anagrafica_id', $s['persone']['giulia']->id)->where('tipo', 'sollecito')->sole();
    expect($riga->dati['totale'])->toBe(75000)
        ->and(collect($riga->dati['righe'])->pluck('numero_rata')->all())->toBe([4]);

    // Con «metti anche le rate in accordo» la lettera torna a € 1.744,10.
    expect(acrAnteprima($this, $s, ['anche_in_accordo' => 1])['persone'][0])->toMatchArray(['totale' => 174410, 'righe' => 3, 'in_accordo' => 0]);
});

it('il segno dice «residuo invariato» quando non è cambiato niente, anche dopo un versamento stornato', function () {
    $s = acrGiulia($this);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000, 2 => 75000])->assertCreated();
    acrIncassa($this, $s, [lcdQuota($s, 'giulia', 3) => 75000], '2026-07-25', 0, 'indicata_dal_versante');

    $this->travelTo('2026-09-20 10:00:00');
    $accordo = collect(acrElenco($this, $s)['righe']['data'])->sole()['accordi'][0];
    expect($accordo)->toMatchArray(['riconosciuti' => 150000, 'oggi' => 150000, 'invariato' => true]);

    // Un versamento di € 252,95 stornato lascia il residuo uguale: la stessa frase, non «nessun versamento».
    $incasso = acrIncassa($this, $s, [lcdQuota($s, 'giulia', 1) => 25295], '2026-09-21');
    $this->travelTo('2026-09-22 10:00:00');
    lcdStorna($this, $s, $incasso);
    $accordo = collect(acrElenco($this, $s)['righe']['data'])->sole()['accordi'][0];
    expect($accordo)->toMatchArray(['riconosciuti' => 150000, 'oggi' => 150000, 'invariato' => true]);
});

it('una rata coperta solo in parte: nella lettera resta la parte non riconosciuta (€ 300,00 di una rata da € 500,00 con € 200,00 nell\'accordo)', function () {
    $this->travelTo('2026-01-02 10:00:00');
    $s = lcdScenario(['A' => ['giulia', 1000]], 12, 'prima_rata', [], 600000);
    lcdEmetti($this, $s, [1], '2026-01-02');
    $this->travelTo('2026-02-10 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 20000], '2026-02-10', '2026-06-30')->assertCreated();

    $a = acrAnteprima($this, $s);
    expect($a['persone'][0])->toMatchArray(['totale' => 30000, 'righe' => 1, 'in_accordo' => 20000]);
    $rata = collect(acrElenco($this, $s)['righe']['data'])->sole()['rate'][0];
    expect($rata['residuo'])->toBe(50000)->and($rata['accordo'])->toMatchArray(['riconosciuto' => 20000, 'in_accordo' => 20000]);

    // Il sollecito per email: la lettera chiede € 300,00; la riga tiene il residuo della rata (€ 500,00) e a parte la parte in accordo, così la
    // guardia alla partenza lo rilegge com'è. Se niente cambia, parte; il PDF dice € 300,00.
    \Illuminate\Support\Facades\Queue::fake();
    config(['mail.default' => 'array']);
    $this->actingAs($this->user)->postJson(route('admin.gestionale.lettere.invia', ['condominio' => $s['c']->id]), [
        'tipo' => 'sollecito', 'persone' => [$s['persone']['giulia']->id], 'testo' => 'Gentile [nome], [totale].', 'termine' => 15, 'indirizzi' => ['email'],
    ])->assertOk();
    $riga = \App\Models\Gestionale\RegistroInvio::where('tipo', 'sollecito')->sole();
    expect($riga->dati['totale'])->toBe(30000)
        ->and($riga->dati['in_accordo'])->toBe(20000)
        ->and($riga->dati['righe'][0])->toMatchArray(['residuo' => 50000, 'in_accordo' => 20000, 'da_versare' => 30000])
        ->and(\App\Notifications\Gestionale\LetteraNotification::debitoCambiato($riga))->toBeFalse();
    $html = \Illuminate\Support\Facades\View::make('pdf.gestionale.lettera', app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)
        ->datiDellaVista($s['c'], [app(\App\Services\Gestionale\Lettere\EmissioneLettere::class)->voceDallaRiga($riga)]))->render();
    expect($html)->toContain('€ 300,00')->and($html)->not->toContain('€ 500,00');

    // Giulia versa € 100,00 sulla rata: il programma non sa a quale parte, e lascia fuori dalla lettera il minore fra il residuo e il
    // riconosciuto (€ 200,00); nella lettera € 200,00.
    $this->travelTo('2026-02-11 10:00:00');
    lcdIncassa($this, $s, 'giulia', [lcdQuota($s, 'giulia', 1) => 10000], '2026-02-11');
    $this->travelTo('2026-02-12 10:00:00');
    expect(acrAnteprima($this, $s)['persone'][0])->toMatchArray(['totale' => 20000, 'in_accordo' => 20000]);
    // Il sollecito in coda chiedeva € 300,00 su un residuo di € 500,00: il residuo è cambiato, e la lettera non parte con la cifra vecchia.
    expect(\App\Notifications\Gestionale\LetteraNotification::debitoCambiato($riga->fresh()))->toBeTrue();
});

it('chi ha solo rate in accordo non è nella lettera proposta, se l\'amministratore non le rimette; il filtro «con accordo» la ritrova; dopo «fino al» le rate tornano nelle lettere', function () {
    $s = acrGiulia($this, ['8' => ['marco', 1000]]);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000, 2 => 75000], '2026-06-26', '2026-10-15')->assertCreated();
    acrIncassa($this, $s, [lcdQuota($s, 'giulia', 3) => 75000], '2026-07-25', 0, 'indicata_dal_versante');

    // Il 20/09 la rata 4 non è scaduta: Giulia ha solo le rate in accordo; Marco ha le rate 1, 2, 3.
    $this->travelTo('2026-09-20 10:00:00');
    $a = acrAnteprima($this, $s);
    expect(collect($a['persone'])->pluck('nome')->all())->toBe(['Marco'])
        ->and($a['solo_in_accordo'])->toBe(['Giulia']);
    expect(collect(acrAnteprima($this, $s, ['anche_in_accordo' => 1])['persone'])->pluck('nome')->all())->toBe(['Giulia', 'Marco']);

    expect(collect(acrElenco($this, $s, ['con_accordo' => 1])['righe']['data'])->pluck('nome')->all())->toBe(['Giulia'])
        ->and(acrElenco($this, $s, ['con_accordo' => 1])['filtri']['con_accordo'])->toBeTrue()
        ->and(collect(acrElenco($this, $s)['righe']['data'])->pluck('nome')->sort()->values()->all())->toBe(['Giulia', 'Marco']);

    // Il 16/10, passato il «fino al»: il segno lo dice, e le rate tornano nelle lettere proposte.
    $this->travelTo('2026-10-16 10:00:00');
    $giulia = collect(acrElenco($this, $s)['righe']['data'])->firstWhere('nome', 'Giulia');
    expect($giulia['accordi'][0])->toMatchArray(['fino_al' => '2026-10-15', 'fino_al_passato' => true]);
    expect(collect(acrAnteprima($this, $s)['persone'])->firstWhere('nome', 'Giulia'))->toMatchArray(['totale' => 150000, 'in_accordo' => 0]);
});

it('interessi e spese versati con le rate dell\'accordo: a fine accordo il capitale è a zero, il credito è € 17,67, e l\'accordo e l\'elenco lo dicono', function () {
    $s = acrGiulia($this);
    $q = fn (int $n) => lcdQuota($s, 'giulia', $n);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000, 2 => 75000])->assertCreated();

    // Le sei rate dell'accordo, sul capitale più vecchio; la rata 3 a parte; la rata 4 non la paga.
    acrIncassa($this, $s, [$q(1) => 25295], '2026-07-15');
    acrIncassa($this, $s, [$q(3) => 75000], '2026-07-25', 0, 'indicata_dal_versante');
    acrIncassa($this, $s, [$q(1) => 25295], '2026-08-15');
    acrIncassa($this, $s, [$q(1) => 24410, $q(2) => 885], '2026-09-15');
    acrIncassa($this, $s, [$q(2) => 25295], '2026-10-15');
    acrIncassa($this, $s, [$q(2) => 25295], '2026-11-15');
    acrIncassa($this, $s, [$q(2) => 23525], '2026-12-15', 1767);

    $this->travelTo('2026-12-16 10:00:00');
    $giulia = collect(acrElenco($this, $s)['righe']['data'])->sole();
    expect($giulia['importo'])->toBe(75000)
        ->and($giulia['credito'])->toBe(1767)
        ->and($giulia['accordi'][0])->toMatchArray(['riconosciuti' => 150000, 'oggi' => 0, 'versati_oltre' => 1767, 'fino_al_passato' => true]);

    $proposta = $this->actingAs($this->user)->getJson(route('admin.gestionale.accordi-rientro.index', ['condominio' => $s['c']->id,
        'anagrafica_id' => $s['persone']['giulia']->id]))->assertOk()->json();
    expect($proposta['accordi'][0])->toMatchArray(['riconosciuti' => 150000, 'oggi' => 0, 'versati_oltre' => 1767]);
    // Nessuna scrittura in più: l'accordo non sposta denaro.
    expect(DB::table('scritture_contabili')->where('condominio_id', $s['c']->id)->where('tipo_movimento', 'incasso_rata')->count())->toBe(7);
});

it('«autorizzato dall\'assemblea» non ha una risposta già scelta: senza la scelta il server rifiuta; con il sì servono la data; le rate sono scadute della persona alla data dell\'accordo', function () {
    $s = acrGiulia($this);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000], altro: ['autorizzato_assemblea' => null])->assertStatus(422)->assertJsonValidationErrors('autorizzato_assemblea');
    acrRegistra($this, $s, 'giulia', [1 => 75000], altro: ['autorizzato_assemblea' => '1'])->assertStatus(422)->assertJsonValidationErrors('data_autorizzazione');
    // La rata 3 il 26/06 non è scaduta: non si copre.
    acrRegistra($this, $s, 'giulia', [3 => 75000])->assertStatus(422)->assertJsonValidationErrors('rate');
    // Più del residuo di quel giorno: no (interessi e spese il programma non li registra).
    acrRegistra($this, $s, 'giulia', [1 => 75001])->assertStatus(422)->assertJsonValidationErrors('rate');
    // «Fino al» prima dell'accordo: no.
    acrRegistra($this, $s, 'giulia', [1 => 75000], '2026-06-26', '2026-06-01')->assertStatus(422)->assertJsonValidationErrors('fino_al');
    expect(AccordoRientro::count())->toBe(0);

    acrRegistra($this, $s, 'giulia', [1 => 75000], altro: ['autorizzato_assemblea' => '1', 'data_autorizzazione' => '2026-06-20', 'verbale_autorizzazione' => '12',
        'documento' => UploadedFile::fake()->create('accordo.pdf', 100, 'application/pdf')])->assertCreated();
    $accordo = AccordoRientro::sole();
    expect($accordo->autorizzato_assemblea)->toBeTrue()
        ->and($accordo->documenti()->count())->toBe(1)
        ->and($accordo->rate()->sole()->importo_riconosciuto)->toBe(75000);
    // La stessa rata in un secondo accordo, mentre il primo è in corso: no (si chiude il primo).
    acrRegistra($this, $s, 'giulia', [1 => 10000])->assertStatus(422)->assertJsonValidationErrors('rate');
    expect(AccordoRientro::count())->toBe(1);
});

it('l\'accordo si chiude con una data e una nota, e non si cancella; chiuso, non toglie più niente dalle lettere', function () {
    $s = acrGiulia($this);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000, 2 => 75000])->assertCreated();
    $accordo = AccordoRientro::sole();
    // Al 04/11 le quattro rate sono scadute (€ 3.000,00): nella lettera la 3 e la 4, la 1 e la 2 in accordo.
    $this->travelTo('2026-11-04 10:00:00');
    expect(acrAnteprima($this, $s)['persone'][0])->toMatchArray(['totale' => 150000, 'in_accordo' => 150000]);

    $rotta = route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $s['c']->id, 'accordo' => $accordo->id]);
    $this->actingAs($this->user)->postJson($rotta, ['chiuso_il' => '2026-06-01'])->assertStatus(422)->assertJsonValidationErrors('chiuso_il');
    $this->actingAs($this->user)->postJson($rotta, ['chiuso_il' => '2026-11-04', 'nota_chiusura' => 'Rate non versate'])->assertOk();
    expect($accordo->fresh()->chiuso_il->format('Y-m-d'))->toBe('2026-11-04')
        ->and(AccordoRientro::count())->toBe(1)
        ->and(acrAnteprima($this, $s)['persone'][0])->toMatchArray(['totale' => 300000, 'in_accordo' => 0]);
    $this->actingAs($this->user)->postJson($rotta, ['chiuso_il' => '2026-11-04'])->assertStatus(422);
});

it('le rotte dell\'accordo stanno nel pannello e nel condominio giusto: una persona o un accordo di un altro condominio fa 404, un condòmino 403', function () {
    $s = acrGiulia($this);
    $altro = acrGiulia($this);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000])->assertCreated();
    $accordo = AccordoRientro::sole();

    $this->actingAs($this->user)->getJson(route('admin.gestionale.accordi-rientro.index', ['condominio' => $altro['c']->id, 'anagrafica_id' => $s['persone']['giulia']->id]))->assertNotFound();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $altro['c']->id]), [
        'anagrafica_id' => $s['persone']['giulia']->id, 'data_accordo' => '2026-06-26', 'fino_al' => '2026-12-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($s, 1), 'immobile_id' => $s['immobili']['7']->id, 'importo' => '750,00']],
    ])->assertNotFound();
    // Una rata dell'altro condominio nella richiesta per Giulia: 404.
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.store', ['condominio' => $s['c']->id]), [
        'anagrafica_id' => $s['persone']['giulia']->id, 'data_accordo' => '2026-06-26', 'fino_al' => '2026-12-15', 'autorizzato_assemblea' => '0',
        'rate' => [['rata_id' => lcdRata($altro, 1), 'immobile_id' => $altro['immobili']['7']->id, 'importo' => '750,00']],
    ])->assertNotFound();
    $this->actingAs($this->user)->postJson(route('admin.gestionale.accordi-rientro.chiudi', ['condominio' => $altro['c']->id, 'accordo' => $accordo->id]), ['chiuso_il' => '2026-06-26'])->assertNotFound();
    $this->actingAs($this->user)->get(route('admin.gestionale.accordi-rientro.documento', ['condominio' => $altro['c']->id, 'accordo' => $accordo->id]))->assertNotFound();

    $condomino = User::factory()->create();
    $this->actingAs($condomino)->getJson(route('admin.gestionale.accordi-rientro.index', ['condominio' => $s['c']->id, 'anagrafica_id' => $s['persone']['giulia']->id]))->assertForbidden();
});

it('alle 00:30 del 27/06 a Roma l\'accordo del 26/06 non si registra con una data dopo oggi, e quello di oggi sì: il giorno è quello dell\'amministratore', function () {
    config(['app.user_timezone' => 'Europe/Rome']);
    $s = acrGiulia($this);
    $this->travelTo(\Carbon\Carbon::parse('2026-06-27 00:30', 'Europe/Rome'));
    acrRegistra($this, $s, 'giulia', [1 => 75000], '2026-06-28', '2026-12-15')->assertStatus(422)->assertJsonValidationErrors('data_accordo');
    acrRegistra($this, $s, 'giulia', [1 => 75000], '2026-06-27', '2026-12-15')->assertCreated();
    // L'elenco di oggi (27/06 per l'amministratore) ha l'accordo in corso.
    expect(collect(acrElenco($this, $s)['righe']['data'])->sole()['accordi'])->toHaveCount(1);
});

it('la stampa dell\'elenco scrive il segno dell\'accordo nella riga della persona', function () {
    $s = acrGiulia($this);
    $q = fn (int $n) => lcdQuota($s, 'giulia', $n);
    $this->travelTo('2026-06-26 10:00:00');
    acrRegistra($this, $s, 'giulia', [1 => 75000, 2 => 75000])->assertCreated();
    acrIncassa($this, $s, [$q(1) => 25295], '2026-07-15');
    acrIncassa($this, $s, [$q(3) => 75000], '2026-07-25', 0, 'indicata_dal_versante');
    acrIncassa($this, $s, [$q(1) => 25295], '2026-08-15');
    $this->travelTo('2026-11-04 10:00:00');

    $dati = null;
    \Illuminate\Support\Facades\View::composer('pdf.gestionale.rate_scadute', function ($vista) use (&$dati) {
        $dati ??= $vista->getData();
    });
    $this->actingAs($this->user)->get(route('admin.gestionale.rate-scadute.stampa', ['condominio' => $s['c']->id, 'tutte' => 1]))->assertOk();
    $html = \Illuminate\Support\Facades\View::make('pdf.gestionale.rate_scadute', $dati)->render();
    expect($html)->toContain('accordo di rientro del 26/06/2026, fino al 15/12/2026: riconosciuti € 1.500,00, oggi € 994,10');
});
