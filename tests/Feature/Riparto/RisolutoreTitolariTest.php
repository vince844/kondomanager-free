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
 * ➕ B2 (1.11.0-beta.31, S4): il tempo è entrato. `attiviAlla()` e `vincolaQuery()` accettano un periodo
 * e applicano D7 — `data_fine` filtra sempre, `data_inizio` solo con un predecessore chiuso sulla stessa
 * coppia — e senza periodo la regola resta `attivo === true`, identica a B1. Il test di B1 «un periodo
 * passato non cambia la risposta» è stato rovesciato in «D7 nel risolutore» (due test, più sotto); il
 * test di `RigheRipartoScritturaTest` sul periodo esplicito è stato confermato ed esteso (con un periodo
 * e nessun cambio di titolarità le cifre sono identiche, e le righe portano periodo e gradino: D8).
 * Il riparto al centesimo resta il cancello della suite (invariante 1, `MotoreTemporaleTest`).
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

// ➕ B2 S4 (1.11.0-beta.31): il test di B1 «un periodo passato non cambia la risposta» è stato rovesciato
// nel suo opposto, come annunciato: D7 dentro `attiviAlla()` e `vincolaQuery()`.
it('D7 nel risolutore: senza periodo la regola resta attivo === true; con un periodo il titolare chiuso PRIMA del periodo è escluso e chi apre nel futuro senza predecessore resta dentro', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $risolutore = app(RisolutoreTitolari::class);
    $anagrafiche = $immobile->anagrafiche()->get();

    // Senza periodo: identico alla beta.30 (invariante 1, il cancello di B2).
    $senzaPeriodo = $risolutore->attiviAlla($anagrafiche)->pluck('id')->sort()->values()->all();
    expect($senzaPeriodo)->toBe(collect([$p['attivo']->id, $p['chiuso_passato']->id, $p['apre_in_futuro']->id])->sort()->values()->all());

    // Periodo che l'inquilino chiuso al 30/04 tocca ancora: tutti e tre dentro.
    $primoSemestre = new PeriodoCompetenza('2026-01-01', '2026-06-30');
    expect($risolutore->attiviAlla($anagrafiche, $primoSemestre)->pluck('id')->sort()->values()->all())->toBe($senzaPeriodo);

    // Periodo dopo la chiusura: l'inquilino chiuso al 30/04 è fuori; l'usufruttuario che «apre» nel 2027
    // resta dentro perché non ha un predecessore chiuso (la sua data_inizio è un censimento, D7).
    $secondoSemestre = new PeriodoCompetenza('2026-07-01', '2026-12-31');
    $attesi = collect([$p['attivo']->id, $p['apre_in_futuro']->id])->sort()->values()->all();
    expect($risolutore->attiviAlla($anagrafiche, $secondoSemestre)->pluck('id')->sort()->values()->all())->toBe($attesi);

    // Invariante 4 esteso al periodo: la query risponde come la collection.
    $suQuery = fn (PeriodoCompetenza $per) => $risolutore
        ->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $per)
        ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    expect($suQuery($primoSemestre))->toBe($senzaPeriodo)
        ->and($suQuery($secondoSemestre))->toBe($attesi);
});

it('D7 nel risolutore: con un predecessore chiuso sulla stessa coppia la data_inizio filtra — l\'acquirente dal 1° maggio non c\'è in un periodo che finisce ad aprile, e la query concorda con la collection', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $risolutore = app(RisolutoreTitolari::class);

    // Il venditore: il proprietario attivo si chiude al 30/04; l'acquirente decorre dal 01/05.
    DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id)->where('anagrafica_id', $p['attivo']->id)
        ->update(['data_fine' => '2026-04-30']);
    $acquirente = Anagrafica::forceCreate(['nome' => 'Acquirente Maggio', 'email' => 'acq-maggio@test.it', 'indirizzo' => 'Via Verdi 2', 'codice_fiscale' => 'ACQMAG0000000001']);
    DB::table('anagrafica_immobile')->insert([
        'anagrafica_id' => $acquirente->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario',
        'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-05-01', 'data_fine' => null,
    ]);
    $anagrafiche = $immobile->anagrafiche()->get();

    $gennaioAprile = new PeriodoCompetenza('2026-01-01', '2026-04-30');
    $maggioDicembre = new PeriodoCompetenza('2026-05-01', '2026-12-31');
    $ids = fn (PeriodoCompetenza $per) => $risolutore->attiviAlla($anagrafiche, $per)->pluck('id')->all();
    $suQuery = fn (PeriodoCompetenza $per) => $risolutore
        ->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $per)
        ->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

    // Gennaio–aprile: il venditore c'è, l'acquirente no (decorre dopo, e HA un predecessore chiuso).
    expect($ids($gennaioAprile))->toContain($p['attivo']->id)->not->toContain($acquirente->id);
    // Maggio–dicembre: l'acquirente c'è, il venditore no (chiuso prima del periodo).
    expect($ids($maggioDicembre))->toContain($acquirente->id)->not->toContain($p['attivo']->id);
    // Le due forme concordano su entrambi i periodi (invariante 4).
    expect(collect($ids($gennaioAprile))->sort()->values()->all())->toBe($suQuery($gennaioAprile))
        ->and(collect($ids($maggioDicembre))->sort()->values()->all())->toBe($suQuery($maggioDicembre));
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
        // B2 (S3, 1.11.0-beta.31): `data_inizio` è un Carbon con cast `date:Y-m-d`. Il JSON che
        // `toArray()` produce resta `2026-01-01`, identico a quello della beta.30 — è la condizione per
        // cui i cast potevano entrare senza toccare le pagine che leggono la pivot serializzata.
        ->and($pivot->data_inizio)->toBeInstanceOf(\Carbon\CarbonInterface::class)
        ->and($pivot->data_inizio->toDateString())->toBe('2026-01-01')
        ->and($pivot->toArray()['data_inizio'])->toBe('2026-01-01')
        ->and($pivot->toArray()['data_fine'])->toBeNull()
        ->and($immobile->anagrafiche()->where('anagrafiche.id', $p['attivo']->id)->first()->pivot->anagrafica_id)->toBe($p['attivo']->id);
});

it('B2 dà alla pivot il cast booleano su attivo: `true`, non l\'intero del database, come il tipo TS AnagraficaPivot dichiarava già', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();

    $pivot = $immobile->anagrafiche()->where('anagrafiche.id', $p['attivo']->id)->first()->pivot;

    expect($pivot->attivo)->toBeTrue()
        ->and($pivot->getCasts())->toHaveKey('attivo')
        ->and($immobile->anagrafiche()->where('anagrafiche.id', $p['spento']->id)->first()->pivot->attivo)->toBeFalse();
});

it('anche dal lato Anagrafica la pivot è lo stesso modello con l\'id', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();

    $pivot = $p['attivo']->immobili()->where('immobili.id', $immobile->id)->first()->pivot;

    expect($pivot)->toBeInstanceOf(TitolaritaImmobile::class)->and((int) $pivot->id)->toBeGreaterThan(0);
});

// ⚠️ B2 S5: DA ROVESCIARE — le rotte lavorano per id (decisione 13): updateExistingPivot per persona non è più la strada.
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

// ---------------------------------------------------------------------------------------------------
// B2, S1 — D7 come funzione pura: l'aritmetica dei giorni di titolarità. Da S4 la stessa regola vive
// anche in `attiviAlla()`/`vincolaQuery()` (i due test «D7 nel risolutore» qui sopra) ed è chiamata dal
// motore con il periodo di competenza (`CalcoloQuoteService`).
//
// D7 (progetto §4.2): `data_fine` filtra sempre; `data_inizio` filtra **solo se** sulla stessa coppia
// (immobile, tipologia) esiste una riga chiusa che la precede. Una riga senza predecessore chiuso è
// «aperta da sempre»: sui dati reali `data_inizio` è la data del censimento, non della decorrenza
// (§6.1 del progetto), e filtrarla svuoterebbe mezzo palazzo.
// ---------------------------------------------------------------------------------------------------

it('D7: data_fine filtra sempre — un inquilino chiuso al 30 aprile ha 120 giorni di titolarità in un esercizio solare, e nulla dopo', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    $riga = $righe->firstWhere('anagrafica_id', $p['chiuso_passato']->id);
    $esercizio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31');

    $giorni = app(RisolutoreTitolari::class)->giorniDiTitolarita($riga, $esercizio, $righe);

    expect($giorni)->toBe(120)
        ->and(app(RisolutoreTitolari::class)->giorniDiTitolarita($riga, new \App\Support\PeriodoCompetenza('2026-05-01', '2026-12-31'), $righe))->toBe(0);
});

it('D7: data_inizio SENZA un predecessore chiuso è inerte — la riga che «apre nel 2027» conta tutto il 2026, perché quella data è un censimento e non una decorrenza', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    $riga = $righe->firstWhere('anagrafica_id', $p['apre_in_futuro']->id); // usufruttuario, dal 2027-01-01

    expect(app(RisolutoreTitolari::class)->giorniDiTitolarita($riga, new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31'), $righe))->toBe(365);
});

it('D7: data_inizio CON un predecessore chiuso sulla stessa coppia filtra — venditore chiuso al 30 aprile e acquirente dal 1° maggio si dividono i 365 giorni senza sovrapporsi', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $venditore = $p['attivo'];
    DB::table('anagrafica_immobile')->where('anagrafica_id', $venditore->id)->where('immobile_id', $immobile->id)
        ->update(['data_inizio' => '2019-03-03', 'data_fine' => '2026-04-30']);
    $acquirente = Anagrafica::forceCreate(['nome' => 'Acquirente D7', 'email' => 'acquirente-d7-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 1', 'codice_fiscale' => 'ACQD7'.str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $acquirente->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-05-01']);

    $righe = $immobile->anagrafiche()->get()->map->pivot;
    $esercizio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31');
    $r = app(RisolutoreTitolari::class);
    $gVenditore = $r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $venditore->id), $esercizio, $righe);
    $gAcquirente = $r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $acquirente->id), $esercizio, $righe);

    expect($gVenditore)->toBe(120)->and($gAcquirente)->toBe(245)->and($gVenditore + $gAcquirente)->toBe(365);
});

it('decisione 23 (S8-9) — D7 stretto: un predecessore chiuso il GIORNO PRIMA è un predecessore (venditore al 30/6, acquirente dal 1/7 → 181/184); chiuso il giorno stesso non lo è più (comproprietà di un giorno, non un passaggio: l\'invariante 11 rifiuta il 200), e la forma SQL concorda', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $venditore = $p['attivo'];
    DB::table('anagrafica_immobile')->where('anagrafica_id', $venditore->id)->where('immobile_id', $immobile->id)
        ->update(['data_inizio' => '2019-03-03', 'data_fine' => '2026-06-30']);
    $acquirente = Anagrafica::forceCreate(['nome' => 'Acquirente Giorno Dopo', 'email' => 'acq-gd-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => 'ACQGD'.str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    $rigaAcq = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $acquirente->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-07-01']);

    $esercizio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31');
    $gennaioMaggio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-05-31');
    $r = app(RisolutoreTitolari::class);
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    expect($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $venditore->id), $esercizio, $righe))->toBe(181)
        ->and($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $acquirente->id), $esercizio, $righe))->toBe(184)
        ->and($r->attiviAlla($immobile->anagrafiche()->get(), $gennaioMaggio)->pluck('id')->all())->not->toContain($acquirente->id)
        ->and($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $gennaioMaggio)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->not->toContain($acquirente->id);

    // Chiuso il giorno stesso (30/6 e 30/6): niente predecessore, la data_inizio è un censimento — 365 giorni, per entrambe le vie.
    DB::table('anagrafica_immobile')->where('id', $rigaAcq)->update(['data_inizio' => '2026-06-30']);
    $r = app(RisolutoreTitolari::class);
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    expect($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $acquirente->id), $esercizio, $righe))->toBe(365)
        ->and($r->attiviAlla($immobile->anagrafiche()->get(), $gennaioMaggio)->pluck('id')->all())->toContain($acquirente->id)
        ->and($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $gennaioMaggio)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->toContain($acquirente->id);
});

it('decisione 23 (S8-9) — la riga entrata con un passaggio registrato (subentri.riga_entrante_id) ha un predecessore anche senza contiguità: il nuovo inquilino dal 1/6 dopo un vuoto (il precedente chiuso al 28/2) conta 214 giorni, non 365; la forma SQL concorda', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $inquilinoA = Anagrafica::forceCreate(['nome' => 'Inquilino A', 'email' => 'inqa-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => 'INQAA'.str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    $inquilinoB = Anagrafica::forceCreate(['nome' => 'Inquilino B', 'email' => 'inqb-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => 'INQBB'.str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $inquilinoA->id, 'immobile_id' => $immobile->id, 'tipologia' => 'inquilino', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => '2026-02-28']);
    $rigaB = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $inquilinoB->id, 'immobile_id' => $immobile->id, 'tipologia' => 'inquilino', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-06-01']);
    $esercizio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31');
    $gennaioMaggio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-05-31');

    // Senza passaggio registrato e senza contiguità: la data_inizio di B è un censimento → 365.
    $r = app(RisolutoreTitolari::class);
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    expect($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $inquilinoB->id), $esercizio, $righe))->toBe(365);

    // Con «Inizio locazione» registrato: B è `riga_entrante_id` → la sua data_inizio conta → 214.
    DB::table('subentri')->insert(['condominio_id' => $immobile->condominio_id, 'immobile_id' => $immobile->id, 'anagrafica_uscente_id' => null, 'anagrafica_entrante_id' => $inquilinoB->id, 'riga_uscente_id' => null, 'riga_entrante_id' => $rigaB, 'tipologia' => 'inquilino', 'tipo_passaggio' => 'inizio_locazione', 'decorrenza' => '2026-06-01', 'created_at' => now(), 'updated_at' => now()]);
    $r = app(RisolutoreTitolari::class);
    expect($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $inquilinoB->id), $esercizio, $righe))->toBe(214)
        ->and($r->attiviAlla($immobile->anagrafiche()->get(), $gennaioMaggio)->pluck('id')->all())->not->toContain($inquilinoB->id)
        ->and($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $gennaioMaggio)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->not->toContain($inquilinoB->id);
});

it('strada (b), B1-2 — la tripla (unità, tipologia, decorrenza) vale solo per l\'usufrutto: il coinquilino censito a mano lo stesso giorno dell\'«Inizio locazione» di un altro (dopo un vuoto) non è «entrato con quel passaggio» e tiene i suoi 365 giorni; la forma SQL concorda', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();
    $mk = fn (string $nome) => Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => strtoupper(substr($nome, 0, 5)).str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    [$i1, $i2, $i3] = [$mk('Primo'), $mk('Secondo'), $mk('Censito')];
    // I1 chiude il 28/2; dopo tre mesi di vuoto I2 entra il 1/6 con «Inizio locazione» registrato (riga_entrante_id esatto).
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $i1->id, 'immobile_id' => $immobile->id, 'tipologia' => 'inquilino', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2020-01-01', 'data_fine' => '2026-02-28']);
    $rigaI2 = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $i2->id, 'immobile_id' => $immobile->id, 'tipologia' => 'inquilino', 'quota' => 50.0, 'attivo' => true, 'data_inizio' => '2026-06-01']);
    // I3 è censito a mano il 1/6, stessa data e stessa tipologia, senza essere parte di quel passaggio (nessuna contiguità con I1).
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $i3->id, 'immobile_id' => $immobile->id, 'tipologia' => 'inquilino', 'quota' => 50.0, 'attivo' => true, 'data_inizio' => '2026-06-01']);
    DB::table('subentri')->insert(['condominio_id' => $immobile->condominio_id, 'immobile_id' => $immobile->id, 'anagrafica_uscente_id' => null, 'anagrafica_entrante_id' => $i2->id, 'riga_uscente_id' => null, 'riga_entrante_id' => $rigaI2, 'tipologia' => 'inquilino', 'tipo_passaggio' => 'inizio_locazione', 'decorrenza' => '2026-06-01', 'created_at' => now(), 'updated_at' => now()]);

    $esercizio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31');
    $gennaioMaggio = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-05-31');
    $r = app(RisolutoreTitolari::class);
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    // I2 è `riga_entrante_id`: 214 giorni. I3 non ha un passaggio suo: 365 (censimento, D7 stretto) — la tripla non lo prende.
    expect($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $i2->id), $esercizio, $righe))->toBe(214)
        ->and($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $i3->id), $esercizio, $righe))->toBe(365);
    // Su gennaio–maggio I3 è dentro e I2 è fuori: collection e SQL concordano.
    $collection = $r->attiviAlla($immobile->anagrafiche()->get(), $gennaioMaggio)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    $sql = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $gennaioMaggio)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    expect($collection)->toBe($sql)->and($collection)->toContain($i3->id, $i1->id)->not->toContain($i2->id);

    // Lo stesso record come «usufrutto» (l'estinzione apre più righe con un record solo): la tripla vale, e I3 è entrato il 1/6.
    DB::table('subentri')->where('immobile_id', $immobile->id)->update(['tipo_passaggio' => 'usufrutto']);
    $r = app(RisolutoreTitolari::class);
    expect($r->giorniDiTitolarita($righe->firstWhere('anagrafica_id', $i3->id), $esercizio, $righe))->toBe(214)
        ->and($r->attiviAlla($immobile->anagrafiche()->get(), $gennaioMaggio)->pluck('id')->all())->not->toContain($i3->id)
        ->and($r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $gennaioMaggio)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all())->not->toContain($i3->id);
});

it('strada (b), B1-1 — invariante 4 con le date salvate CON L\'ORA (come le scrive un Carbon intero su sqlite): predecessore chiuso il giorno prima e tripla dell\'usufrutto, la forma SQL risponde come la collection', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();
    $mk = fn (string $nome) => Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => strtoupper(substr($nome, 0, 5)).str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    [$a, $b, $n1, $n2] = [$mk('Prima'), $mk('Dopo'), $mk('Nudouno'), $mk('Nudodue')];
    // Vendita contigua con l'ora: A fino al 30/4, B dal 1/5.
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $a->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2015-01-01 00:00:00', 'data_fine' => '2026-04-30 00:00:00']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $b->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 100.0, 'attivo' => true, 'data_inizio' => '2026-05-01 00:00:00']);
    // Estinzione dell'usufrutto il 1/6: due nudi tornano pieni con un record solo (tripla), le righe con l'ora e la decorrenza senza.
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $n1->id, 'immobile_id' => $immobile->id, 'tipologia' => 'nudo_proprietario', 'quota' => 60.0, 'attivo' => true, 'data_inizio' => '2026-06-01 00:00:00']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $n2->id, 'immobile_id' => $immobile->id, 'tipologia' => 'nudo_proprietario', 'quota' => 40.0, 'attivo' => true, 'data_inizio' => '2026-06-01 00:00:00']);
    DB::table('subentri')->insert(['condominio_id' => $immobile->condominio_id, 'immobile_id' => $immobile->id, 'anagrafica_uscente_id' => null, 'anagrafica_entrante_id' => $n1->id, 'riga_uscente_id' => null, 'riga_entrante_id' => null, 'tipologia' => 'nudo_proprietario', 'tipo_passaggio' => 'usufrutto', 'decorrenza' => '2026-06-01', 'created_at' => now(), 'updated_at' => now()]);

    $r = app(RisolutoreTitolari::class);
    foreach ([new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-30'), new \App\Support\PeriodoCompetenza('2026-01-01', '2026-05-31'), new \App\Support\PeriodoCompetenza('2026-05-01', '2026-12-31')] as $periodo) {
        $collection = $r->attiviAlla($immobile->anagrafiche()->get(), $periodo)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $sql = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $periodo)->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        expect($sql)->toBe($collection, 'periodo '.$periodo->dal->toDateString().'–'.$periodo->al->toDateString());
    }
    // E il merito: fino al 30/4 B è fuori (predecessore A chiuso il giorno prima) e i nudi sono fuori (entrati con la tripla il 1/6).
    $ids = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-30'))->pluck('anagrafica_id')->map(fn ($id) => (int) $id)->all();
    // Un `not->toContain` per id: con più argomenti passava se ne mancava anche uno solo (Fase 1-bis della .47).
    expect($ids)->toContain($a->id)->not->toContain($b->id)->not->toContain($n1->id)->not->toContain($n2->id);
});

it('rilievo D1 della Fase 1-bis della beta.41 — la costituzione su una quota apre anche la nuda proprietà di chi costituisce: quella riga è entrata con il passaggio, e la forma SQL risponde come la collection', function () {
    ['immobile' => $immobile] = unitaConQuattroTitolari();
    $mk = fn (string $nome) => Anagrafica::forceCreate(['nome' => $nome, 'email' => strtolower($nome).'-'.$immobile->id.'@test.it', 'indirizzo' => 'Via Verdi 3', 'codice_fiscale' => strtoupper(substr($nome, 0, 5)).str_pad((string) $immobile->id, 11, '0', STR_PAD_LEFT)]);
    [$ugo, $bice, $elsa] = [$mk('Costituente'), $mk('Comproprietaria'), $mk('Usufruttuaria')];
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $ugo->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 50.0, 'attivo' => true, 'data_inizio' => '2015-01-01', 'data_fine' => '2026-04-30']);
    DB::table('anagrafica_immobile')->insert(['anagrafica_id' => $bice->id, 'immobile_id' => $immobile->id, 'tipologia' => 'proprietario', 'quota' => 50.0, 'attivo' => true, 'data_inizio' => '2015-01-01']);
    $nuda = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $ugo->id, 'immobile_id' => $immobile->id, 'tipologia' => 'nuda_proprietario', 'quota' => 50.0, 'attivo' => true, 'data_inizio' => '2026-05-01']);
    $usufrutto = DB::table('anagrafica_immobile')->insertGetId(['anagrafica_id' => $elsa->id, 'immobile_id' => $immobile->id, 'tipologia' => 'usufruttuario', 'quota' => 50.0, 'attivo' => true, 'data_inizio' => '2026-05-01']);
    // Il record della costituzione: l'entrante è la riga dell'usufruttuaria, non quella della nuda proprietà.
    DB::table('subentri')->insert(['condominio_id' => $immobile->condominio_id, 'immobile_id' => $immobile->id, 'anagrafica_uscente_id' => $ugo->id, 'anagrafica_entrante_id' => $elsa->id, 'riga_uscente_id' => null, 'riga_entrante_id' => $usufrutto, 'tipologia' => 'usufruttuario', 'tipo_passaggio' => 'usufrutto', 'decorrenza' => '2026-05-01', 'created_at' => now(), 'updated_at' => now()]);

    $r = app(RisolutoreTitolari::class);
    foreach ([new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-30'), new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31'), new \App\Support\PeriodoCompetenza('2026-05-01', '2026-12-31')] as $periodo) {
        $collection = $r->attiviAlla($immobile->anagrafiche()->get(), $periodo)->map(fn ($a) => (int) $a->pivot->id)->sort()->values()->all();
        $sql = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), $periodo)->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        expect($sql)->toBe($collection, 'periodo '.$periodo->dal->toDateString().'–'.$periodo->al->toDateString());
    }
    // Il merito: fino al 30/4 la nuda proprietà di Ugo non c'è ancora (prima del rilievo valeva «da sempre»).
    $fino = $r->vincolaQuery(DB::table('anagrafica_immobile')->where('immobile_id', $immobile->id), new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-30'))->pluck('id')->map(fn ($id) => (int) $id)->all();
    expect($fino)->not->toContain($nuda)->not->toContain($usufrutto);
});

it('D7: il predecessore conta solo sulla STESSA tipologia — un inquilino chiuso non rende decorrenza la data_inizio del proprietario', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    // `chiuso_passato` è un inquilino chiuso al 30/04; `apre_in_futuro` è un usufruttuario: coppie diverse.
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    $riga = $righe->firstWhere('anagrafica_id', $p['apre_in_futuro']->id);

    expect(app(RisolutoreTitolari::class)->giorniDiTitolarita($riga, new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31'), $righe))->toBe(365);
});

it('D7 su un insieme di tratti: i giorni si sommano tratto per tratto — il venditore del 30 aprile ha tutto il primo tratto della stagione di riscaldamento e nulla del secondo', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    $riga = $righe->firstWhere('anagrafica_id', $p['chiuso_passato']->id); // chiuso al 30/04
    $stagione = new \App\Support\InsiemePeriodi(new \App\Support\PeriodoCompetenza('2026-01-01', '2026-04-15'), new \App\Support\PeriodoCompetenza('2026-10-15', '2026-12-31'));

    expect(app(RisolutoreTitolari::class)->giorniDiTitolarita($riga, $stagione, $righe))->toBe(105);
});

it('D8, la domanda che decide l\'uscita anticipata: cambiaTitolaritaNelPeriodo() è falsa quando nessuna riga della coppia si chiude o decorre dentro il periodo, vera altrimenti', function () {
    ['immobile' => $immobile, 'persone' => $p] = unitaConQuattroTitolari();
    $righe = $immobile->anagrafiche()->get()->map->pivot;
    $r = app(RisolutoreTitolari::class);
    $anno = new \App\Support\PeriodoCompetenza('2026-01-01', '2026-12-31');

    // Proprietari: una riga attiva dal 2026-01-01 senza fine, una spenta — nessun cambio nel 2026.
    expect($r->cambiaTitolaritaNelPeriodo($righe->where('tipologia', 'proprietario'), $anno))->toBeFalse()
        // Inquilini: la riga chiusa al 30/04/2026 cade dentro l'anno → cambio.
        ->and($r->cambiaTitolaritaNelPeriodo($righe->where('tipologia', 'inquilino'), $anno))->toBeTrue()
        // Stesso inquilino guardato dal 2027: la chiusura è fuori dal periodo → nessun cambio.
        ->and($r->cambiaTitolaritaNelPeriodo($righe->where('tipologia', 'inquilino'), new \App\Support\PeriodoCompetenza('2027-01-01', '2027-12-31')))->toBeFalse();
});
