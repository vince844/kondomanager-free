<?php

/**
 * La gestione straordinaria su più esercizi: il conguaglio quando l'opera finisce. Con la data di fine della gestione dentro l'esercizio, il conguaglio
 * è sull'intera gestione (tutte le spese e tutte le quote della gestione, di tutti i suoi esercizi); finché l'opera è in corso (la data di fine vuota o
 * dopo l'esercizio) la lettura dice lo speso e il chiesto e non propone: la persona non ha un conguaglio per quella gestione, né nel saldo.
 *
 * Il caso: opera «Facciata», gestione straordinaria dal 01/01/2026, legata agli esercizi 2026 e 2027; la tabella «Proprietà» con Neri 500 e Verdi 500
 * millesimi; un piano del 2026 chiede € 60.000,00 in due rate (Neri 30.000,00, Verdi 30.000,00), deliberato il 01/02/2026, le due rate emesse il
 * 10/02/2026; Neri paga tutto, Verdi la prima rata (€ 15.000,00). Le fatture: 2026 € 40.000,00, 2027 € 25.000,00, senza competenza scritta (la delibera).
 * La fine il 30/06/2027.
 *
 * A mano. 2026: «in corso: spesi € 40.000,00, chiesti € 60.000,00», nessun conguaglio; il saldo di Verdi è la sua rata non pagata, € 15.000,00. 2027: spese
 * della gestione € 65.000,00, Neri 500/1000 = 32.500,00, quote 30.000,00, conguaglio +2.500,00; posizione 0 (ha pagato), saldo +2.500,00; Verdi 32.500,00 −
 * 30.000,00 = +2.500,00, posizione 15.000,00 (la seconda rata del 2026, sulla gestione della Facciata, che è dell'esercizio 2027: non va fra «da altre
 * gestioni»), saldo 15.000,00 + 2.500,00 = +17.500,00. Il condominio +5.000,00.
 */

use App\Models\Esercizio;
use App\Models\Gestionale\Conto;
use App\Models\Gestionale\PianoConto;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestione;
use App\Models\User;
use App\Services\Gestionale\ConguaglioDelRendiconto;
use App\Services\Gestionale\ConsuntivoPerPersona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../Gestionale/GestionaleTestHelpers.php';
require_once __DIR__.'/../Gestionale/LetturaDebitiProva.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
    $ruolo = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $ruolo->givePermissionTo(Permission::firstOrCreate(['name' => 'Accesso pannello amministratore', 'guard_name' => 'web']));
    $this->user = User::factory()->create();
    $this->user->assignRole($ruolo);
    $this->withoutVite();
});

/**
 * Via Roma con l'opera «Facciata»: l'esercizio 2026 e la sua ordinaria (di `lcdScenario`), l'esercizio 2027, la gestione straordinaria legata ai due, il suo
 * piano del 2026 emesso e gli incassi, le fatture. `$fine` è la data di fine della gestione (null: vuota).
 */
function opxCaso($test, ?string $fine = '2027-06-30', bool $legataAl2027 = true): array
{
    $test->travelTo('2026-03-15 10:00:00');
    $s = lcdScenario(['A' => ['neri', 500], 'B' => ['verdi', 500]], 2, 'tutte_rate');
    $e27 = Esercizio::factory()->create(['condominio_id' => $s['c']->id, 'nome' => 'Esercizio 2027', 'data_inizio' => '2027-01-01', 'data_fine' => '2027-12-31', 'stato' => 'aperto']);
    $g = Gestione::create(['condominio_id' => $s['c']->id, 'nome' => 'Facciata', 'tipo' => 'straordinaria', 'descrizione' => 'Facciata', 'attiva' => true,
        'data_inizio' => '2026-01-01', 'data_fine' => $fine]);
    legaAEsercizio($s['e'], $g->id);
    if ($legataAl2027) {
        legaAEsercizio($e27, $g->id);
    }
    $pc = PianoConto::create(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'nome' => 'PC Facciata']);
    $voce = Conto::create(['piano_conto_id' => $pc->id, 'nome' => 'Facciata', 'tipo' => 'spesa', 'natura_spesa' => 'straordinaria', 'importo' => 6000000]);
    $tabella = (int) DB::table('tabelle')->where('condominio_id', $s['c']->id)->value('id');
    $ctm = DB::table('conto_tabella_millesimale')->insertGetId(['conto_id' => $voce->id, 'tabella_id' => $tabella, 'coefficiente' => 100, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('conto_tabella_ripartizioni')->insert(['conto_tabella_millesimale_id' => $ctm, 'soggetto' => 'proprietario', 'percentuale' => 100, 'created_at' => now(), 'updated_at' => now()]);

    $piano = PianoRate::create(['gestione_id' => $g->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $s['e']->id, 'nome' => 'Facciata 2026', 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 2, 'giorno_scadenza' => 10, 'data_prima_scadenza' => '2026-03-10', 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
        'data_delibera_assemblea' => '2026-02-01']);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $voce->id, 'importo' => 6000000, 'created_at' => now(), 'updated_at' => now()]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $s['e']);
    $rate = DB::table('rate')->where('piano_rate_id', $piano->id)->orderBy('numero_rata')->pluck('id')->all();
    $test->actingAs($test->user)->post(route('admin.gestionale.piani-rate.emetti', [$s['c'], $piano]), ['rate_ids' => $rate, 'data_emissione' => '2026-02-10', 'invia_notifiche' => false])
        ->assertSessionHasNoErrors();
    $quota = fn (string $chi, int $rata) => (int) DB::table('rate_quote')->where('rata_id', $rate[$rata])->where('anagrafica_id', $s['persone'][$chi]->id)->value('id');
    foreach ([['neri', [$quota('neri', 0) => 1500000, $quota('neri', 1) => 1500000]], ['verdi', [$quota('verdi', 0) => 1500000]]] as [$chi, $righe]) {
        $test->actingAs($test->user)->post(route('admin.gestionale.movimenti-rate.store', [$s['c']]), [
            'pagante_id' => $s['persone'][$chi]->id, 'cassa_id' => $s['cassa']->id, 'data_pagamento' => '2026-03-10', 'importo_totale' => number_format(array_sum($righe) / 100, 2, '.', ''),
            'descrizione' => 'Bonifico', 'eccedenza' => '0.00', 'gestione_id' => $g->id,
            'dettaglio_pagamenti' => array_map(fn ($id, $c) => ['rata_id' => $id, 'importo' => number_format($c / 100, 2, '.', '')], array_keys($righe), array_values($righe)),
        ])->assertSessionHasNoErrors();
    }

    $giornale = ['costi' => DB::table('conti_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'codice' => '60.10', 'nome' => 'Costi', 'tipo' => 'costo', 'categoria' => 'costi',
        'created_at' => now(), 'updated_at' => now()]),
        'debiti' => DB::table('conti_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'codice' => '40.10', 'nome' => 'Debiti verso fornitori', 'tipo' => 'passivo',
            'ruolo' => 'debiti_fornitori', 'categoria' => 'debiti', 'created_at' => now(), 'updated_at' => now()])];
    opxSpesa($s, $s['e'], $g, $voce, $giornale, 4000000, '2026-09-15');
    opxSpesa($s, $e27, $g, $voce, $giornale, 2500000, '2027-05-15');

    return $s + ['e27' => $e27, 'facciata' => $g, 'voce' => $voce, 'piano_facciata' => $piano, 'giornale' => $giornale];
}

/** Una spesa dell'opera sul giornale dell'esercizio dato: DARE costi con la voce, AVERE i debiti. */
function opxSpesa(array $s, Esercizio $e, Gestione $g, Conto $voce, array $giornale, int $importo, string $giorno): void
{
    $sc = DB::table('scritture_contabili')->insertGetId(['condominio_id' => $s['c']->id, 'gestione_id' => $g->id, 'esercizio_id' => $e->id, 'data_registrazione' => $giorno,
        'data_competenza' => $giorno, 'numero_protocollo' => 'OPX-'.$giorno, 'causale' => 'fattura', 'tipo_movimento' => 'fattura_acquisto', 'stato' => 'registrata',
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('righe_scritture')->insert([
        ['scrittura_id' => $sc, 'conto_contabile_id' => $giornale['costi'], 'voce_spesa_id' => $voce->id, 'tipo_riga' => 'dare', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
        ['scrittura_id' => $sc, 'conto_contabile_id' => $giornale['debiti'], 'voce_spesa_id' => null, 'tipo_riga' => 'avere', 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()],
    ]);
}

/** La riga di una persona sulla gestione dell'opera. */
function opxRiga(array $letto, array $s, string $chi): ?array
{
    $righe = $letto['persone'][$s['facciata']->id][$s['persone'][$chi]->id] ?? [];

    return $righe === [] ? null : reset($righe);
}

it('2026, l\'opera in corso: lo speso e il chiesto, nessun conguaglio per la Facciata, e il saldo di Verdi è la sua rata non pagata', function () {
    $s = opxCaso($this);
    $this->travelTo('2027-01-10 10:00:00');
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $g = $letto['gestioni'][$s['facciata']->id];

    expect($g['in_corso'])->toMatchArray(['spesi' => 4000000, 'chiesti' => 6000000, 'speso_esercizio' => 4000000, 'data_fine' => '2027-06-30'])
        ->and($g['uscite'])->toBe([])->and($g['totali']['conguaglio'])->toBe(0)
        // Neri ha pagato tutto: sulla gestione dell'opera non ha niente da mostrare, nessuna riga.
        ->and(opxRiga($letto, $s, 'neri'))->toBeNull()
        ->and(opxRiga($letto, $s, 'verdi'))->toMatchArray(['a' => 0, 'conguaglio' => 0, 'posizione' => 1500000, 'saldo' => 1500000, 'in_corso' => true])
        ->and(opxRiga($letto, $s, 'verdi')['b']['totale'])->toBe(0);
});

it('2027, l\'opera finita il 30/06: il conguaglio sull\'intera gestione, +2.500,00 a testa; il saldo di Verdi conta la sua rata una volta sola', function () {
    $s = opxCaso($this);
    $this->travelTo('2028-01-10 10:00:00');
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $g = $letto['gestioni'][$s['facciata']->id];
    $neri = opxRiga($letto, $s, 'neri');
    $verdi = opxRiga($letto, $s, 'verdi');

    expect($g['in_corso'] ?? null)->toBeNull()
        ->and($g['opera_finita'])->toMatchArray(['spese_esercizi_prima' => 4000000, 'data_fine' => '2027-06-30'])
        ->and($g['uscite'][$s['voce']->id])->toBe(6500000)
        ->and([$neri['a'], $neri['b']['totale'], $neri['conguaglio'], $neri['posizione'], $neri['saldo']])->toBe([3250000, 3000000, 250000, 0, 250000])
        ->and([$verdi['a'], $verdi['b']['totale'], $verdi['conguaglio'], $verdi['posizione'], $verdi['saldo']])->toBe([3250000, 3000000, 250000, 1500000, 1750000])
        ->and($g['totali']['conguaglio'])->toBe(500000)
        // La rata della Facciata non pagata è sulla gestione dell'opera, che è dell'esercizio 2027: non è anche fra «da altre gestioni».
        ->and($letto['da_altre_gestioni']['persone'][$s['persone']['verdi']->id] ?? [])->toBe([]);
});

it('senza la data di fine l\'opera resta in corso anche nel 2027, e la scheda dice di scriverla quando l\'opera è finita, con la porta alla gestione', function () {
    $s = opxCaso($this, null);
    $this->travelTo('2028-01-10 10:00:00');
    $servizio = app(ConguaglioDelRendiconto::class);
    $letto = $servizio->leggi($s['e27']->fresh());
    $righe = $servizio->righe($s['c'], $s['e27']->fresh(), $letto);
    $facciata = collect($righe['gestioni'])->firstWhere('id', $s['facciata']->id);

    expect($letto['gestioni'][$s['facciata']->id]['in_corso'])->toMatchArray(['spesi' => 6500000, 'chiesti' => 6000000, 'speso_esercizio' => 2500000, 'data_fine' => null])
        ->and($facciata['in_corso']['frase'])->toBe('Opera in corso: spesi € 65.000,00, chiesti € 60.000,00. Il conguaglio si propone quando l\'opera è finita: scrivi la data di fine della gestione.')
        ->and($facciata['in_corso']['gestione_id'])->toBe($s['facciata']->id)
        ->and(opxRiga($letto, $s, 'neri'))->toBeNull()
        ->and(opxRiga($letto, $s, 'verdi'))->toMatchArray(['conguaglio' => 0, 'posizione' => 1500000, 'saldo' => 1500000, 'in_corso' => true]);
});

it('la scheda del 2026 dice l\'opera in corso con lo speso e il chiesto, e la data di fine scritta', function () {
    $s = opxCaso($this);
    $this->travelTo('2027-01-10 10:00:00');
    $p = $this->actingAs($this->user)->get(route('admin.gestionale.esercizi.rendiconto.conguaglio', [$s['c'], $s['e']]))->assertOk()->viewData('page')['props']['conguaglio'];
    $facciata = collect($p['gestioni'])->firstWhere('id', $s['facciata']->id);

    expect($facciata['in_corso']['frase'])->toBe('Opera in corso fino al 30/06/2027: spesi € 40.000,00, chiesti € 60.000,00. Il conguaglio si propone quando l\'opera è finita.');
});

/** Un piano della «Facciata» nell'esercizio dato, con la sua delibera, generato dal programma (le rate non emesse). */
function opxPiano(array $s, Esercizio $e, string $nome, int $importo, string $delibera, string $scadenza): void
{
    $piano = PianoRate::create(['gestione_id' => $s['facciata']->id, 'condominio_id' => $s['c']->id, 'esercizio_id' => $e->id, 'nome' => $nome, 'stato' => 'approvato', 'tipo' => 'ordinario',
        'numero_rate' => 1, 'giorno_scadenza' => 10, 'data_prima_scadenza' => $scadenza, 'metodo_distribuzione' => 'tutte_rate', 'applica_saldi' => false,
        'data_delibera_assemblea' => $delibera]);
    DB::table('piano_rate_capitoli')->insert(['piano_rate_id' => $piano->id, 'conto_id' => $s['voce']->id, 'importo' => $importo, 'created_at' => now(), 'updated_at' => now()]);
    app(\App\Actions\PianoRate\GeneratePianoRateAction::class)->execute($piano, accettaDestinatari: true, notaDestinatari: 'Prova', esercizio: $e);
}

/** Il passaggio di B da Verdi a Gialli (il 01/07/2026, o il giorno dato nell'esercizio dato), con la coppia di ± € 1.000,00 sulla «Facciata» non ancora in un piano. */
function opxPassaggioConCoppia(array $s, ?Esercizio $esercizio = null, string $giorno = '2026-07-01'): \App\Models\Anagrafica
{
    $esercizio ??= $s['e'];
    $vigilia = \Carbon\CarbonImmutable::parse($giorno)->subDay()->format('Y-m-d');
    $b = $s['immobili']['B'];
    $verdi = $s['persone']['verdi'];
    $gialli = \App\Models\Anagrafica::forceCreate(['nome' => 'Gialli', 'email' => 'gialli-opx'.$s['c']->id.'@test.it', 'indirizzo' => 'Via Roma 1', 'codice_fiscale' => 'GLLOPX'.str_pad((string) $s['c']->id, 10, '0', STR_PAD_LEFT)]);
    $rV = DB::table('anagrafica_immobile')->where('immobile_id', $b->id)->where('anagrafica_id', $verdi->id)->value('id');
    DB::table('anagrafica_immobile')->where('id', $rV)->update(['data_fine' => $vigilia]);
    $rG = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $gialli->id, 'immobile_id' => $b->id, 'tipologia' => 'proprietario', 'quota' => 100, 'attivo' => true,
        'data_inizio' => $giorno, 'data_fine' => null, 'created_at' => now(), 'updated_at' => now()]);
    $sub = DB::table('subentri')->insertGetId(['condominio_id' => $s['c']->id, 'immobile_id' => $b->id, 'anagrafica_uscente_id' => $verdi->id, 'anagrafica_entrante_id' => $gialli->id,
        'riga_uscente_id' => $rV, 'riga_entrante_id' => $rG, 'tipologia' => 'proprietario', 'tipo_passaggio' => 'vendita', 'decorrenza' => $giorno, 'created_at' => now(), 'updated_at' => now()]);
    foreach ([[$verdi->id, -100000], [$gialli->id, 100000]] as [$aid, $imp]) {
        DB::table('saldi')->insert(['condominio_id' => $s['c']->id, 'esercizio_id' => $esercizio->id, 'gestione_id' => $s['facciata']->id, 'anagrafica_id' => $aid, 'immobile_id' => $b->id,
            'subentro_id' => $sub, 'saldo_iniziale' => $imp, 'is_applicato' => false, 'origine' => 'manuale', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $gialli;
}

it('l\'opera con un secondo piano nel 2027, deliberato da un\'altra assemblea: nell\'anno in cui finisce si calcola, ogni spesa senza competenza con la delibera che ha approvato l\'opera', function () {
    $s = opxCaso($this);
    opxPiano($s, $s['e27'], 'Facciata 2027', 500000, '2027-02-01', '2027-03-10');
    $this->travelTo('2028-01-10 10:00:00');
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $neri = opxRiga($letto, $s, 'neri');
    $verdi = opxRiga($letto, $s, 'verdi');

    // Le spese del 2026 e del 2027 alla delibera del primo piano, 01/02/2026: Neri e Verdi titolari, 65.000,00 × 0,5 = 32.500,00.
    // Le quote: 30.000,00 del piano del 2026 + 2.500,00 del piano del 2027 = 32.500,00: conguaglio 0. Verdi ha la seconda rata del 2026 non pagata: saldo 15.000,00.
    expect($letto['gestioni'][$s['facciata']->id]['non_calcolabile'])->toBeNull()
        ->and([$neri['a'], $neri['b']['totale'], $neri['conguaglio']])->toBe([3250000, 3250000, 0])
        ->and([$verdi['a'], $verdi['b']['totale'], $verdi['conguaglio'], $verdi['saldo']])->toBe([3250000, 3250000, 0, 1500000]);
});

it('una spesa dell\'opera senza competenza scritta nell\'anno dopo è di chi era titolare alla delibera che ha approvato l\'opera, non a quella del piano a saldo', function () {
    $s = opxCaso($this);
    // B passa da Verdi a Gialli il 01/07/2026, con il passaggio registrato e la coppia di ± € 1.000,00 non ancora in un piano; poi il piano del 2027 di
    // € 5.000,00, deliberato il 01/02/2027, quando B è di Gialli.
    $gialli = opxPassaggioConCoppia($s);
    opxPiano($s, $s['e27'], 'Facciata 2027', 500000, '2027-02-01', '2027-03-10');
    $this->travelTo('2028-01-10 10:00:00');
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $b = $s['immobili']['B']->id;
    $di = fn (int $aid) => $letto['persone'][$s['facciata']->id][$aid][$b] ?? null;
    $verdi = $di($s['persone']['verdi']->id);
    $g = $di($gialli->id);

    // A: le spese del 2026 (40.000,00) e del 2027 (25.000,00) alla delibera del 01/02/2026, quando B era di Verdi: Verdi 20.000,00 + 12.500,00 = 32.500,00,
    // Gialli 0. B: Verdi 30.000,00 del piano del 2026 − 1.000,00 della coppia = 29.000,00; Gialli 2.500,00 del piano del 2027 + 1.000,00 della coppia = 3.500,00.
    // Conguaglio: Verdi 32.500,00 − 29.000,00 = +3.500,00; Gialli 0 − 3.500,00 = −3.500,00. Con la delibera del piano a saldo per le spese del 2027 (la
    // regola di prima) 12.500,00 passavano da Verdi a Gialli: Verdi −9.000,00, Gialli +9.000,00.
    expect([$verdi['a'], $verdi['b']['totale'], $verdi['conguaglio']])->toBe([3250000, 2900000, 350000])
        ->and([$g['a'] ?? 0, $g['b']['totale'], $g['conguaglio']])->toBe([0, 350000, -350000]);
});

it('una gestione che non si calcola non propone niente: le sue righe sono non disponibili con il perché, e il totale non le conta', function () {
    // Un'opera di un anno con due piani deliberati da due assemblee e una spesa senza competenza scritta: il programma non sceglie la delibera.
    $s = opxCaso($this, '2026-12-31');
    opxPiano($s, $s['e'], 'Facciata integrazione', 500000, '2026-06-01', '2026-09-10');
    $this->travelTo('2027-01-10 10:00:00');
    $letto = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $g = $letto['gestioni'][$s['facciata']->id];
    $neri = opxRiga($letto, $s, 'neri');

    // Prima: A 0 e B 32.500,00, cioè Neri −32.500,00 e il condominio −65.000,00, come un rimborso di tutte le quote.
    expect($g['non_calcolabile'])->toContain('date diverse')
        ->and($neri['conguaglio'])->toBeNull()->and($neri['saldo'])->toBeNull()->and($neri['b']['totale'])->toBeNull()
        ->and($neri['non_disponibile'])->toBe('La gestione non si calcola: '.$g['non_calcolabile'])
        ->and($g['totali']['conguaglio'])->toBe(0)->and($g['totali']['non_disponibili'])->toBe(2);
});

it('una gestione che non si calcola: la frase dei non disponibili dice la gestione e le sue spese non divise, e il foglio non dice «nessuna spesa»', function () {
    $s = opxCaso($this, '2026-12-31');
    opxPiano($s, $s['e'], 'Facciata integrazione', 500000, '2026-06-01', '2026-09-10');
    $this->travelTo('2027-01-10 10:00:00');
    $servizio = app(\App\Services\Gestionale\ConguaglioDelRendiconto::class);
    $letto = $servizio->leggi($s['e']->fresh(), [], true);
    $righe = $servizio->righe($s['c'], $s['e']->fresh(), $letto);
    $f = app(\App\Services\Gestionale\FogliDelConguaglio::class)->datiDelFoglio($s['c'], $s['e']->fresh(), $letto, $s['persone']['neri']->id);
    $html = view('pdf.gestionale.foglio_conguaglio', ['condominio' => $s['c'], 'esercizio' => $s['e'], 'foglio' => $f])->render();
    $testo = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#</?(td|th|tr|p|div|br|h1|h2|table)\b[^>]*>#i', ' ', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

    // Le spese ci sono, € 40.000,00, e non sono divise: la frase non dice «spese dell'esercizio € 0,00».
    expect($righe['non_disponibili'])->toBe('Le righe non disponibili (2) non sono nei totali. «Facciata» non si calcola: le sue spese dell\'esercizio, € 40.000,00, non sono divise.')
        ->and($testo)->toContain('La spesa di questa gestione non è ancora divisa: il conguaglio non è ancora calcolato.')
        ->and($testo)->toContain('Spese dell\'esercizio non ancora divisa');
    // La sezione della «Facciata» non dice «nessuna spesa» (la dice, se è così, la sezione dell'ordinaria).
    $facciata = substr($testo, (int) strpos($testo, '§ 2 Facciata'), 600);
    expect($facciata)->toStartWith('§ 2 Facciata')->not->toContain('Nessuna spesa dell\'esercizio su questa unità.');
});

it('l\'opera finita nel 2026 con un piano a saldo del 2027: il conguaglio del 2026 conta anche quel piano; nel 2027 le spese scritte dopo la fine si conguagliano da sole, senza quote', function () {
    $s = opxCaso($this, '2026-11-30');
    // Le quote del 2026 e il piano a saldo del 2027 di € 5.000,00; le spese: 40.000,00 nel 2026 e 25.000,00 scritte nel 2027, dopo la fine.
    opxPiano($s, $s['e27'], 'Facciata 2027, a saldo', 500000, '2027-02-01', '2027-03-10');
    $this->travelTo('2028-01-10 10:00:00');
    $l26 = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $neri = opxRiga($l26, $s, 'neri');
    $neri27 = opxRiga($l27, $s, 'neri');
    $g27 = $l27['gestioni'][$s['facciata']->id];
    $servizio = app(\App\Services\Gestionale\ConguaglioDelRendiconto::class);
    $facciata = collect($servizio->righe($s['c'], $s['e27']->fresh(), $l27)['gestioni'])->firstWhere('id', $s['facciata']->id);
    $stampa = collect($servizio->righe($s['c'], $s['e27']->fresh(), $l27, null, true)['gestioni'])->firstWhere('id', $s['facciata']->id);

    // 2026: A 40.000,00 × 0,5 = 20.000,00; B 30.000,00 + 2.500,00 = 32.500,00 (di cui 2.500,00 non emesse): −12.500,00.
    // 2027: le spese scritte dopo la fine, € 25.000,00, alla delibera del primo piano (01/02/2026), 12.500,00 a testa; nessuna quota (sono tutte nel
    // conguaglio del 2026) e nessuna coppia: +12.500,00 a testa, il condominio +25.000,00. Nei due anni Neri −12.500,00 + 12.500,00 = 0 = 32.500,00 − 32.500,00.
    // Prima i € 25.000,00 del 2027 non li chiedeva nessuno: il 2027 non proponeva, e il 2026 rimborsava le quote che li finanziano.
    expect([$neri['a'], $neri['b']['totale'], $neri['conguaglio'], $neri['b']['di_cui_non_emesse']])->toBe([2000000, 3250000, -1250000, 250000])
        ->and([$neri27['a'], $neri27['b']['totale'], $neri27['conguaglio']])->toBe([1250000, 0, 1250000])
        ->and(opxRiga($l27, $s, 'verdi')['conguaglio'])->toBe(1250000)
        ->and($g27['totali']['conguaglio'])->toBe(2500000)->and($g27['uscite'])->toBe([$s['voce']->id => 2500000])
        ->and($g27['finita_prima'])->toMatchArray(['data_fine' => '2026-11-30', 'esercizio' => 'Esercizio 2026', 'speso_esercizio' => 2500000])
        ->and($neri['conguaglio'] + $neri27['conguaglio'])->toBe(0)
        // La scheda lo dice con la cifra, e avvisa l'amministratore: se l'opera non era finita, la data di fine va spostata (decide lui).
        ->and($facciata['in_corso']['frase'])->toBe('Opera finita il 30/11/2026: il conguaglio dell\'opera è nel rendiconto di «Esercizio 2026»; qui le spese scritte dopo la fine, € 25.000,00. '
            .'Se l\'opera non era finita, sposta la data di fine della gestione.')
        ->and($facciata['in_corso']['sposta_data_fine'])->toBeTrue()
        // La stampa per i condòmini: la frase senza l'avviso, che è per l'amministratore.
        ->and($stampa['in_corso']['frase'])->toBe('Opera finita il 30/11/2026: il conguaglio dell\'opera è nel rendiconto di «Esercizio 2026»; qui le spese scritte dopo la fine, € 25.000,00.');
    // La 8.3: le spese scritte dopo la fine sono divise, non fra le opere che non si dividono.
    $t = \App\Services\Gestionale\ControlliDelConguaglio::termini($l27);
    expect([$t['diviso'], $t['in_corso']])->toBe([2500000, 0]);
});

it('l\'opera finita nel 2026, il piano a saldo del 2027 e B che passa da Verdi a Gialli il 01/01/2027: le spese scritte dopo la fine vanno a chi era titolare alla delibera che ha approvato l\'opera', function () {
    $s = opxCaso($this, '2026-11-30');
    opxPassaggioConCoppia($s, $s['e27'], '2027-01-01');
    opxPiano($s, $s['e27'], 'Facciata 2027, a saldo', 500000, '2027-02-01', '2027-03-10');
    $this->travelTo('2028-01-10 10:00:00');
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $b = $s['immobili']['B']->id;
    $di = fn (int $aid) => $l27['persone'][$s['facciata']->id][$aid][$b] ?? null;

    // Le spese del 2027, € 25.000,00, alla delibera del 01/02/2026: B era di Verdi, 12.500,00 a lui e 0 a Gialli; nessuna quota né coppia nella coda. Con la
    // delibera del piano a saldo (01/02/2027, B di Gialli) i 12.500,00 andrebbero a Gialli.
    expect([$di($s['persone']['verdi']->id)['a'], $di($s['persone']['verdi']->id)['conguaglio']])->toBe([1250000, 1250000])
        ->and((int) ($di(\App\Models\Anagrafica::where('nome', 'Gialli')->value('id'))['a'] ?? 0))->toBe(0)
        ->and(opxRiga($l27, $s, 'neri')['a'])->toBe(1250000);
});

it('il primo piano dell\'opera è quello con la delibera più vecchia, non quello scritto per primo', function () {
    $s = opxCaso($this);
    // Il piano scritto per primo ha la delibera del 01/06/2026; un secondo piano del 2026 ha quella del 01/02/2026. Nessuna spesa nel 2026 (l'esercizio con le
    // due delibere): le spese del 2027 vanno alla delibera più vecchia, 01/02/2026, quando B era di Verdi (passa a Gialli il 01/03/2026).
    DB::table('piani_rate')->where('id', $s['piano_facciata']->id)->update(['data_delibera_assemblea' => '2026-06-01']);
    opxPiano($s, $s['e'], 'Facciata integrazione', 500000, '2026-02-01', '2026-09-10');
    DB::table('scritture_contabili')->where('esercizio_id', $s['e']->id)->where('numero_protocollo', 'OPX-2026-09-15')->update(['deleted_at' => now()]);
    $gialli = opxPassaggioConCoppia($s, $s['e'], '2026-03-01');
    $this->travelTo('2028-01-10 10:00:00');
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $b = $s['immobili']['B']->id;

    // € 25.000,00 × 0,5: Verdi 12.500,00, Gialli 0. Con il piano scritto per primo (01/06/2026, B di Gialli) sarebbe il contrario.
    expect($l27['gestioni'][$s['facciata']->id]['non_calcolabile'])->toBeNull()
        ->and((int) ($l27['persone'][$s['facciata']->id][$s['persone']['verdi']->id][$b]['a'] ?? 0))->toBe(1250000)
        ->and((int) ($l27['persone'][$s['facciata']->id][$gialli->id][$b]['a'] ?? 0))->toBe(0);
});

it('l\'opera finita nel 2026 senza un piano nel 2027: le spese scritte dopo la fine si dividono alla delibera del primo piano dell\'opera, e si conguagliano nel 2027', function () {
    $s = opxCaso($this, '2026-11-30');
    $this->travelTo('2028-01-10 10:00:00');
    $l26 = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());

    // 2026: A 20.000,00, B 30.000,00: −10.000,00. 2027: € 25.000,00 alla delibera del 01/02/2026, 12.500,00 a testa, B 0: +12.500,00. Nei due anni Neri
    // −10.000,00 + 12.500,00 = +2.500,00 = 32.500,00 − 30.000,00.
    expect(opxRiga($l26, $s, 'neri')['conguaglio'])->toBe(-1000000)
        ->and($l27['gestioni'][$s['facciata']->id]['non_calcolabile'])->toBeNull()
        ->and([opxRiga($l27, $s, 'neri')['conguaglio'], opxRiga($l27, $s, 'verdi')['conguaglio']])->toBe([1250000, 1250000]);
});

it('l\'opera finita nel 2026, un passaggio nel 2027 con la sua coppia: le spese scritte dopo la fine si conguagliano senza la coppia, che resta fuori dalle rate', function () {
    $s = opxCaso($this, '2026-11-30');
    $gialli = opxPassaggioConCoppia($s, $s['e27'], '2027-03-01');
    $this->travelTo('2028-01-10 10:00:00');
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $b = $s['immobili']['B']->id;
    $verdi = $l27['persone'][$s['facciata']->id][$s['persone']['verdi']->id][$b];
    $g = $l27['persone'][$s['facciata']->id][$gialli->id][$b];

    // Le spese del 2027, € 25.000,00, alla delibera del primo piano (01/02/2026): di B è titolare Verdi, 12.500,00. Nessuna coppia nelle quote (sono del
    // conguaglio dell'opera); la coppia, non ancora in un piano, è nei saldi fuori dalle rate: Verdi −1.000,00, Gialli +1.000,00.
    expect([$verdi['a'], $verdi['b']['coppie'], $verdi['b']['totale'], $verdi['conguaglio'], $verdi['fuori_dalle_rate']])->toBe([1250000, 0, 0, 1250000, -100000])
        ->and([$g['a'], $g['b']['coppie'], $g['conguaglio'], $g['fuori_dalle_rate']])->toBe([0, 0, 0, 100000]);
});

it('l\'opera finita nel 2026 senza spese scritte dopo la fine: il 2027 non propone niente e non avvisa', function () {
    $s = opxCaso($this, '2026-11-30');
    DB::table('scritture_contabili')->where('esercizio_id', $s['e27']->id)->update(['deleted_at' => now()]);
    $this->travelTo('2028-01-10 10:00:00');
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $servizio = app(\App\Services\Gestionale\ConguaglioDelRendiconto::class);
    $facciata = collect($servizio->righe($s['c'], $s['e27']->fresh(), $l27)['gestioni'])->firstWhere('id', $s['facciata']->id);

    expect($l27['gestioni'][$s['facciata']->id]['totali']['conguaglio'])->toBe(0)
        ->and($facciata['in_corso']['frase'])->toBe('Opera finita il 30/11/2026: il conguaglio è nel rendiconto di «Esercizio 2026».')
        ->and($facciata['in_corso']['sposta_data_fine'])->toBeFalse();
});

it('la coppia di un passaggio del 2026 non ancora in un piano: nel 2026 l\'opera in corso non la mette nelle quote; nel 2027 è nelle quote e nel saldo', function () {
    $s = opxCaso($this);
    $gialli = opxPassaggioConCoppia($s);
    $this->travelTo('2028-01-10 10:00:00');
    $b = $s['immobili']['B']->id;
    $l26 = (new ConsuntivoPerPersona())->perEsercizio($s['e']->fresh());
    $l27 = (new ConsuntivoPerPersona())->perEsercizio($s['e27']->fresh());
    $di = fn (array $l, int $aid) => $l['persone'][$s['facciata']->id][$aid][$b];

    // 2026, in corso: nessuna quota né coppia; Verdi saldo = la rata non pagata 15.000,00 − la coppia fuori dalle rate 1.000,00 = 14.000,00; Gialli +1.000,00.
    expect([$di($l26, $s['persone']['verdi']->id)['b']['coppie'], $di($l26, $s['persone']['verdi']->id)['conguaglio'], $di($l26, $s['persone']['verdi']->id)['saldo']])->toBe([0, 0, 1400000])
        ->and([$di($l26, $gialli->id)['conguaglio'], $di($l26, $gialli->id)['saldo']])->toBe([0, 100000])
        // 2027, finita: Verdi B 30.000,00 − 1.000,00 = 29.000,00, A 32.500,00, +3.500,00; saldo 15.000,00 − 1.000,00 + 3.500,00 = 17.500,00. Gialli B +1.000,00,
        // conguaglio −1.000,00, fuori dalle rate +1.000,00: saldo 0.
        ->and([$di($l27, $s['persone']['verdi']->id)['b']['totale'], $di($l27, $s['persone']['verdi']->id)['conguaglio'], $di($l27, $s['persone']['verdi']->id)['saldo']])->toBe([2900000, 350000, 1750000])
        ->and([$di($l27, $gialli->id)['conguaglio'], $di($l27, $gialli->id)['fuori_dalle_rate'], $di($l27, $gialli->id)['saldo']])->toBe([-100000, 100000, 0]);
});

it('l\'opera che finisce in un esercizio a cui la gestione non è legata: la frase dice di legarla, o il conguaglio non si propone mai', function () {
    $s = opxCaso($this, '2027-06-30', false);
    $this->travelTo('2027-01-10 10:00:00');
    $servizio = app(\App\Services\Gestionale\ConguaglioDelRendiconto::class);
    $facciata = collect($servizio->righe($s['c'], $s['e']->fresh(), $servizio->leggi($s['e']->fresh()))['gestioni'])->firstWhere('id', $s['facciata']->id);

    expect($facciata['in_corso']['frase'])->toBe('Opera in corso fino al 30/06/2027: spesi € 40.000,00, chiesti € 60.000,00. Il conguaglio si propone quando l\'opera è finita. '
        .'La gestione non è legata all\'esercizio in cui finisce: salvala dalla sua pagina quando quell\'esercizio è aperto, e il conguaglio si propone nel suo rendiconto.');
});
