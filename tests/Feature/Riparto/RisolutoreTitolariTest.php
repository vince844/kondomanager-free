<?php

/**
 * B1 — il risolutore unico dei titolari, a comportamento invariato.
 *
 * Fino alla 1.11.0-beta.29 la domanda «chi è titolare di questa unità» era risolta in una ventina
 * di punti diversi, ognuno con il proprio `->where('attivo', true)` (o senza). B1 li fa passare da un
 * posto solo, `RisolutoreTitolari`, con due forme sulla stessa regola: `attiviAlla()` per le
 * collection già caricate (il motore lavora in memoria) e `vincolaQuery()` per i punti che usano
 * `DB::table`. La regola, in B1, è **esattamente quella di prima**: `attivo === true` e nient'altro.
 *
 * Il progetto è `docs/subentro_e_competenza_temporale.md`, §4.3 e §5 (invarianti 4 e 6-bis).
 *
 * ⚠️ Cosa questi test NON coprono, di proposito: il tempo. `data_inizio` e `data_fine` sono scritte
 * e mostrate ma **non filtrano** — è il difetto che il progetto denuncia in §1.1 e che B2 (la beta
 * successiva) corregge con il pro rata. Il test «un periodo passato non cambia la risposta» fissa
 * questo comportamento perché sia B2, e non un refactor distratto, a cambiarlo: quando B2 uscirà
 * quel test si riscrive nel suo opposto. Il riparto al centesimo è il cancello della suite
 * (invariante 1): qui c'è solo la controprova, in `RigheRipartoScritturaTest`, che il motore,
 * ricevendo un periodo esplicito, risponde identico a quando non lo riceve — è il test che B2
 * rovescia insieme a «un periodo passato non cambia la risposta».
 */

use App\Models\Anagrafica;
use App\Models\Condominio;
use App\Models\Immobile;
use App\Models\TitolaritaImmobile;
use App\Services\Riparto\RisolutoreTitolari;
use App\Support\PeriodoCompetenza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Un'unità con quattro titolari che coprono i casi che il risolutore deve distinguere e quelli
 * che in B1 deve ancora ignorare: attivo, spento, chiuso nel passato, aperto nel futuro.
 *
 * @return array{immobile: Immobile, persone: array<string, Anagrafica>}
 */
function unitaConQuattroTitolari(): array
{
    static $seq = 0;
    $seq++;

    $condominio = Condominio::create([
        'nome' => "Condominio Risolutore {$seq}", 'uuid' => (string) Str::uuid(),
        'indirizzo' => 'Via Roma 1', 'citta' => 'Milano', 'cap' => '20100', 'provincia' => 'MI',
    ]);

    $immobile = Immobile::forceCreate([
        'condominio_id' => $condominio->id, 'nome' => "Int {$seq}",
        'descrizione' => 'Appartamento', 'interno' => (string) $seq,
    ]);

    $righe = [
        // chiave => [tipologia, attivo, data_inizio, data_fine]
        'attivo'          => ['proprietario', true, '2026-01-01', null],
        'spento'          => ['proprietario', false, '2026-01-01', null],
        'chiuso_passato'  => ['inquilino', true, '2025-01-01', '2026-04-30'],
        'apre_in_futuro'  => ['usufruttuario', true, '2027-01-01', null],
    ];

    $persone = [];
    foreach ($righe as $chiave => [$tipologia, $attivo, $dal, $al]) {
        $seq++;
        $persone[$chiave] = Anagrafica::forceCreate([
            'nome' => "Titolare {$chiave} {$seq}",
            'email' => "risolutore{$seq}@test.it",
            'indirizzo' => 'Via Verdi 1',
            'codice_fiscale' => 'RSLTST' . str_pad((string) $seq, 10, '0', STR_PAD_LEFT),
        ]);
        DB::table('anagrafica_immobile')->insert([
            'anagrafica_id' => $persone[$chiave]->id,
            'immobile_id' => $immobile->id,
            'tipologia' => $tipologia,
            'quota' => 100.0,
            'attivo' => $attivo,
            'data_inizio' => $dal,
            'data_fine' => $al,
        ]);
    }

    return ['immobile' => $immobile, 'persone' => $persone];
}

it('invariante 4: attiviAlla() sulla collection e vincolaQuery() sulla query rispondono identico sullo stesso dataset', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();
    $risolutore = app(RisolutoreTitolari::class);

    $inMemoria = $risolutore->attiviAlla($immobile->anagrafiche()->get())->pluck('id')->sort()->values()->all();
    $suQuery = $risolutore->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id))
        ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($inMemoria)->toBe($suQuery)->and($inMemoria)->toHaveCount(3);
});

it('in B1 la regola è attivo === true e nient\'altro: il titolare spento è fuori, quello chiuso nel passato e quello che apre nel futuro sono dentro', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();

    $ids = app(RisolutoreTitolari::class)->attiviAlla($immobile->anagrafiche()->get())->pluck('id')->all();

    expect($ids)->toContain($p['attivo']->id, $p['chiuso_passato']->id, $p['apre_in_futuro']->id)
        ->not->toContain($p['spento']->id);
});

it('un periodo di competenza passato al risolutore non cambia la risposta (B1 è atemporale per costruzione; B2 riscriverà questo test nel suo opposto)', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();
    $risolutore = app(RisolutoreTitolari::class);
    $anagrafiche = $immobile->anagrafiche()->get();

    $senzaPeriodo = $risolutore->attiviAlla($anagrafiche)->pluck('id')->sort()->values()->all();
    $conPeriodo = $risolutore->attiviAlla($anagrafiche, PeriodoCompetenza::puntuale('2026-06-15'))->pluck('id')->sort()->values()->all();
    $suQueryConPeriodo = $risolutore
        ->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), new PeriodoCompetenza('2026-01-01', '2026-12-31'))
        ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    expect($conPeriodo)->toBe($senzaPeriodo)->and($suQueryConPeriodo)->toBe($senzaPeriodo);
});

it('vincolaQuery() accetta un alias di tabella, perché i punti con join non chiamano la colonna «attivo» e basta', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();

    $conAlias = app(RisolutoreTitolari::class)
        ->vincolaQuery(DB::table('anagrafica_immobile as ai')->where('ai.immobile_id', $immobile->id), null, 'ai')
        ->count();

    expect($conAlias)->toBe(3);
});

it('vincolaQuery() con alias regge un join su una tabella che ha anch\'essa la colonna attivo (immobili)', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();

    // Senza il prefisso `ai.` MySQL risponde «Column attivo in where clause is ambiguous» e SQLite
    // «ambiguous column name»: il test muore se l'alias smette di essere applicato.
    $conJoin = app(RisolutoreTitolari::class)->vincolaQuery(
        DB::table('anagrafica_immobile as ai')
            ->join('immobili as i', 'i.id', '=', 'ai.immobile_id')
            ->where('i.id', $immobile->id),
        null, 'ai'
    )->count();

    expect($conJoin)->toBe(3);
});

it('invariante 6-bis: con due righe per la stessa persona sulla stessa unità, ordinePreferenza() rende ->value(\'tipologia\') deterministico — prima l\'attiva, poi la più recente', function () {
    // Due righe per la stessa coppia (anagrafica, immobile) oggi non passano la guardia 1 di
    // `ValidatesImmobileAnagraficaPivot`: le si inserisce direttamente, come farà B2 aprendo i periodi.
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $persona = $p['attivo'];
    // La riga SPENTA ha l'id più basso (è quella del fixture, riscritta) e la data più recente: senza
    // `orderBy` il motore restituisce per prima la riga con la chiave primaria più bassa (rowid su
    // SQLite, dove gira la suite; PK dell'indice secondario su InnoDB), cioè quella spenta, e
    // `->value('tipologia')` direbbe «inquilino». Il test deve fallire se l'ordine sparisce, non
    // passare per l'ordine naturale della tabella.
    DB::table('anagrafica_immobile')->where('anagrafica_id', $persona->id)->where('immobile_id', $immobile->id)
        ->update(['tipologia' => 'inquilino', 'attivo' => false, 'data_inizio' => '2026-05-01']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2024-01-01',
    ]);

    // Controprova che il caso morde: senza ordine, la prima riga è quella spenta.
    expect(DB::table('anagrafica_immobile')->where('anagrafica_id', $persona->id)->where('immobile_id', $immobile->id)->value('tipologia'))->toBe('inquilino');

    $letture = collect(range(1, 5))->map(fn () => app(RisolutoreTitolari::class)
        ->ordinePreferenza(DB::table('anagrafica_immobile')->where('anagrafica_id', $persona->id)->where('immobile_id', $immobile->id))
        ->value('tipologia'))->unique()->all();

    // Una sola risposta su cinque letture, ed è la riga attiva: la più recente è spenta.
    expect($letture)->toBe(['proprietario']);
});

it('ordinePreferenza(): a parità di attivo vince la data_inizio più recente; l\'id è lo spareggio stabile (non discriminabile dall\'ordine naturale, dichiarato e non provato)', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $persona = $p['attivo'];
    $base = ['anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id, 'quota' => 100.0, 'attivo' => true];

    // Tre righe tutte attive: la più recente NON è la prima inserita, così l'ordine naturale
    // darebbe una risposta diversa.
    DB::table('anagrafica_immobile')->where('anagrafica_id', $persona->id)->where('immobile_id', $immobile->id)
        ->update(['tipologia' => 'inquilino', 'data_inizio' => '2024-01-01']);
    DB::table('anagrafica_immobile')->insert($base + ['tipologia' => 'proprietario', 'data_inizio' => '2026-05-01']);
    DB::table('anagrafica_immobile')->insert($base + ['tipologia' => 'usufruttuario', 'data_inizio' => '2026-05-01']);

    $query = fn () => DB::table('anagrafica_immobile')->where('anagrafica_id', $persona->id)->where('immobile_id', $immobile->id);
    $risolutore = app(RisolutoreTitolari::class);

    expect($query()->value('tipologia'))->toBe('inquilino')
        ->and($risolutore->ordinePreferenza($query())->value('tipologia'))->toBe('proprietario')
        ->and($risolutore->ordinePreferenza($query())->pluck('tipologia')->all())->toBe(['proprietario', 'usufruttuario', 'inquilino']);
});

it('la pivot ha un modello dedicato ed espone il proprio id, così B2 potrà lavorare per periodo e non per persona', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();

    $pivot = $immobile->anagrafiche()->where('anagrafiche.id', $p['attivo']->id)->first()->pivot;
    $idAtteso = (int) DB::table('anagrafica_immobile')->where('anagrafica_id', $p['attivo']->id)->where('immobile_id', $immobile->id)->value('id');

    expect($pivot)->toBeInstanceOf(TitolaritaImmobile::class)
        ->and((int) $pivot->id)->toBe($idAtteso)
        // In B1 nessun cast: `data_inizio` resta la stringa che `ImmobileAnagraficaResource:46` e
        // `AnagraficaController:203` emettono oggi tal quale. I cast arrivano con B2, insieme alla UI.
        ->and($pivot->data_inizio)->toBe('2026-01-01')
        ->and($immobile->anagrafiche()->where('anagrafiche.id', $p['attivo']->id)->first()->pivot->anagrafica_id)->toBe($p['attivo']->id);
});

it('in B1 la pivot non ha cast nemmeno su attivo: resta l\'intero del database, com\'è emesso da ImmobileAnagraficaResource', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();

    $pivot = $immobile->anagrafiche()->where('anagrafiche.id', $p['attivo']->id)->first()->pivot;

    expect($pivot->attivo)->toBe(1)
        ->and($pivot->getCasts())->not->toHaveKey('attivo');
});

it('anche dal lato Anagrafica la pivot è lo stesso modello con l\'id', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();

    $pivot = $p['attivo']->immobili()->where('immobili.id', $immobile->id)->first()->pivot;

    expect($pivot)->toBeInstanceOf(TitolaritaImmobile::class)->and((int) $pivot->id)->toBeGreaterThan(0);
});

it('B1 fissa il percorso di scrittura della pivot con using(): con due righe per coppia updateExistingPivot ne tocca una, con valori identici non scrive, detach le toglie tutte (B2 lavora per id, decisione 13)', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $persona = $p['attivo'];
    // Seconda riga attiva della stessa coppia, con un altro ruolo: è ciò che l'importatore può
    // scrivere (idempotenza sulla tripla, `LivelloTitolarita::commit()`), non l'interfaccia.
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'inquilino', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-01-01',
    ]);
    $coppia = fn () => DB::table('anagrafica_immobile')
        ->where('anagrafica_id', $persona->id)->where('immobile_id', $immobile->id);

    // (1) Una riga sola: si asserisce il conteggio, non quale — nessun ORDER BY lo garantisce.
    expect($immobile->anagrafiche()->updateExistingPivot($persona->id, ['note' => 'X']))->toBe(1)
        ->and($coppia()->where('note', 'X')->count())->toBe(1);

    // (2) Valori identici su entrambe le righe: nessun UPDATE, updated_at fermo, ritorno 0.
    $coppia()->update(['note' => 'X', 'updated_at' => '2020-01-01 00:00:00']);
    expect($immobile->anagrafiche()->updateExistingPivot($persona->id, ['note' => 'X']))->toBe(0)
        ->and($coppia()->where('updated_at', 'like', '2020-01-01%')->count())->toBe(2);

    // (3) detach resta per persona: tutte le righe, una per id.
    expect($immobile->anagrafiche()->detach($persona->id))->toBe(2)
        ->and($coppia()->count())->toBe(0);
});

it('la strada di B2 funziona già: wherePivot(\'id\') davanti a updateExistingPivot tocca solo quella riga', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $persona = $p['attivo'];
    $seconda = DB::table('anagrafica_immobile')->insertGetId([
        'anagrafica_id' => $persona->id, 'immobile_id' => $immobile->id,
        'tipologia' => 'inquilino', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-01-01',
    ]);

    expect($immobile->anagrafiche()->wherePivot('id', $seconda)->updateExistingPivot($persona->id, ['note' => 'solo la seconda']))->toBe(1)
        ->and(DB::table('anagrafica_immobile')->where('note', 'solo la seconda')->pluck('id')->map(fn ($id) => (int) $id)->all())->toBe([(int) $seconda]);
});

it('PeriodoCompetenza: una data puntuale è un periodo lungo un giorno (D2), e i giorni si contano estremi inclusi', function () {
    $puntuale = PeriodoCompetenza::puntuale('2026-03-01');
    $anno = new PeriodoCompetenza('2026-01-01', '2026-12-31');

    expect($puntuale->giorni())->toBe(1)
        ->and($anno->giorni())->toBe(365)
        ->and($puntuale->dal->toDateString())->toBe('2026-03-01')
        ->and($puntuale->al->toDateString())->toBe('2026-03-01')
        ->and(fn () => new PeriodoCompetenza('2026-12-31', '2026-01-01'))->toThrow(InvalidArgumentException::class);
});
