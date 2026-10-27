<?php

/**
 * # L'agenda del portale si apre con qualunque numero di palazzi
 *
 * L'agenda del condòmino (`user.eventi.index`) arricchisce le scadenze delle rate con gli arretrati e i crediti
 * delle rate precedenti (`CalculatesFinancialWaterfall`), filtrando le quote per i palazzi dell'anagrafica e per i
 * piani rate degli esercizi aperti. Gli id dei palazzi arrivano come Collection: il filtro li deve ricevere come
 * lista di id, qualunque forma abbiano (Collection, array, un id solo). Con zero palazzi o con due l'agenda
 * rispondeva 500; con uno passava.
 *
 * Le cifre attese sono scritte a mano dalla regola del calcolo, palazzo per palazzo e rata per rata in ordine di
 * scadenza: gli arretrati di una rata sono i residui non pagati delle rate prima di lei, al netto dei crediti
 * (una rata negativa non ancora usata); la lista nomina le rate rimaste insolute. Contano solo i piani degli
 * esercizi aperti.
 *
 * Senza palazzi non conta nessuna quota. La dashboard del portale chiama lo stesso calcolo senza i palazzi (`null`, nessun
 * filtro): qui un test solo, con le cifre di un suo palazzo.
 *
 * Cosa NON copre: il resto della dashboard del portale (`ScadenzeNellaDashboardDelPortaleTest`,
 * `DatiDeiViciniNelPortaleTest`); le scadenze ricorrenti.
 */

use App\Enums\EventoTipo;
use App\Enums\Role;
use App\Enums\VisibilityStatus;
use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Esercizio;
use App\Models\Evento;
use App\Models\Gestionale\PianoRate;
use App\Models\Gestionale\Rata;
use App\Models\Gestionale\RataQuote;
use App\Models\Gestione;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->withoutVite();

    $this->staff = User::factory()->create(['email_verified_at' => now()]);
    $this->aurora = Condominio::factory()->create(['nome' => 'Condominio Aurora']);
    $this->borea = Condominio::factory()->create(['nome' => 'Condominio Borea']);
});

function agendaPalazziCondomino(array $condomini): array
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(Role::UTENTE->value);
    $anagrafica = Anagrafica::factory()->create(['user_id' => $user->id, 'nome' => 'Mario Rossi']);
    $anagrafica->condomini()->attach(collect($condomini)->pluck('id')->all());

    return [$user->fresh(), $anagrafica];
}

/** Un piano rate del palazzo, in una gestione legata a un esercizio con lo stato dato. */
function agendaPalazziPiano(Condominio $condominio, string $statoEsercizio): PianoRate
{
    $esercizio = Esercizio::factory()->create(['condominio_id' => $condominio->id, 'stato' => $statoEsercizio]);
    $gestione = Gestione::factory()->create(['condominio_id' => $condominio->id]);
    DB::table('esercizio_gestione')->insert([
        'esercizio_id' => $esercizio->id, 'gestione_id' => $gestione->id, 'attiva' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return PianoRate::factory()->create(['gestione_id' => $gestione->id, 'condominio_id' => $condominio->id]);
}

/** Una rata del piano con la quota della persona: importo e pagato in centesimi. */
function agendaPalazziRata(PianoRate $piano, Anagrafica $anagrafica, int $numero, int $giorni, int $importo, int $pagato): Rata
{
    $rata = Rata::factory()->create([
        'piano_rate_id' => $piano->id, 'numero_rata' => $numero,
        'data_scadenza' => now()->addDays($giorni)->toDateString(), 'importo_totale' => $importo,
    ]);
    RataQuote::factory()->create([
        'rata_id' => $rata->id, 'anagrafica_id' => $anagrafica->id, 'importo' => $importo,
        'importo_pagato' => $pagato, 'stato' => $importo < 0 ? 'credito' : ($pagato >= $importo ? 'pagata' : 'da_pagare'),
    ]);

    return $rata;
}

/** Il promemoria della rata nell'agenda della persona, con il contesto che il calcolo legge. */
function agendaPalazziPromemoria(Rata $rata, PianoRate $piano, Anagrafica $anagrafica, Condominio $condominio, User $staff): Evento
{
    $evento = Evento::create([
        'title' => "Scadenza rata {$rata->numero_rata} - {$condominio->nome}",
        'start_time' => $rata->data_scadenza->copy()->setTime(0, 0),
        'end_time' => $rata->data_scadenza->copy()->setTime(23, 59),
        'created_by' => $staff->id,
        'visibility' => VisibilityStatus::PRIVATE->value,
        'is_approved' => true,
        'tipo' => EventoTipo::SCADENZA_RATA_CONDOMINO,
        'meta' => [
            'type' => EventoTipo::SCADENZA_RATA_CONDOMINO->value,
            'status' => 'pending',
            'importo_originale' => $rata->importo_totale,
            'importo_pagato' => 0,
            'importo_restante' => $rata->importo_totale,
            'condominio_nome' => $condominio->nome,
            'context' => ['piano_rate_id' => $piano->id, 'rata_id' => $rata->id],
        ],
    ]);
    $evento->anagrafiche()->attach($anagrafica->id);
    $evento->condomini()->attach($condominio->id);

    return $evento;
}

test('agenda del portale — si apre a chi ha un\'anagrafica senza palazzi', function () {
    [$user] = agendaPalazziCondomino([]);

    $this->actingAs($user)->get(route('user.eventi.index'))->assertOk();
});

test('agenda del portale — si apre a chi ha un\'anagrafica con un palazzo', function () {
    [$user] = agendaPalazziCondomino([$this->aurora]);

    $this->actingAs($user)->get(route('user.eventi.index'))->assertOk();
});

test('agenda del portale — con due palazzi si apre, e arretrati e crediti delle rate restano di ciascun palazzo', function () {
    [$user, $mario] = agendaPalazziCondomino([$this->aurora, $this->borea]);

    // Aurora, esercizio aperto: la rata 1 pagata per € 40,00 su € 100,00; la rata 2 è quella in agenda.
    $pianoAurora = agendaPalazziPiano($this->aurora, 'aperto');
    agendaPalazziRata($pianoAurora, $mario, 1, -60, 10000, 4000);
    $seconda = agendaPalazziRata($pianoAurora, $mario, 2, 10, 10000, 0);
    // Aurora, esercizio chiuso: una rata non pagata di € 50,00 che l'agenda non conta.
    agendaPalazziRata(agendaPalazziPiano($this->aurora, 'chiuso'), $mario, 1, -200, 5000, 0);
    // Borea, esercizio aperto: un credito di € 30,00 (rata 0 negativa), la rata 1 pagata; la rata 2 in agenda.
    $pianoBorea = agendaPalazziPiano($this->borea, 'aperto');
    agendaPalazziRata($pianoBorea, $mario, 0, -90, -3000, 0);
    agendaPalazziRata($pianoBorea, $mario, 1, -30, 8000, 8000);
    $secondaBorea = agendaPalazziRata($pianoBorea, $mario, 2, 20, 8000, 0);

    $inAurora = agendaPalazziPromemoria($seconda, $pianoAurora, $mario, $this->aurora, $this->staff);
    $inBorea = agendaPalazziPromemoria($secondaBorea, $pianoBorea, $mario, $this->borea, $this->staff);

    $eventi = collect($this->actingAs($user)->get(route('user.eventi.index'))->assertOk()
        ->viewData('page')['props']['eventi']['data'])->keyBy('id');

    $storico = fn (Evento $e) => array_intersect_key($eventi[$e->id]['meta'], array_flip(['storico_arretrati', 'storico_crediti', 'storico_rate_rif']));

    // Aurora: prima della rata 2 restano € 60,00 della rata 1; la rata dell'esercizio chiuso non conta.
    expect($storico($inAurora))->toBe(['storico_arretrati' => 6000, 'storico_crediti' => 0, 'storico_rate_rif' => '#1'])
        // Borea: nessun arretrato, il credito di € 30,00 della rata 0; i € 60,00 di Aurora non passano di qui.
        ->and($storico($inBorea))->toBe(['storico_arretrati' => 0, 'storico_crediti' => 3000, 'storico_rate_rif' => '']);
});

test('agenda del portale — senza esercizi aperti contano le quote dei suoi palazzi, non quelle di un palazzo da cui è uscita', function () {
    [$user, $mario] = agendaPalazziCondomino([$this->aurora, $this->borea]);
    $cielo = Condominio::factory()->create(['nome' => 'Condominio Cielo']);

    // Aurora, solo un esercizio chiuso: senza piani aperti il calcolo non filtra per piano, solo per palazzo.
    $pianoAurora = agendaPalazziPiano($this->aurora, 'chiuso');
    agendaPalazziRata($pianoAurora, $mario, 1, -60, 10000, 4000);
    $inAurora = agendaPalazziPromemoria(agendaPalazziRata($pianoAurora, $mario, 2, 10, 10000, 0), $pianoAurora, $mario, $this->aurora, $this->staff);
    // Cielo, un palazzo a cui l'anagrafica non è più collegata: il promemoria è ancora suo, le quote non contano.
    $pianoCielo = agendaPalazziPiano($cielo, 'chiuso');
    agendaPalazziRata($pianoCielo, $mario, 1, -60, 7000, 0);
    $inCielo = agendaPalazziPromemoria(agendaPalazziRata($pianoCielo, $mario, 2, 15, 7000, 0), $pianoCielo, $mario, $cielo, $this->staff);

    $eventi = collect($this->actingAs($user)->get(route('user.eventi.index'))->assertOk()
        ->viewData('page')['props']['eventi']['data'])->keyBy('id');

    expect(array_intersect_key($eventi[$inAurora->id]['meta'], array_flip(['storico_arretrati', 'storico_crediti', 'storico_rate_rif'])))
        ->toBe(['storico_arretrati' => 6000, 'storico_crediti' => 0, 'storico_rate_rif' => '#1'])
        ->and($eventi[$inCielo->id]['meta'])->not->toHaveKey('storico_arretrati');
});

test('agenda del portale — senza palazzi non conta nessuna quota: il promemoria di un palazzo da cui è uscita resta senza arretrati', function () {
    // Nessun palazzo vuol dire nessuna quota da contare, non «tutte le quote»: è la stessa regola del test qui sopra,
    // con zero palazzi invece di due.
    [$user, $mario] = agendaPalazziCondomino([]);
    $cielo = Condominio::factory()->create(['nome' => 'Condominio Cielo']);
    $pianoCielo = agendaPalazziPiano($cielo, 'chiuso');
    agendaPalazziRata($pianoCielo, $mario, 1, -60, 7000, 0);
    $inCielo = agendaPalazziPromemoria(agendaPalazziRata($pianoCielo, $mario, 2, 15, 7000, 0), $pianoCielo, $mario, $cielo, $this->staff);

    $eventi = collect($this->actingAs($user)->get(route('user.eventi.index'))->assertOk()
        ->viewData('page')['props']['eventi']['data'])->keyBy('id');

    expect($eventi)->toHaveKey($inCielo->id)
        ->and($eventi[$inCielo->id]['meta'])->not->toHaveKey('storico_arretrati')
        ->and($eventi[$inCielo->id]['meta'])->not->toHaveKey('storico_crediti')
        ->and($eventi[$inCielo->id]['meta'])->not->toHaveKey('storico_rate_rif');
});

test('agenda del portale — con un palazzo le cifre della regola, e le quote di un palazzo da cui è uscita non contano', function () {
    [$user, $mario] = agendaPalazziCondomino([$this->aurora]);
    $cielo = Condominio::factory()->create(['nome' => 'Condominio Cielo']);

    // Aurora: la rata 1 pagata per € 40,00 su € 100,00; la rata 2 è quella in agenda.
    $pianoAurora = agendaPalazziPiano($this->aurora, 'aperto');
    agendaPalazziRata($pianoAurora, $mario, 1, -60, 10000, 4000);
    $inAurora = agendaPalazziPromemoria(agendaPalazziRata($pianoAurora, $mario, 2, 10, 10000, 0), $pianoAurora, $mario, $this->aurora, $this->staff);
    // Cielo, un palazzo lasciato: una rata non pagata di € 70,00 prima di quella del promemoria.
    $pianoCielo = agendaPalazziPiano($cielo, 'aperto');
    agendaPalazziRata($pianoCielo, $mario, 1, -60, 7000, 0);
    $inCielo = agendaPalazziPromemoria(agendaPalazziRata($pianoCielo, $mario, 2, 15, 7000, 0), $pianoCielo, $mario, $cielo, $this->staff);

    $eventi = collect($this->actingAs($user)->get(route('user.eventi.index'))->assertOk()
        ->viewData('page')['props']['eventi']['data'])->keyBy('id');

    expect(array_intersect_key($eventi[$inAurora->id]['meta'], array_flip(['storico_arretrati', 'storico_crediti', 'storico_rate_rif'])))
        ->toBe(['storico_arretrati' => 6000, 'storico_crediti' => 0, 'storico_rate_rif' => '#1'])
        ->and($eventi[$inCielo->id]['meta'])->not->toHaveKey('storico_arretrati');
});

test('dashboard del portale — chiama il calcolo senza palazzi, cioè senza filtro, e la scadenza di un suo palazzo ha le cifre della regola', function () {
    [$user, $mario] = agendaPalazziCondomino([$this->aurora]);
    $pianoAurora = agendaPalazziPiano($this->aurora, 'aperto');
    agendaPalazziRata($pianoAurora, $mario, 1, -60, 10000, 4000);
    $inAurora = agendaPalazziPromemoria(agendaPalazziRata($pianoAurora, $mario, 2, 10, 10000, 0), $pianoAurora, $mario, $this->aurora, $this->staff);

    $eventi = $this->actingAs($user)->get(route('user.dashboard'))->assertOk()->viewData('page')['props']['eventi'];
    $eventi = collect($eventi['data'] ?? $eventi)->keyBy('id');

    expect(array_intersect_key($eventi[$inAurora->id]['meta'], array_flip(['storico_arretrati', 'storico_crediti', 'storico_rate_rif'])))
        ->toBe(['storico_arretrati' => 6000, 'storico_crediti' => 0, 'storico_rate_rif' => '#1']);
});
